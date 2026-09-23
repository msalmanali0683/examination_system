<?php

namespace App\Livewire\Students;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

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

    public function mount(): void
    {
        $this->authorize('manage_enrollments');
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
        $student = Student::findOrFail($id);

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

        $validated = $this->validate([
            'roll_no' => ['required', 'string', 'max:255', Rule::unique('students', 'roll_no')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255'],
            'admission_year' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['program'] = $validated['program'] ?: null;
        $validated['admission_year'] = $validated['admission_year'] ?: null;

        Student::updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Student saved.');
    }

    /**
     * A student's enrollments cascade-delete at the database level (see
     * enrollments.student_id's cascadeOnDelete()), which in turn cascades
     * to their seat assignment — for a finalized session that would
     * silently erase part of its permanent seating chart, so this is
     * blocked the same way deleting the session itself is blocked while
     * finalized. A non-finalized session's enrollments can always be
     * re-imported, so only finalized use blocks this.
     */
    public function deleteStudent(int $id): void
    {
        $this->authorize('manage_enrollments');
        $student = Student::findOrFail($id);

        if ($this->hasFinalizedEnrollments($student)) {
            session()->flash('error', "{$student->name} has enrollments in a finalized session and can't be deleted — unlock that session first if it really needs to change.");

            return;
        }

        $student->delete();
        session()->flash('status', 'Student deleted.');
    }

    private function hasFinalizedEnrollments(Student $student): bool
    {
        return Enrollment::where('student_id', $student->id)
            ->whereHas('examSession', fn ($q) => $q->where('status', 'finalized'))
            ->exists();
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

    /**
     * A student's enrollment rows (and their seat assignments) cascade-
     * delete at the database level — every one of those is a real FK
     * constraint, not application logic, so this can never fail with a
     * foreign-key error. But a student with enrollments in a finalized
     * session is skipped (see hasFinalizedEnrollments()) rather than
     * deleted, so a bulk action can never silently erase part of a
     * finalized session's seating chart.
     */
    public function bulkDelete(): void
    {
        $this->authorize('manage_enrollments');

        $selected = Student::whereIn('id', $this->selected)->get();
        $blocked = $selected->filter(fn (Student $student) => $this->hasFinalizedEnrollments($student));
        $toDelete = $selected->reject(fn (Student $student) => $blocked->contains($student));

        $count = Student::destroy($toDelete->pluck('id'));

        $this->selected = [];
        $this->resetPage();

        session()->flash($blocked->isEmpty() ? 'status' : 'error', "{$count} student(s) deleted."
            .($blocked->isEmpty() ? '' : " {$blocked->count()} skipped — they have enrollments in a finalized session."));
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
            'students' => Student::when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('roll_no', 'like', "%{$this->search}%")
                ->orWhere('name', 'like', "%{$this->search}%")
            ))
                ->orderBy('roll_no')
                ->paginate($this->perPage),
        ]);
    }
}
