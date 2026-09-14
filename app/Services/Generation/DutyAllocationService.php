<?php

namespace App\Services\Generation;

use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\SessionTeacherConstraint;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Services\Generation\DTOs\DutyPlacement;
use App\Services\Generation\DTOs\DutyResult;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates duty (invigilator) assignment for a session: loads
 * real data from the database, hands it to the pure fairness/rebalance
 * algorithms, then persists the result. Already-locked duties (manual
 * overrides from the review step) are left untouched and treated as
 * obstacles for everyone else — the same pattern SeatAllocationService
 * uses for locked seats.
 */
class DutyAllocationService
{
    public function __construct(
        private DutyFairnessService $fairnessService = new DutyFairnessService,
        private DutyRebalancer $rebalancer = new DutyRebalancer,
    ) {
    }

    public function generate(ExamSession $session): DutyResult
    {
        [$slots, $lockedPlacements] = $this->buildSlots($session);

        if (empty($slots)) {
            return new DutyResult([], collect());
        }

        $teachers = $this->eligibleTeachers($session);

        $result = $this->fairnessService->generate($slots, $teachers, $lockedPlacements, $session->invigilators_per_room);
        $result = $this->rebalancer->rebalance($result, $teachers, $slots, $lockedPlacements);

        $this->persist($session, $result);

        return $result;
    }

    /**
     * @return array{0: array<int, array{id: int, roomIds: int[], unavailableTeacherIds: int[]}>, 1: DutyPlacement[]}
     */
    private function buildSlots(ExamSession $session): array
    {
        // Which rooms are actually in use each slot comes straight from the
        // already-generated seating — that's the ground truth for how many
        // invigilators a slot needs, not a re-derived estimate.
        $roomsBySlot = SeatAssignment::where('exam_session_id', $session->id)
            ->select('time_slot_id', 'room_id')
            ->distinct()
            ->get()
            ->groupBy('time_slot_id')
            ->map(fn ($rows) => $rows->pluck('room_id')->all());

        $subjectExclusionTeacherIds = $this->subjectExclusionTeacherIdsBySlot($session);

        $constraints = SessionTeacherConstraint::where('exam_session_id', $session->id)->get()->keyBy('teacher_id');

        $slots = [];

        foreach ($session->timeSlots()->orderBy('date')->orderBy('start_time')->get() as $slot) {
            $roomIds = $roomsBySlot->get($slot->id, []);

            if (empty($roomIds)) {
                continue;
            }

            $dayUnavailable = $constraints->filter(fn ($c) => ! $c->isAvailableOn($slot->date))->keys()->all();
            $subjectExcluded = $subjectExclusionTeacherIds[$slot->id] ?? [];

            $slots[] = [
                'id' => $slot->id,
                'roomIds' => $roomIds,
                'unavailableTeacherIds' => array_values(array_unique(array_merge($dayUnavailable, $subjectExcluded))),
            ];
        }

        $lockedPlacements = DutyAssignment::where('exam_session_id', $session->id)
            ->where('is_locked', true)
            ->get()
            ->map(fn (DutyAssignment $d) => new DutyPlacement($d->teacher_id, $d->time_slot_id, $d->room_id))
            ->all();

        return [$slots, $lockedPlacements];
    }

    /**
     * @return array<int, int[]> time_slot_id => teacher ids who teach a subject scheduled that slot
     */
    private function subjectExclusionTeacherIdsBySlot(ExamSession $session): array
    {
        if (! $session->teacher_subject_exclusion) {
            return [];
        }

        $subjectsBySlot = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('time_slot_id')
            ->get()
            ->groupBy('time_slot_id')
            ->map(fn ($rows) => $rows->pluck('subject_id')->all());

        $teacherIdsBySubject = Enrollment::where('exam_session_id', $session->id)
            ->whereNotNull('teacher_id')
            ->select('subject_id', 'teacher_id')
            ->distinct()
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->pluck('teacher_id')->unique()->all());

        $result = [];

        foreach ($subjectsBySlot as $slotId => $subjectIds) {
            $ids = [];

            foreach ($subjectIds as $subjectId) {
                $ids = array_merge($ids, $teacherIdsBySubject->get($subjectId, []));
            }

            $result[$slotId] = array_unique($ids);
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, minDuties: int, maxDuties: int}>
     */
    private function eligibleTeachers(ExamSession $session): array
    {
        $constraints = SessionTeacherConstraint::where('exam_session_id', $session->id)->get()->keyBy('teacher_id');
        $excludedIds = $constraints->where('is_excluded', true)->keys();

        return Teacher::where('is_active', true)
            ->whereNotIn('id', $excludedIds)
            ->orderBy('id')
            ->get()
            ->map(fn (Teacher $teacher) => [
                'id' => $teacher->id,
                'minDuties' => $constraints->get($teacher->id)?->effectiveMinDuties() ?? config('exam.default_min_duties'),
                'maxDuties' => $constraints->get($teacher->id)?->effectiveMaxDuties() ?? config('exam.default_max_duties'),
            ])
            ->all();
    }

    private function persist(ExamSession $session, DutyResult $result): void
    {
        DB::transaction(function () use ($session, $result) {
            DutyAssignment::where('exam_session_id', $session->id)
                ->where('is_locked', false)
                ->delete();

            foreach ($result->placements as $placement) {
                DutyAssignment::create([
                    'exam_session_id' => $session->id,
                    'teacher_id' => $placement->teacherId,
                    'time_slot_id' => $placement->timeSlotId,
                    'room_id' => $placement->roomId,
                    'is_locked' => false,
                ]);
            }
        });
    }
}
