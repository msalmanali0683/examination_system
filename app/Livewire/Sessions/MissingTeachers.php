<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Teacher;
use App\Services\MissingTeacherSections;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Missing Teachers card, split onto its own page — every un-taught
 * subject/section pair in the session, with per-row and bulk assignment,
 * plus the ignore/dismiss workflow. Split out for the same reason as
 * Timetable: it's a distinct, self-contained workflow that doesn't need
 * to compete for attention with seating/duty generation.
 */
class MissingTeachers extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    /**
     * Pending teacher choice for each subject/section that has no teacher
     * on any of its enrollments (e.g. the import row's Teacher column was
     * blank) — keyed by subject_id then section. Assigning fills in only
     * the enrollments still missing a teacher for that pair, so it never
     * overwrites a teacher already recorded on some of them.
     */
    public array $missingTeacherSelection = [];

    /**
     * Teacher chosen for the "assign to all" bulk action — a single
     * selection applied to every subject/section pair currently listed,
     * as an alternative to picking one row at a time.
     */
    public string $bulkMissingTeacherId = '';

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    /**
     * Assigns the chosen teacher to every enrollment for this subject+
     * section that currently has no teacher, so features that depend on
     * the enrollment Teacher column (invigilator-subject exclusion,
     * duty-matches-sections) can account for them.
     */
    public function assignMissingTeacher(int $subjectId, string $section): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $teacherId = $this->missingTeacherSelection[$subjectId][$section] ?? null;

        if (! $teacherId || ! Teacher::whereKey($teacherId)->exists()) {
            session()->flash('error', 'Pick a teacher before assigning.');

            return;
        }

        Enrollment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->where('section', $section)
            ->whereNull('teacher_id')
            ->update(['teacher_id' => $teacherId]);

        unset($this->missingTeacherSelection[$subjectId][$section]);

        session()->flash('status', "Teacher assigned to {$section}.");
    }

    /**
     * Assigns one chosen teacher to every subject/section pair currently
     * listed (skipping any already ignored), for when the real answer
     * for all of them is the same person rather than picking row by row.
     */
    public function assignMissingTeacherToAll(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $teacherId = $this->bulkMissingTeacherId;

        if (! $teacherId || ! Teacher::whereKey($teacherId)->exists()) {
            session()->flash('error', 'Pick a teacher before assigning to all.');

            return;
        }

        $pairs = MissingTeacherSections::find($this->examSession);

        if ($pairs->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($pairs, $teacherId) {
            foreach ($pairs as $pair) {
                Enrollment::where('exam_session_id', $this->examSession->id)
                    ->where('subject_id', $pair->subject_id)
                    ->where('section', $pair->section)
                    ->whereNull('teacher_id')
                    ->update(['teacher_id' => $teacherId]);
            }
        });

        $this->missingTeacherSelection = [];
        $this->bulkMissingTeacherId = '';

        session()->flash('status', "Assigned a teacher to all {$pairs->count()} pending subject/section pair(s).");
    }

    /**
     * Dismisses every subject/section pair currently listed without
     * assigning anyone — for cases where that's the real answer (e.g. an
     * online/self-invigilated paper). Dismissed pairs stay without a
     * teacher and won't be listed again, even after a later import,
     * until unignored.
     */
    public function ignoreAllMissingTeachers(): void
    {
        $this->authorize('manage_sessions');

        $pairs = MissingTeacherSections::find($this->examSession);

        if ($pairs->isEmpty()) {
            return;
        }

        $ignored = $this->examSession->ignored_missing_teacher_sections ?? [];

        foreach ($pairs as $pair) {
            $ignored[] = MissingTeacherSections::key($pair->subject_id, $pair->section);
        }

        $this->examSession->update(['ignored_missing_teacher_sections' => array_values(array_unique($ignored))]);
        $this->missingTeacherSelection = [];

        session()->flash('status', "Dismissed {$pairs->count()} pending subject/section pair(s) — they'll stay without a teacher.");
    }

    /**
     * Brings back every subject/section pair dismissed via "Ignore All"
     * so they show up on this list again if still missing a teacher.
     */
    public function unignoreMissingTeachers(): void
    {
        $this->authorize('manage_sessions');

        $this->examSession->update(['ignored_missing_teacher_sections' => []]);

        session()->flash('status', 'Dismissed subject/section pairs are visible again.');
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $missingTeacherSections = MissingTeacherSections::find($this->examSession);
        $suggestedTeachersBySubject = MissingTeacherSections::suggestedTeachers($missingTeacherSections->pluck('subject_id')->unique()->all());

        return view('livewire.sessions.missing-teachers', [
            'missingTeacherSections' => $missingTeacherSections,
            'suggestedTeachersBySubject' => $suggestedTeachersBySubject,
            'ignoredMissingTeacherCount' => count($this->examSession->ignored_missing_teacher_sections ?? []),
            'activeTeachers' => Teacher::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
