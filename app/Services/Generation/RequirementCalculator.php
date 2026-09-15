<?php

namespace App\Services\Generation;

use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Services\Generation\DTOs\SlotRequirement;
use App\Services\Generation\Strategies\SeatingStrategy;
use Illuminate\Support\Collection;

/**
 * Answers "do we have enough rooms and teachers for every slot" before the
 * admin commits to generating seating/duties — computed from real data
 * (each room's actual capacity, each teacher's actual availability), not a
 * manual estimate.
 */
class RequirementCalculator
{
    public function __construct(private SeatAllocationService $seatAllocationService = new SeatAllocationService)
    {
    }

    /**
     * $strategyOverride simulates a different seating strategy than the
     * one saved on the session — e.g. "what if I combined N subjects per
     * room?" — for the what-if Capacity Check, without changing the
     * session's real settings.
     *
     * @return Collection<int, SlotRequirement>
     */
    public function calculate(ExamSession $session, ?SeatingStrategy $strategyOverride = null): Collection
    {
        $activePreview = $this->seatAllocationService->preview($session, $strategyOverride)->keyBy(fn ($p) => $p['slot']->id);
        $allRoomsPreview = $this->seatAllocationService->previewAgainstAllRooms($session, $strategyOverride)->keyBy(fn ($p) => $p['slot']->id);

        $activeSessionRooms = $session->sessionRooms()->where('is_active', true)->with('room')->get();
        $roomsAvailable = $activeSessionRooms->count();
        $seatsAvailable = $activeSessionRooms->sum(fn ($sr) => $sr->effectiveCapacity());

        // Every active teacher is available by default; a constraint row
        // only exists where the admin explicitly excluded them or marked
        // specific days unavailable (see TeacherConstraints::apply()).
        $activeTeacherCount = Teacher::where('is_active', true)->count();
        $constraints = SessionTeacherConstraint::where('exam_session_id', $session->id)->get();
        $excludedCount = $constraints->where('is_excluded', true)->count();
        $constrainedNotExcluded = $constraints->where('is_excluded', false);

        // A slot can have plenty of room/teacher capacity and still not be
        // ready: the real timetable generator records a conflict_note on a
        // subject when it couldn't find a clash-free day for it and placed
        // it anyway (see TimetableGenerator's capacity-shortfall/clash
        // conflicts). Capacity Check must surface that too, or it can show
        // "Ready" for a slot that Pin Subjects to Slots is warning about.
        $slotsWithUnresolvedClash = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('time_slot_id')
            ->whereNotNull('conflict_note')
            ->pluck('time_slot_id')
            ->unique();

        return $activePreview->map(function ($active) use ($allRoomsPreview, $roomsAvailable, $seatsAvailable, $activeTeacherCount, $excludedCount, $constrainedNotExcluded, $slotsWithUnresolvedClash, $session) {
            $slot = $active['slot'];
            $unseated = $active['result']->warnings->where('type', 'unseated');
            $studentCount = collect($active['result']->placements)->count() + $unseated->count();

            $roomsNeeded = $allRoomsPreview->get($slot->id)['roomsUsed'] ?? $active['roomsUsed'];

            // previewAgainstAllRooms() picks whichever rooms in the whole
            // system fit this slot most efficiently, which aren't
            // necessarily the same rooms actually active for this session —
            // so its count can understate the real need (e.g. it counts
            // the two biggest rooms system-wide, while the session's own
            // two active rooms are smaller and still leave students
            // unseated). Never let the displayed numbers claim "0
            // shortfall" while the real, active-room simulation says
            // otherwise.
            if ($unseated->isNotEmpty() && $roomsNeeded <= $roomsAvailable) {
                $roomsNeeded = $roomsAvailable + 1;
            }

            $unavailableThisDay = $constrainedNotExcluded->filter(fn ($c) => ! $c->isAvailableOn($slot->date))->count();
            $teachersAvailable = $activeTeacherCount - $excludedCount - $unavailableThisDay;

            return new SlotRequirement(
                timeSlotId: $slot->id,
                label: $slot->date->format('d M Y').' '.substr($slot->start_time, 0, 5),
                studentCount: $studentCount,
                roomsNeeded: $roomsNeeded,
                roomsAvailable: $roomsAvailable,
                teachersNeeded: $roomsNeeded * $session->invigilators_per_room,
                teachersAvailable: max(0, $teachersAvailable),
                hasUnseatedStudents: $unseated->isNotEmpty(),
                seatsAvailable: $seatsAvailable,
                hasUnresolvedClash: $slotsWithUnresolvedClash->contains($slot->id),
            );
        })->values();
    }

    public function isFullyMet(ExamSession $session): bool
    {
        return $this->calculate($session)->every(fn (SlotRequirement $r) => $r->isMet());
    }
}
