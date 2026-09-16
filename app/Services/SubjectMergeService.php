<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges two subjects that turned out to be the same real course under
 * different catalog codes (e.g. a department prefix change) into one.
 *
 * The merged-away subject's row is never deleted — a finalized session's
 * enrollments may still reference it, and that historical record is left
 * untouched. Only enrollments and pinned slots in non-finalized sessions
 * are moved onto the surviving subject; the merged-away subject is
 * flagged via merged_into_id so future enrollment imports under its code
 * resolve to the survivor instead (see EnrollmentImport::commitImport()).
 */
class SubjectMergeService
{
    /**
     * @return array{enrollmentsMoved: int, enrollmentsDropped: int, slotAssignmentsMoved: int, slotAssignmentsDropped: int, sessionsSkipped: int}
     */
    public function merge(Subject $keep, Subject $mergeAway): array
    {
        if ($keep->is($mergeAway)) {
            throw new InvalidArgumentException('Cannot merge a subject into itself.');
        }

        if ($keep->isMerged() || $mergeAway->isMerged()) {
            throw new InvalidArgumentException('One of these subjects has already been merged.');
        }

        return DB::transaction(function () use ($keep, $mergeAway) {
            [$enrollmentsMoved, $enrollmentsDropped, $skippedFromEnrollments] = $this->moveEnrollments($keep, $mergeAway);
            [$slotAssignmentsMoved, $slotAssignmentsDropped, $skippedFromSlots] = $this->moveSlotAssignments($keep, $mergeAway);

            $mergeAway->update(['merged_into_id' => $keep->id]);

            return [
                'enrollmentsMoved' => $enrollmentsMoved,
                'enrollmentsDropped' => $enrollmentsDropped,
                'slotAssignmentsMoved' => $slotAssignmentsMoved,
                'slotAssignmentsDropped' => $slotAssignmentsDropped,
                'sessionsSkipped' => $skippedFromEnrollments->merge($skippedFromSlots)->unique()->count(),
            ];
        });
    }

    /**
     * @return array{0: int, 1: int, 2: Collection}
     */
    private function moveEnrollments(Subject $keep, Subject $mergeAway): array
    {
        // Every (session, student) pair already enrolled under the
        // survivor — moving a merge-away enrollment onto one of these
        // would collide with enrollments' unique constraint, so that row
        // is dropped instead (the student is already recorded correctly).
        $keepKeys = Enrollment::where('subject_id', $keep->id)
            ->get(['exam_session_id', 'student_id'])
            ->map(fn ($e) => "{$e->exam_session_id}|{$e->student_id}")
            ->flip();

        $moved = 0;
        $dropped = 0;
        $skippedSessionIds = collect();

        Enrollment::where('subject_id', $mergeAway->id)
            ->with('examSession')
            ->get()
            ->each(function (Enrollment $enrollment) use ($keep, $keepKeys, &$moved, &$dropped, &$skippedSessionIds) {
                if ($enrollment->examSession->isFinalized()) {
                    $skippedSessionIds->push($enrollment->exam_session_id);

                    return;
                }

                if ($keepKeys->has("{$enrollment->exam_session_id}|{$enrollment->student_id}")) {
                    $enrollment->delete();
                    $dropped++;
                } else {
                    $enrollment->update(['subject_id' => $keep->id]);
                    $moved++;
                }
            });

        return [$moved, $dropped, $skippedSessionIds];
    }

    /**
     * @return array{0: int, 1: int, 2: Collection}
     */
    private function moveSlotAssignments(Subject $keep, Subject $mergeAway): array
    {
        // Sessions where the survivor already has its own pin/placement —
        // the merge-away subject's assignment is redundant there and is
        // dropped rather than colliding with the unique constraint.
        $keepSessionIds = SubjectSlotAssignment::where('subject_id', $keep->id)
            ->pluck('exam_session_id')
            ->flip();

        $moved = 0;
        $dropped = 0;
        $skippedSessionIds = collect();

        SubjectSlotAssignment::where('subject_id', $mergeAway->id)
            ->with('examSession')
            ->get()
            ->each(function (SubjectSlotAssignment $assignment) use ($keep, $keepSessionIds, &$moved, &$dropped, &$skippedSessionIds) {
                if ($assignment->examSession->isFinalized()) {
                    $skippedSessionIds->push($assignment->exam_session_id);

                    return;
                }

                if ($keepSessionIds->has($assignment->exam_session_id)) {
                    $assignment->delete();
                    $dropped++;
                } else {
                    $assignment->update(['subject_id' => $keep->id]);
                    $moved++;
                }
            });

        return [$moved, $dropped, $skippedSessionIds];
    }
}
