<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Teacher;
use Illuminate\Support\Collection;

/**
 * Every subject/section pair in a session with at least one un-taught
 * enrollment — the single source of truth behind the Missing Teachers card
 * (GenerationConstraints), the Ignored Missing Teachers page, both of the
 * card's bulk actions, and the Missing Teachers Excel import/template, so
 * they always agree on exactly what's pending.
 *
 * A pair explicitly dismissed via "Ignore All" (or the Ignored page's
 * per-row restore) is tracked as a "subject_id|section" key on the
 * session's ignored_missing_teacher_sections column — find() excludes
 * those, findIgnored() returns only those.
 */
class MissingTeacherSections
{
    public static function find(ExamSession $session): Collection
    {
        $ignored = $session->ignored_missing_teacher_sections ?? [];

        return self::baseQuery($session)
            ->reject(fn ($row) => in_array(self::key($row->subject_id, $row->section), $ignored, true));
    }

    public static function findIgnored(ExamSession $session): Collection
    {
        $ignored = $session->ignored_missing_teacher_sections ?? [];

        return self::baseQuery($session)
            ->filter(fn ($row) => in_array(self::key($row->subject_id, $row->section), $ignored, true));
    }

    public static function key(int $subjectId, string $section): string
    {
        return "{$subjectId}|{$section}";
    }

    /**
     * Active teacher(s) already on record for each subject — any
     * enrollment with that subject_id and a non-null teacher_id, across
     * every session, not just this one — so an assignment dropdown can
     * suggest "whoever already teaches this" instead of making staff
     * search an alphabetical list of every teacher for a name they might
     * not even know. Ordered by how often each teacher is linked to the
     * subject, since the most common pairing is the most likely answer.
     *
     * @param  int[]  $subjectIds
     * @return Collection<int, Collection<int, Teacher>> subject_id => teachers
     */
    public static function suggestedTeachers(array $subjectIds): Collection
    {
        if (empty($subjectIds)) {
            return collect();
        }

        $usage = Enrollment::whereIn('subject_id', $subjectIds)
            ->whereNotNull('teacher_id')
            ->selectRaw('subject_id, teacher_id, count(*) as uses')
            ->groupBy('subject_id', 'teacher_id')
            ->get();

        $teachers = Teacher::whereIn('id', $usage->pluck('teacher_id')->unique())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        return $usage->groupBy('subject_id')
            ->map(fn ($rows) => $rows->sortByDesc('uses')
                ->map(fn ($row) => $teachers->get($row->teacher_id))
                ->filter()
                ->values());
    }

    private static function baseQuery(ExamSession $session): Collection
    {
        return Enrollment::where('enrollments.exam_session_id', $session->id)
            ->whereNull('enrollments.teacher_id')
            ->join('subjects', 'subjects.id', '=', 'enrollments.subject_id')
            ->selectRaw('enrollments.subject_id, enrollments.section, subjects.code, subjects.title, count(*) as missing_count')
            ->groupBy('enrollments.subject_id', 'enrollments.section', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->orderBy('enrollments.section')
            ->get()
            ->values();
    }
}
