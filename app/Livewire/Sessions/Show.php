<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SubjectSlotAssignment;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Show extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public bool $editingDetails = false;

    public string $name = '';

    public string $department_name = '';

    public string $report_status = 'tentative';

    public string $report_version = '';

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
        $this->department_name = (string) $this->examSession->department_name;
        $this->report_status = $this->examSession->report_status;
        $this->report_version = (string) $this->examSession->report_version;
        $this->start_date = $this->examSession->start_date->toDateString();
        $this->end_date = $this->examSession->end_date->toDateString();
        $this->editingDetails = true;
    }

    public function saveDetails(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'department_name' => ['nullable', 'string', 'max:255'],
            'report_status' => ['required', 'in:tentative,final'],
            'report_version' => ['nullable', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
        $validated['department_name'] = $validated['department_name'] ?: null;
        $validated['report_version'] = $validated['report_version'] ?: null;

        $this->examSession->update($validated);
        $this->editingDetails = false;
    }

    public function finalize(): void
    {
        $this->authorize('finalize_sessions');

        if ($this->examSession->status !== 'generated') {
            session()->flash('error', 'Only a fully generated session can be finalized — run timetable, seating and duty generation first.');

            return;
        }

        $this->examSession->update(['status' => 'finalized', 'locked_at' => now()]);
        ActivityLog::record($this->examSession, 'session.finalized', "Finalized \"{$this->examSession->name}\" — it is now read-only.");

        session()->flash('status', 'Session finalized. It is now read-only until unlocked.');
    }

    public function unlock(): void
    {
        $this->authorize('finalize_sessions');

        $this->examSession->update(['status' => 'generated', 'locked_at' => null]);
        ActivityLog::record($this->examSession, 'session.unlocked', "Unlocked \"{$this->examSession->name}\" for editing.");

        session()->flash('status', 'Session unlocked — it can be edited again.');
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

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $enrollmentCount = $this->examSession->enrollments()->count();

        DB::transaction(function () {
            SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)->delete();
            DutyAssignment::where('exam_session_id', $this->examSession->id)->delete();
            $this->examSession->enrollments()->delete();
        });

        if ($this->examSession->status !== 'draft') {
            $this->examSession->update(['status' => 'draft']);
        }

        ActivityLog::record($this->examSession, 'enrollments.reset', "Removed all {$enrollmentCount} enrollment(s) to re-import from scratch.");

        session()->flash('status', 'All enrollments for this session were removed. You can import a fresh file now.');
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.sessions.show', [
            'enrollmentCount' => $this->examSession->enrollments()->count(),
            'studentCount' => $this->examSession->students()->count(),
            'subjectCount' => $this->examSession->subjects()->count(),
            'roomCount' => $this->examSession->rooms()->count(),
            'activeRoomCount' => $this->examSession->rooms()->where('is_active', true)->count(),
            'seatCount' => (int) $this->examSession->rooms()->where('is_active', true)->sum('capacity'),
            'teacherCount' => $this->examSession->teachers()->count(),
            'activityLogs' => $this->examSession->activityLogs()->with('user')->latest('created_at')->take(50)->get(),
        ]);
    }
}
