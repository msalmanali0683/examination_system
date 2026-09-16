<?php

namespace App\Livewire\Subjects;

use App\Models\Subject;
use App\Services\SubjectMergeService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public int $perPage = 25;

    public string $search = '';

    /**
     * Subject IDs checked for a merge — bound directly to each row's
     * checkbox via wire:model, same pattern as bulk teacher delete.
     *
     * @var int[]
     */
    public array $selected = [];

    public bool $showMergeModal = false;

    /**
     * Which of the selected subjects survives the merge, chosen in the
     * modal — the rest are merged into it. Kept as a string since it's
     * bound to a radio input.
     */
    public string $survivorId = '';

    public function mount(): void
    {
        $this->authorize('manage_subjects');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Selects or deselects every non-merged subject currently visible on
     * this page (not the whole search result set).
     *
     * @param  int[]  $ids
     */
    public function toggleSelectAllOnPage(array $ids): void
    {
        if (! empty($ids) && empty(array_diff($ids, $this->selected))) {
            $this->selected = array_values(array_diff($this->selected, $ids));
        } else {
            $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function openMergeModal(): void
    {
        $this->authorize('manage_subjects');

        if (count($this->selected) < 2) {
            session()->flash('error', 'Select at least two subjects to merge.');

            return;
        }

        $this->survivorId = (string) $this->selected[0];
        $this->showMergeModal = true;
    }

    public function closeMergeModal(): void
    {
        $this->showMergeModal = false;
        $this->survivorId = '';
    }

    /**
     * Merges every other selected subject into the chosen survivor, one
     * pair at a time — each pair already checks for an already-merged
     * subject and duplicate enrollments, so a 3+ way merge is just that
     * same safe operation repeated.
     */
    public function confirmMerge(): void
    {
        $this->authorize('manage_subjects');

        if (! in_array((int) $this->survivorId, $this->selected, true)) {
            session()->flash('error', 'Pick which subject should survive the merge.');

            return;
        }

        $keep = Subject::find((int) $this->survivorId);
        $mergeAwayIds = array_values(array_diff($this->selected, [(int) $this->survivorId]));

        if (! $keep || empty($mergeAwayIds)) {
            session()->flash('error', 'Select at least two subjects to merge.');

            return;
        }

        $service = new SubjectMergeService;
        $totals = ['enrollmentsMoved' => 0, 'enrollmentsDropped' => 0, 'slotAssignmentsMoved' => 0, 'slotAssignmentsDropped' => 0, 'sessionsSkipped' => 0];
        $merged = 0;

        foreach ($mergeAwayIds as $mergeAwayId) {
            $mergeAway = Subject::find($mergeAwayId);

            if (! $mergeAway || $mergeAway->isMerged()) {
                continue;
            }

            $stats = $service->merge($keep, $mergeAway);

            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }

            $merged++;
        }

        $this->selected = [];
        $this->closeMergeModal();
        $this->resetPage();

        session()->flash(
            'status',
            "Merged {$merged} subject(s) into {$keep->code}. {$totals['enrollmentsMoved']} enrollment(s) moved"
                .($totals['enrollmentsDropped'] ? ", {$totals['enrollmentsDropped']} duplicate enrollment(s) dropped" : '')
                .($totals['sessionsSkipped'] ? ", {$totals['sessionsSkipped']} finalized session(s) left untouched" : '')
                .'.'
        );
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.subjects.index', [
            'subjects' => Subject::with('mergedInto')
                ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                    ->where('code', 'like', "%{$this->search}%")
                    ->orWhere('title', 'like', "%{$this->search}%")
                ))
                ->orderBy('code')
                ->paginate($this->perPage),
        ]);
    }
}
