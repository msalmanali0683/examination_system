<?php

namespace App\Livewire\Subjects;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Services\SubjectMergeService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A session's own subjects — created from its enrollment import, and never
 * shared with any other session.
 */
class Index extends Component
{
    use GuardsFinalizedSession;
    use WithPagination;

    public ExamSession $examSession;

    public int $perPage = 25;

    public string $search = '';

    /**
     * Set once subjects have been deleted from a session that already had a timetable, seating plan or duties:
     * those no longer match the remaining subjects, so the page shows the regenerate-in-order banner.
     */
    public bool $needsRegeneration = false;

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

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_subjects');
        $this->examSession = $examSession;
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

    /**
     * Deletes one subject of this session. Its enrollments go with it (and so do their seats), as does its
     * timetable slot — fine while the session can still be regenerated, which is why a finalized session
     * refuses it outright. Students themselves are kept.
     */
    public function deleteSubject(int $id): void
    {
        $this->authorize('manage_subjects');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $this->removeSubjects($this->examSession->subjects()->whereKey($id)->get());
    }

    /**
     * Deletes every ticked subject (only this session's own — ids come from the browser).
     */
    public function bulkDelete(): void
    {
        $this->authorize('manage_subjects');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $subjects = $this->examSession->subjects()->whereIn('id', array_map('intval', $this->selected))->get();

        if ($subjects->isEmpty()) {
            session()->flash('error', 'Select at least one subject to delete.');

            return;
        }

        $this->removeSubjects($subjects);
        $this->selected = [];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Subject>  $subjects
     */
    private function removeSubjects($subjects): void
    {
        if ($subjects->isEmpty()) {
            session()->flash('error', 'That subject no longer exists — the list may be out of date.');

            return;
        }

        $ids = $subjects->pluck('id')->all();

        // Subjects that were merged INTO a deleted one are only retired aliases of it (their enrollments moved
        // onto it), so they go too rather than resurfacing as empty "Active" subjects.
        $aliases = $this->examSession->subjects()->whereIn('merged_into_id', $ids)->whereNotIn('id', $ids)->get();
        $all = $subjects->merge($aliases);
        $allIds = $all->pluck('id')->all();

        $enrollmentCount = Enrollment::where('exam_session_id', $this->examSession->id)->whereIn('subject_id', $allIds)->count();

        // Judged before deleting: was there anything generated that is about to stop matching?
        $hadSchedule = SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)->whereNotNull('time_slot_id')->exists()
            || SeatAssignment::where('exam_session_id', $this->examSession->id)->exists()
            || DutyAssignment::where('exam_session_id', $this->examSession->id)->exists();

        DB::transaction(fn () => Subject::whereIn('id', $allIds)->delete());

        if ($hadSchedule) {
            // Same rule as removing every enrollment: what was generated no longer matches the data, so the
            // session is a draft again until timetable, seating and duties are regenerated.
            if ($this->examSession->status === 'generated') {
                $this->examSession->update(['status' => 'draft']);
            }

            $this->needsRegeneration = true;
        }

        $label = $subjects->count() === 1 ? $subjects->first()->code : $subjects->count().' subjects';

        ActivityLog::record($this->examSession, 'subjects.deleted', "Deleted {$label} with {$enrollmentCount} enrollment(s).");

        $this->resetPage();

        session()->flash(
            'status',
            'Deleted '.($subjects->count() === 1 ? "subject {$subjects->first()->code}" : "{$subjects->count()} subjects")
                .($aliases->isNotEmpty() ? " (and {$aliases->count()} retired merged code(s))" : '')
                ." and {$enrollmentCount} enrollment(s)."
                .($hadSchedule ? ' The timetable, seating plan and duties need to be regenerated.' : '')
        );
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
        // Alpine's own "show" state, once initialized, isn't re-read from
        // :show="$showMergeModal" on a later Livewire morph — open via
        // this explicit event instead, same as every other modal.
        $this->dispatch('open-modal', 'merge-subjects');
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

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        // Checkbox values arrive as strings (e.g. "1"), so $selected is an
        // array of strings — normalize before comparing against the
        // (int)-cast survivor id, or a strict in_array() check here would
        // wrongly reject a genuinely selected survivor every time.
        $selectedIds = array_map('intval', $this->selected);
        $survivorId = (int) $this->survivorId;

        if (! in_array($survivorId, $selectedIds, true)) {
            session()->flash('error', 'Pick which subject should survive the merge.');

            return;
        }

        $keep = $this->examSession->subjects()->find($survivorId);
        $mergeAwayIds = array_values(array_diff($selectedIds, [$survivorId]));

        if (! $keep || empty($mergeAwayIds)) {
            session()->flash('error', 'Select at least two subjects to merge.');

            return;
        }

        $service = new SubjectMergeService;
        $totals = ['enrollmentsMoved' => 0, 'enrollmentsDropped' => 0, 'slotAssignmentsMoved' => 0, 'slotAssignmentsDropped' => 0, 'sessionsSkipped' => 0];
        $merged = 0;

        foreach ($mergeAwayIds as $mergeAwayId) {
            $mergeAway = $this->examSession->subjects()->find($mergeAwayId);

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
        // wire:confirm on the Merge button means the modal can't rely on
        // a same-click x-on:click to close itself — that would fire
        // immediately regardless of whether the confirm dialog was
        // accepted, closing the modal even when the merge never ran (or
        // before it finished). Dispatching close-modal here only happens
        // once the merge has actually completed.
        $this->dispatch('close-modal', 'merge-subjects');
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
            'subjects' => $this->examSession->subjects()->with('mergedInto')
                ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                    ->where('code', 'like', "%{$this->search}%")
                    ->orWhere('title', 'like', "%{$this->search}%")
                ))
                ->orderBy('code')
                ->paginate($this->perPage),
        ]);
    }
}
