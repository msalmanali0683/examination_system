<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Teacher;
use App\Services\MissingTeacherSections;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The subject/section pairs dismissed via "Ignore All" on the Missing
 * Teachers card, on their own page so staff can work through that backlog
 * at their own pace — assign one at a time, restore individual pairs back
 * to the main card, or upload a spreadsheet — without restoring everything
 * at once the way the card's own "Show again" link does.
 */
class IgnoredMissingTeachers extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    /**
     * subject_id => section => teacher_id, bound per-row exactly like
     * GenerationConstraints::$missingTeacherSelection.
     *
     * @var array<int, array<string, string>>
     */
    public array $selection = [];

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    /**
     * Assigns the chosen teacher to this one pair's still-un-taught
     * enrollments — the pair naturally drops off both this page and the
     * ignored list, since it's no longer missing a teacher at all.
     */
    public function assignTeacher(int $subjectId, string $section): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $teacherId = $this->selection[$subjectId][$section] ?? null;

        if (! $teacherId || ! Teacher::whereKey($teacherId)->exists()) {
            $this->flashError('Pick a teacher before assigning.');

            return;
        }

        Enrollment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->where('section', $section)
            ->whereNull('teacher_id')
            ->update(['teacher_id' => $teacherId]);

        $this->dropFromIgnored($subjectId, $section);
        unset($this->selection[$subjectId][$section]);

        session()->flash('status', "Teacher assigned to {$section}.");
    }

    /**
     * Moves just this one pair back to the main Missing Teachers list,
     * without restoring every other ignored pair too — the individual
     * counterpart to GenerationConstraints::unignoreMissingTeachers().
     */
    public function restoreToMissingList(int $subjectId, string $section): void
    {
        $this->authorize('manage_sessions');

        $this->dropFromIgnored($subjectId, $section);

        session()->flash('status', "{$section} moved back to the Missing Teachers list.");
    }

    /**
     * Restores every ignored pair back to the main Missing Teachers list
     * at once — same effect as GenerationConstraints::unignoreMissingTeachers(),
     * exposed here too since this page is where the ignored list is
     * actually being reviewed.
     */
    public function restoreAll(): void
    {
        $this->authorize('manage_sessions');

        $this->examSession->update(['ignored_missing_teacher_sections' => []]);

        session()->flash('status', 'All ignored pairs moved back to the Missing Teachers list.');
    }

    private function dropFromIgnored(int $subjectId, string $section): void
    {
        $ignored = $this->examSession->ignored_missing_teacher_sections ?? [];
        $key = MissingTeacherSections::key($subjectId, $section);

        $this->examSession->update([
            'ignored_missing_teacher_sections' => array_values(array_diff($ignored, [$key])),
        ]);
    }

    private function flashError(string $message): void
    {
        session()->flash('error', $message);
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $ignoredSections = MissingTeacherSections::findIgnored($this->examSession);
        $suggestedTeachersBySubject = MissingTeacherSections::suggestedTeachers($ignoredSections->pluck('subject_id')->unique()->all());

        return view('livewire.sessions.ignored-missing-teachers', [
            'ignoredSections' => $ignoredSections,
            'suggestedTeachersBySubject' => $suggestedTeachersBySubject,
            'activeTeachers' => Teacher::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
