<?php

namespace App\Livewire\Students;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ExamSession;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A session's own students — the ones imported or enrolled into it, and
 * never shared with any other session.
 */
class Index extends Component
{
    use GuardsFinalizedSession;
    use WithPagination;

    public ExamSession $examSession;

    public int $perPage = 25;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $roll_no = '';

    public string $name = '';

    public string $program = '';

    public string $admission_year = '';

    /**
     * Student IDs checked for bulk actions — bound directly to each row's
     * checkbox via wire:model, so Livewire keeps this in sync without a
     * dedicated toggle method per row.
     *
     * @var int[]
     */
    public array $selected = [];

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_enrollments');
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

    public function addStudent(): void
    {
        $this->authorize('manage_enrollments');
        $this->resetForm();
        $this->showForm = true;
    }

    public function editStudent(int $id): void
    {
        $this->authorize('manage_enrollments');
        $student = $this->examSession->students()->findOrFail($id);

        $this->editingId = $student->id;
        $this->roll_no = $student->roll_no;
        $this->name = $student->name;
        $this->program = (string) $student->program;
        $this->admission_year = (string) $student->admission_year;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_enrollments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $validated = $this->validate([
            'roll_no' => ['required', 'string', 'max:255', Rule::unique('students', 'roll_no')->where('exam_session_id', $this->examSession->id)->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255'],
            'admission_year' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['program'] = $validated['program'] ?: null;
        $validated['admission_year'] = $validated['admission_year'] ?: null;

        $this->examSession->students()->updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Student saved.');
    }

    /**
     * A student's enrollments cascade-delete at the database level (see
     * enrollments.student_id's cascadeOnDelete()), taking their seat
     * assignment with them — fine while the session can still be
     * re-imported or regenerated, which is why a finalized session
     * refuses it outright.
     */
    public function deleteStudent(int $id): void
    {
        $this->authorize('manage_enrollments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $this->examSession->students()->findOrFail($id)->delete();
        session()->flash('status', 'Student deleted.');
    }

    /**
     * Every student in this session matching the current search (or the
     * session's whole roster when the search box is empty) — shared by
     * render() and deleteAllStudents() so "Delete All" always matches
     * exactly what's on screen, not the unfiltered whole roster.
     */
    private function studentsQuery()
    {
        return $this->examSession->students()->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
            ->where('roll_no', 'like', "%{$this->search}%")
            ->orWhere('name', 'like', "%{$this->search}%")
        ));
    }

    /**
     * Selects or deselects every student currently visible on this page
     * (not the whole search result set) — $ids comes straight from the
     * paginated rows the view is already showing.
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

    public function bulkDelete(): void
    {
        $this->authorize('manage_enrollments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $count = $this->examSession->students()->whereIn('id', $this->selected)->delete();

        $this->selected = [];
        $this->resetPage();

        session()->flash('status', "{$count} student(s) deleted.");
    }

    /**
     * Deletes every student in this session matching the current search
     * filter (the whole result set, not just the current page). Their
     * enrollments and seat assignments go with them (real FK cascades).
     */
    public function deleteAllStudents(): void
    {
        $this->authorize('manage_enrollments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $count = $this->studentsQuery()->delete();

        if ($count === 0) {
            session()->flash('error', 'No students to delete.');

            return;
        }

        $this->selected = [];
        $this->resetPage();

        session()->flash('status', "{$count} student(s) deleted.");
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'roll_no', 'name', 'program', 'admission_year']);
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.students.index', [
            'students' => $this->studentsQuery()->orderBy('roll_no')->paginate($this->perPage),
        ]);
    }
}
