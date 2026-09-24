<?php

namespace App\Services\Generation;

use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\SessionTeacherConstraint;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\DTOs\DutyPlacement;
use App\Services\Generation\DTOs\DutyResult;
use Illuminate\Support\Collection;
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
    ) {}

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
     * @return array{0: array<int, array{id: int, roomIds: int[], unavailableTeacherIds: int[], adjacentSlotIds: int[]}>, 1: DutyPlacement[]}
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

        $timeSlots = $session->timeSlots()->orderBy('date')->orderBy('start_time')->get();
        $adjacentSlotIds = $this->adjacentSlotIdsMap($timeSlots);

        $slots = [];

        foreach ($timeSlots as $slot) {
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
                'adjacentSlotIds' => $adjacentSlotIds[$slot->id] ?? [],
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
     * Maps each time slot to the immediately-preceding and following slot
     * on the *same day* (by start time) — used to softly avoid assigning a
     * teacher two back-to-back invigilation duties. Slots on different days
     * are never considered adjacent, however small the calendar gap.
     *
     * @return array<int, int[]>
     */
    private function adjacentSlotIdsMap(Collection $timeSlots): array
    {
        $map = [];

        foreach ($timeSlots->groupBy(fn (TimeSlot $slot) => $slot->date->format('Y-m-d')) as $dayGroup) {
            $ordered = $dayGroup->sortBy('start_time')->values();

            foreach ($ordered as $index => $slot) {
                $neighbors = [];

                if ($index > 0) {
                    $neighbors[] = $ordered[$index - 1]->id;
                }

                if ($index < $ordered->count() - 1) {
                    $neighbors[] = $ordered[$index + 1]->id;
                }

                $map[$slot->id] = $neighbors;
            }
        }

        return $map;
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
        $sectionOverrides = $this->sectionBasedDutyOverrides($session);

        return $session->teachers()->where('is_active', true)
            ->whereNotIn('id', $excludedIds)
            ->orderBy('id')
            ->get()
            ->map(function (Teacher $teacher) use ($constraints, $sectionOverrides) {
                $override = $sectionOverrides[$teacher->id] ?? null;

                return [
                    'id' => $teacher->id,
                    'minDuties' => $override ?? ($constraints->get($teacher->id)?->effectiveMinDuties() ?? config('exam.default_min_duties')),
                    'maxDuties' => $override ?? ($constraints->get($teacher->id)?->effectiveMaxDuties() ?? config('exam.default_max_duties')),
                ];
            })
            ->all();
    }

    /**
     * For any subject marked "duty = sections taught", a teacher who
     * teaches N distinct sections of it (per the enrollment Teacher
     * column) gets their session duty count forced to exactly N —
     * overriding any individual or bulk min/max override, since this is a
     * more specific, deliberately-opted-into rule. Summed across every
     * such subject a teacher teaches, in the (expected to be rare) case
     * more than one is checked.
     *
     * @return array<int, int> teacher_id => exact duty count
     */
    private function sectionBasedDutyOverrides(ExamSession $session): array
    {
        $checkedSubjectIds = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->where('duty_matches_sections', true)
            ->where('is_excluded', false)
            ->pluck('subject_id');

        if ($checkedSubjectIds->isEmpty()) {
            return [];
        }

        return Enrollment::where('exam_session_id', $session->id)
            ->whereIn('subject_id', $checkedSubjectIds)
            ->whereNotNull('teacher_id')
            ->select('teacher_id', 'subject_id', 'section')
            ->distinct()
            ->get()
            ->groupBy('teacher_id')
            ->map(fn ($rows) => $rows->count())
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
