<?php

namespace App\Livewire\Teachers;

use App\Models\Teacher;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public int $perPage = 25;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $designation = '';

    public string $department = '';

    public string $email = '';

    public string $phone = '';

    public bool $is_active = true;

    public string $search = '';

    /**
     * Teacher IDs checked for bulk actions — bound directly to each row's
     * checkbox via wire:model, so Livewire keeps this in sync without a
     * dedicated toggle method per row.
     *
     * @var int[]
     */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('manage_teachers');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function addTeacher(): void
    {
        $this->authorize('manage_teachers');
        $this->resetForm();
        $this->showForm = true;
    }

    public function editTeacher(int $id): void
    {
        $this->authorize('manage_teachers');
        $teacher = Teacher::findOrFail($id);

        $this->editingId = $teacher->id;
        $this->name = $teacher->name;
        $this->designation = (string) $teacher->designation;
        $this->department = (string) $teacher->department;
        $this->email = (string) $teacher->email;
        $this->phone = (string) $teacher->phone;
        $this->is_active = $teacher->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_teachers');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('teachers', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['is_active'] = $this->is_active;
        $validated['email'] = $validated['email'] ?: null;

        Teacher::updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Teacher saved.');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage_teachers');
        $teacher = Teacher::findOrFail($id);
        $teacher->update(['is_active' => ! $teacher->is_active]);
    }

    public function deleteTeacher(int $id): void
    {
        $this->authorize('manage_teachers');
        Teacher::findOrFail($id)->delete();
        session()->flash('status', 'Teacher deleted.');
    }

    /**
     * Selects or deselects every teacher currently visible on this page
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
     * A teacher's duty assignments and session-teacher-constraint rows
     * cascade-delete at the database level, and their enrollment rows
     * just lose the teacher reference (nullable column) — every one of
     * those is a real FK constraint, not application logic, so this can
     * never fail with a foreign-key error even when the teacher has
     * duties in an active or finalized session.
     */
    public function bulkDelete(): void
    {
        $this->authorize('manage_teachers');

        $count = Teacher::destroy($this->selected);

        $this->selected = [];
        $this->resetPage();
        session()->flash('status', "{$count} teacher(s) deleted.");
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'designation', 'department', 'email', 'phone', 'is_active']);
        $this->is_active = true;
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.teachers.index', [
            'teachers' => Teacher::when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                ))
                ->orderBy('name')
                ->paginate($this->perPage),
        ]);
    }
}
