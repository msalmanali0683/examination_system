<?php

namespace App\Livewire\Subjects;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Subject;
use App\Services\SectionMergeService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One subject's sections (as grouped straight from its enrollments) with a
 * way to merge two or more labels that turned out to be the same real
 * class — e.g. a SIS export quirk split "BSCS 1A" into two slightly
 * different strings.
 */
class Show extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public Subject $subject;

    /**
     * Section labels checked for a merge — bound per row via wire:model,
     * same pattern as the subject-merge checkboxes on the Subjects index.
     *
     * @var string[]
     */
    public array $selected = [];

    public bool $showMergeModal = false;

    /**
     * Which selected section's label survives the merge, chosen in the
     * modal — every other selected section's enrollments are relabeled
     * onto it.
     */
    public string $keepSection = '';

    public function mount(ExamSession $examSession, int $subjectId): void
    {
        $this->authorize('manage_subjects');
        $this->examSession = $examSession;
        $this->subject = $examSession->subjects()->findOrFail($subjectId);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function openMergeModal(): void
    {
        $this->authorize('manage_subjects');

        if (count($this->selected) < 2) {
            session()->flash('error', 'Select at least two sections to merge.');

            return;
        }

        $this->keepSection = $this->selected[0];
        $this->showMergeModal = true;
        // Alpine's own "show" state, once initialized, isn't re-read from
        // :show="$showMergeModal" on a later Livewire morph — open via
        // this explicit event instead, same as every other modal.
        $this->dispatch('open-modal', 'merge-sections');
    }

    public function closeMergeModal(): void
    {
        $this->showMergeModal = false;
        $this->keepSection = '';
    }

    public function confirmMerge(): void
    {
        $this->authorize('manage_subjects');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        if (count($this->selected) < 2) {
            session()->flash('error', 'Select at least two sections to merge.');

            return;
        }

        if (! in_array($this->keepSection, $this->selected, true)) {
            session()->flash('error', 'Pick which section should survive the merge.');

            return;
        }

        $mergedLabels = array_values(array_diff($this->selected, [$this->keepSection]));
        $moved = (new SectionMergeService)->merge($this->examSession, $this->subject, $this->keepSection, $this->selected);

        ActivityLog::record(
            $this->examSession,
            'subjects.sections_merged',
            'Merged section(s) '.implode(', ', $mergedLabels)." into \"{$this->keepSection}\" for {$this->subject->code} ({$moved} student(s))."
        );

        $this->selected = [];
        $this->closeMergeModal();
        // wire:confirm on the Merge button means the modal can't rely on a
        // same-click x-on:click to close itself — that would fire
        // immediately regardless of whether the confirm dialog was
        // accepted. Dispatching close-modal here only happens once the
        // merge has actually completed.
        $this->dispatch('close-modal', 'merge-sections');

        session()->flash('status', 'Merged '.count($mergedLabels)." section(s) into \"{$this->keepSection}\". {$moved} student(s) updated.");
    }

    /**
     * @return Collection<int, object{section: string, student_count: int}>
     */
    private function sections(): Collection
    {
        return Enrollment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $this->subject->id)
            ->selectRaw('section, count(*) as student_count')
            ->groupBy('section')
            ->orderBy('section')
            ->get();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.subjects.show', [
            'sections' => $this->sections(),
        ]);
    }
}
