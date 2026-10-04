<?php

namespace App\Services\Generation;

use App\Models\Enrollment;

/**
 * Which semester each subject belongs to, in the shape TimetableGenerator's
 * $semesterBySubject expects. Shared by the Timetable page (generation and
 * manual pins) and SlotSharingAdvisor, so every place that judges a
 * same-semester day clash uses the same definition of "same semester".
 */
class SubjectSemesters
{
    /**
     * Each subject gets its single dominant semester (see
     * SemesterExtractor::dominant()), wrapped in an array — not the full set
     * of every semester it touches, so a subject with a couple of repeaters
     * from another semester isn't misclassified as belonging to that
     * semester too. [] when the sections can't be parsed.
     *
     * @param  int[]  $subjectIds
     * @return array<int, int[]> subject_id => [dominant semester], or []
     */
    public static function forSubjects(int $sessionId, array $subjectIds): array
    {
        return Enrollment::where('exam_session_id', $sessionId)
            ->whereIn('subject_id', $subjectIds)
            ->select('subject_id', 'section')
            ->selectRaw('count(*) as c')
            ->groupBy('subject_id', 'section')
            ->get()
            ->groupBy('subject_id')
            ->map(function ($rows) {
                $dominant = SemesterExtractor::dominant($rows->pluck('c', 'section'));

                return $dominant === null ? [] : [$dominant];
            })
            ->all();
    }
}
