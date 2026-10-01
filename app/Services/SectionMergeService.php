<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Subject;
use InvalidArgumentException;

/**
 * Merges two or more of a subject's section labels into one — e.g. a SIS
 * quirk split one real class into "BSCS 1A" and "BSCS 1A ". Unlike
 * SubjectMergeService, there's no survivor/merge-away row to flag: section
 * is a plain string on Enrollment, and the table's unique constraint
 * (exam_session_id, student_id, subject_id) never includes it, so no
 * student can already hold a second enrollment row under this subject to
 * collide with — merging is just a relabel, one UPDATE statement.
 */
class SectionMergeService
{
    /**
     * @param  string[]  $sections  every selected label, including $keepSection
     * @return int how many enrollment rows were relabeled
     */
    public function merge(ExamSession $session, Subject $subject, string $keepSection, array $sections): int
    {
        $mergeAway = array_values(array_diff($sections, [$keepSection]));

        if (empty($mergeAway)) {
            throw new InvalidArgumentException('Select at least two sections to merge.');
        }

        return Enrollment::where('exam_session_id', $session->id)
            ->where('subject_id', $subject->id)
            ->whereIn('section', $mergeAway)
            ->update(['section' => $keepSection]);
    }
}
