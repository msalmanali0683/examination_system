<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\ExamSession;
use Illuminate\Support\Collection;

/**
 * Every subject/section pair in a session with at least one un-taught
 * enrollment, excluding pairs explicitly dismissed via "Ignore All" — the
 * single source of truth behind the Missing Teachers card
 * (GenerationConstraints), both of its bulk actions, and the Missing
 * Teachers Excel import/template, so they always agree on exactly what's
 * pending.
 */
class MissingTeacherSections
{
    public static function find(ExamSession $session): Collection
    {
        $ignored = $session->ignored_missing_teacher_sections ?? [];

        return Enrollment::where('enrollments.exam_session_id', $session->id)
            ->whereNull('enrollments.teacher_id')
            ->join('subjects', 'subjects.id', '=', 'enrollments.subject_id')
            ->selectRaw('enrollments.subject_id, enrollments.section, subjects.code, subjects.title, count(*) as missing_count')
            ->groupBy('enrollments.subject_id', 'enrollments.section', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->orderBy('enrollments.section')
            ->get()
            ->reject(fn ($row) => in_array(self::key($row->subject_id, $row->section), $ignored, true))
            ->values();
    }

    public static function key(int $subjectId, string $section): string
    {
        return "{$subjectId}|{$section}";
    }
}
