<?php

namespace App\Livewire\Sessions;

use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SubjectSlotAssignment;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Show extends Component
{
    public ExamSession $examSession;

    public bool $editingDetails = false;

    public string $name = '';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    public function editDetails(): void
    {
        $this->authorize('manage_sessions');
        $this->name = $this->examSession->name;
        $this->start_date = $this->examSession->start_date->toDateString();
        $this->end_date = $this->examSession->end_date->toDateString();
        $this->editingDetails = true;
    }

    public function saveDetails(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $this->examSession->update($validated);
        $this->editingDetails = false;
    }

    /**
     * Wipes every enrollment for this session so it can be re-imported
     * from scratch. Deleting an enrollment cascades to its seat
     * assignment (FK on seat_assignments.enrollment_id), but subject
     * slot assignments and duty assignments are keyed by subject/room,
     * not enrollment, so those are cleared explicitly — otherwise a
     * subject with no enrollments left would still show as scheduled,
     * and duties (tied to which rooms hosted which slot) would go
     * stale. Anything already generated from this data is stale once
     * the underlying enrollments are gone, so the session drops back
     * to 'draft'.
     */
    public function resetEnrollments(): void
    {
        $this->authorize('manage_enrollments');

        if ($this->examSession->isFinalized()) {
            session()->flash('error', 'This session is finalized and cannot be modified.');

            return;
        }

        DB::transaction(function () {
            SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)->delete();
            DutyAssignment::where('exam_session_id', $this->examSession->id)->delete();
            $this->examSession->enrollments()->delete();
        });

        if ($this->examSession->status !== 'draft') {
            $this->examSession->update(['status' => 'draft']);
        }

        session()->flash('status', 'All enrollments for this session were removed. You can import a fresh file now.');
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.sessions.show', [
            'enrollmentCount' => $this->examSession->enrollments()->count(),
            'studentCount' => $this->examSession->enrollments()->distinct()->count('student_id'),
            'subjectCount' => $this->examSession->enrollments()->distinct()->count('subject_id'),
        ]);
    }
}
