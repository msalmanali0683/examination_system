<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\Strategies\CombineSectionsSeatingStrategy;
use App\Services\Generation\Strategies\GroupedWithOverflowStrategy;
use App\Services\Generation\Strategies\MixedSeatingStrategy;
use App\Services\Generation\Strategies\SeatingStrategy;
use App\Services\Generation\Strategies\StrictSeatingStrategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeatAllocationService
{
    /**
     * Regenerates seating for every time slot in the session. Already-
     * locked seats (manual drag-drop overrides from the review step) are
     * left untouched and treated as occupied obstacles for everyone else;
     * only unlocked seats are recomputed.
     *
     * Every slot is allocated first (read-only, no writes), then every
     * unlocked seat assignment for the *whole session* is cleared and the
     * fresh set inserted in one transaction — not per slot. A subject can
     * land on a different slot than last time (e.g. after regenerating
     * the timetable), and seat_assignments.enrollment_id is unique across
     * the entire session; clearing only the current slot's old rows would
     * leave a stale row from the subject's previous slot in place and
     * collide with the new insert.
     */
    public function generate(ExamSession $session): SeatingResult
    {
        $pairs = $this->allocatePerSlot($session, $this->activeSessionRoomPool($session));
        $warnings = collect();

        DB::transaction(function () use ($session, $pairs, &$warnings) {
            SeatAssignment::where('exam_session_id', $session->id)
                ->where('is_locked', false)
                ->delete();

            foreach ($pairs as [$slot, $result]) {
                foreach ($result->placements as $placement) {
                    SeatAssignment::create([
                        'exam_session_id' => $session->id,
                        'enrollment_id' => $placement->enrollmentId,
                        'time_slot_id' => $slot->id,
                        'room_id' => $placement->roomId,
                        'row_number' => $placement->row,
                        'column_number' => $placement->column,
                        'is_locked' => false,
                    ]);
                }

                $warnings = $warnings->merge($result->warnings);
            }
        });

        return new SeatingResult([], $warnings);
    }

    /**
     * Computes what seating *would* look like for every slot without
     * writing anything to the database — used by the capacity/requirement
     * check so the admin can see whether enough rooms exist before
     * committing to a real generation run.
     *
     * $strategyOverride simulates a different strategy than the one saved
     * on the session (e.g. "what if I combined N subjects per room?") —
     * used by the what-if Capacity Check, without touching the session.
     *
     * @return Collection<int, array{slot: TimeSlot, result: SeatingResult, roomsUsed: int}>
     */
    public function preview(ExamSession $session, ?SeatingStrategy $strategyOverride = null): Collection
    {
        return $this->allocatePerSlot($session, $this->activeSessionRoomPool($session), $strategyOverride)->map(fn ($pair) => [
            'slot' => $pair[0],
            'result' => $pair[1],
            'roomsUsed' => collect($pair[1]->placements)->pluck('roomId')->unique()->count(),
        ]);
    }

    /**
     * Same as preview(), but allocates against every room defined in the
     * system (active or not, in this session or not) rather than just the
     * session's active rooms. Used to answer "how many rooms would this
     * slot truly need" independent of what's currently activated — the
     * basis for the capacity/requirement check's shortfall numbers.
     *
     * @return Collection<int, array{slot: TimeSlot, result: SeatingResult, roomsUsed: int}>
     */
    public function previewAgainstAllRooms(ExamSession $session, ?SeatingStrategy $strategyOverride = null): Collection
    {
        return $this->allocatePerSlot($session, $this->allRoomsPool(), $strategyOverride)->map(fn ($pair) => [
            'slot' => $pair[0],
            'result' => $pair[1],
            'roomsUsed' => collect($pair[1]->placements)->pluck('roomId')->unique()->count(),
        ]);
    }

    /**
     * The number of rooms this slot would truly need to seat everyone,
     * even beyond however many rooms actually exist in the system today
     * — used when previewAgainstAllRooms() still leaves students unseated
     * with every real room, so the capacity check can report an honest
     * shortfall instead of a flat "one more room" guess. Simulated by
     * cloning the pool's largest room profile as many times as it takes
     * to seat everyone; nothing about these virtual rooms is persisted.
     */
    public function trueRoomsNeededForSlot(ExamSession $session, TimeSlot $slot, ?SeatingStrategy $strategyOverride = null): int
    {
        $strategy = $strategyOverride ?? $this->strategyFor($session->seating_strategy, $session->mixed_subjects_per_room);

        $subjectIds = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->where('time_slot_id', $slot->id)
            ->pluck('subject_id')
            ->all();

        if (empty($subjectIds)) {
            return 0;
        }

        $pool = $this->allRoomsPool();
        $largest = $pool->sortByDesc('capacity')->first();

        if ($largest === null) {
            return 0;
        }

        $result = $this->allocateForSlot($session, $slot, $subjectIds, $pool, $strategy);
        $unseated = collect($result->warnings)->where('type', 'unseated')->count();

        // Jump straight to a close estimate instead of growing one room
        // at a time from scratch, then let the loop below correct for
        // any bin-packing waste the estimate didn't account for.
        $virtualRoomsAdded = (int) ceil($unseated / $largest['capacity']);

        for ($i = 0; $i < $virtualRoomsAdded; $i++) {
            $pool->push(['room_id' => -1 - $i, 'rows' => $largest['rows'], 'columns' => $largest['columns'], 'capacity' => $largest['capacity']]);
        }

        $result = $this->allocateForSlot($session, $slot, $subjectIds, $pool, $strategy);
        $safety = 0;

        while (collect($result->warnings)->where('type', 'unseated')->isNotEmpty() && $safety < 50) {
            $virtualRoomsAdded++;
            $pool->push(['room_id' => -1 - $virtualRoomsAdded, 'rows' => $largest['rows'], 'columns' => $largest['columns'], 'capacity' => $largest['capacity']]);
            $result = $this->allocateForSlot($session, $slot, $subjectIds, $pool, $strategy);
            $safety++;
        }

        return collect($result->placements)->pluck('roomId')->unique()->count();
    }

    /**
     * Every room the system has, regardless of whether it's activated for
     * any particular session — the ceiling used to tell "you have more
     * rooms to activate" apart from "no more rooms exist anywhere".
     */
    public function totalSystemRoomsCount(): int
    {
        return $this->allRoomsPool()->count();
    }

    /**
     * @return Collection<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>
     */
    private function activeSessionRoomPool(ExamSession $session): Collection
    {
        return $session->sessionRooms()->where('is_active', true)->with('room')->get()->map(fn ($sr) => [
            'room_id' => $sr->room_id,
            'rows' => $sr->room->rows,
            'columns' => $sr->room->columns,
            'capacity' => $sr->effectiveCapacity(),
        ]);
    }

    /**
     * @return Collection<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>
     */
    private function allRoomsPool(): Collection
    {
        return Room::where('is_active', true)->get()->map(fn (Room $room) => [
            'room_id' => $room->id,
            'rows' => $room->rows,
            'columns' => $room->columns,
            'capacity' => $room->capacity,
        ]);
    }

    /**
     * @param  Collection<int, array{room_id: int, rows: int, columns: int, capacity: int}>  $roomPool
     * @return Collection<int, array{0: TimeSlot, 1: SeatingResult}>
     */
    private function allocatePerSlot(ExamSession $session, Collection $roomPool, ?SeatingStrategy $strategyOverride = null): Collection
    {
        $strategy = $strategyOverride ?? $this->strategyFor($session->seating_strategy, $session->mixed_subjects_per_room);

        $subjectToSlot = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('time_slot_id')
            ->pluck('time_slot_id', 'subject_id');

        $pairs = collect();

        foreach ($session->timeSlots as $slot) {
            $subjectIds = $subjectToSlot->filter(fn ($slotId) => $slotId === $slot->id)->keys()->all();

            if (empty($subjectIds)) {
                continue;
            }

            $pairs->push([$slot, $this->allocateForSlot($session, $slot, $subjectIds, $roomPool, $strategy)]);
        }

        return $pairs;
    }

    /**
     * @param  int[]  $subjectIds
     * @param  Collection<int, array{room_id: int, rows: int, columns: int, capacity: int}>  $roomPool
     */
    private function allocateForSlot(ExamSession $session, TimeSlot $slot, array $subjectIds, Collection $roomPool, SeatingStrategy $strategy): SeatingResult
    {
        $lockedAssignments = SeatAssignment::where('exam_session_id', $session->id)
            ->where('time_slot_id', $slot->id)
            ->where('is_locked', true)
            ->with('enrollment')
            ->get();

        $lockedEnrollmentIds = $lockedAssignments->pluck('enrollment_id');

        $enrollments = Enrollment::whereIn('enrollments.subject_id', $subjectIds)
            ->where('enrollments.exam_session_id', $session->id)
            ->whereNotIn('enrollments.id', $lockedEnrollmentIds)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->orderBy('students.roll_no')
            ->select('enrollments.id', 'enrollments.subject_id', 'enrollments.section')
            ->get();

        if ($enrollments->isEmpty()) {
            return new SeatingResult([], collect());
        }

        $occupiedByRoom = $lockedAssignments->groupBy('room_id')->map(
            fn ($seats) => $seats->map(fn ($s) => [
                'row' => $s->row_number,
                'column' => $s->column_number,
                'subject_id' => $s->enrollment->subject_id,
            ])->all()
        );

        $rooms = $roomPool->map(fn ($r) => [
            ...$r,
            'occupied' => $occupiedByRoom->get($r['room_id'], []),
        ])->sortByDesc('capacity')->values()->all();

        return $strategy->allocate($enrollments, $rooms);
    }

    /**
     * Exposed publicly (not just used internally) so anything that needs
     * to simulate "would this fit" — e.g. the room-capacity check during
     * timetable generation — uses the exact same strategy the session is
     * actually configured with, rather than assuming a plain one-room-
     * per-subject model that's more conservative than what's really
     * going to happen at the real seating step.
     */
    public function strategyFor(string $seatingStrategy, int $mixedSubjectsPerRoom = 2): SeatingStrategy
    {
        return match ($seatingStrategy) {
            'combine_sections' => new CombineSectionsSeatingStrategy,
            'combine_sections_overflow_subject' => new GroupedWithOverflowStrategy(groupBy: 'subject', overflowSource: 'other_subject'),
            'strict_overflow_section' => new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'same_subject'),
            'strict_overflow_subject' => new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'other_subject'),
            'mixed' => new MixedSeatingStrategy($mixedSubjectsPerRoom),
            default => new StrictSeatingStrategy,
        };
    }
}
