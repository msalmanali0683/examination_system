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
     * Regenerates seating for every time slot in the session, one slot at a
     * time. Already-locked seats (manual drag-drop overrides from the
     * review step) are left untouched and treated as occupied obstacles for
     * everyone else; only unlocked seats are recomputed.
     */
    public function generate(ExamSession $session): SeatingResult
    {
        $warnings = collect();

        foreach ($this->allocatePerSlot($session, $this->activeSessionRoomPool($session)) as [$slot, $result]) {
            $this->persist($session, $slot, $result);
            $warnings = $warnings->merge($result->warnings);
        }

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
     * Simulates seating a hypothetical slot containing exactly these
     * subjects (every enrolled section of each, combined) — with no real
     * TimeSlot behind it, so there's nothing locked to treat as an
     * obstacle. Always allocates with Strict placement (one room per
     * subject, never shared) since the question this answers is "if
     * these subjects were examined at the same time, each in its own
     * room(s), how much room and teacher capacity would that take" — not
     * a seating-strategy choice. Used by the Check Capacity slot
     * simulator, which works directly from enrollment data before any
     * real timetable exists.
     *
     * @param  int[]  $subjectIds
     * @return array{result: SeatingResult, roomsUsed: int}
     */
    public function previewForSubjects(ExamSession $session, array $subjectIds): array
    {
        $enrollments = Enrollment::whereIn('enrollments.subject_id', $subjectIds)
            ->where('enrollments.exam_session_id', $session->id)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->orderBy('students.roll_no')
            ->select('enrollments.id', 'enrollments.subject_id', 'enrollments.section')
            ->get();

        if ($enrollments->isEmpty()) {
            return ['result' => new SeatingResult([], collect()), 'roomsUsed' => 0];
        }

        $rooms = $this->allRoomsPool()->map(fn ($r) => [...$r, 'occupied' => []])->sortByDesc('capacity')->values()->all();

        $result = (new StrictSeatingStrategy)->allocate($enrollments, $rooms);

        return [
            'result' => $result,
            'roomsUsed' => collect($result->placements)->pluck('roomId')->unique()->count(),
        ];
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

    private function persist(ExamSession $session, TimeSlot $slot, SeatingResult $result): void
    {
        $lockedEnrollmentIds = SeatAssignment::where('exam_session_id', $session->id)
            ->where('time_slot_id', $slot->id)
            ->where('is_locked', true)
            ->pluck('enrollment_id');

        DB::transaction(function () use ($session, $slot, $lockedEnrollmentIds, $result) {
            SeatAssignment::where('exam_session_id', $session->id)
                ->where('time_slot_id', $slot->id)
                ->whereNotIn('enrollment_id', $lockedEnrollmentIds)
                ->delete();

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
        });
    }

    private function strategyFor(string $seatingStrategy, int $mixedSubjectsPerRoom = 2): SeatingStrategy
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
