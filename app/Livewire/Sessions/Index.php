<?php

namespace App\Livewire\Sessions;

use App\Models\ActivityLog;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Index extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?int $duplicatingId = null;

    public string $duplicateName = '';

    public ?string $duplicateStartDate = null;

    public ?string $duplicateEndDate = null;

    public function mount(): void
    {
        $this->authorize('manage_sessions');
    }

    public function addSession(): void
    {
        $this->authorize('manage_sessions');
        $this->reset(['name', 'start_date', 'end_date']);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $session = ExamSession::create($validated);

        $this->redirect(route('sessions.show', $session), navigate: true);
    }

    public function cancel(): void
    {
        $this->reset(['name', 'start_date', 'end_date']);
        $this->showForm = false;
    }

    /**
     * Starts a session from a past one — its rooms, its teachers (with
     * their duty constraints) and its generation settings are copied as
     * brand-new rows owned by the new session, so editing them later never
     * touches the original. None of the exam data (students, subjects,
     * enrollments, time slots, generated seats/duties) carries over, since
     * that needs a fresh import and generation run each time.
     */
    public function startDuplicate(int $sessionId): void
    {
        $this->authorize('manage_sessions');

        $source = ExamSession::findOrFail($sessionId);

        $this->duplicatingId = $sessionId;
        $this->duplicateName = "{$source->name} (Copy)";
        $this->duplicateStartDate = $source->start_date->toDateString();
        $this->duplicateEndDate = $source->end_date->toDateString();
    }

    public function confirmDuplicate(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'duplicateName' => ['required', 'string', 'max:255'],
            'duplicateStartDate' => ['required', 'date'],
            'duplicateEndDate' => ['required', 'date', 'after_or_equal:duplicateStartDate'],
        ]);

        $source = ExamSession::findOrFail($this->duplicatingId);

        $newSession = DB::transaction(function () use ($source, $validated) {
            $newSession = ExamSession::create([
                'name' => $validated['duplicateName'],
                'start_date' => $validated['duplicateStartDate'],
                'end_date' => $validated['duplicateEndDate'],
                'status' => 'draft',
                'seating_strategy' => $source->seating_strategy,
                'mixed_subjects_per_room' => $source->mixed_subjects_per_room,
                'invigilators_per_room' => $source->invigilators_per_room,
                'teacher_subject_exclusion' => $source->teacher_subject_exclusion,
            ]);

            foreach ($source->rooms as $room) {
                $newSession->rooms()->create($room->only(['name', 'rows', 'columns', 'capacity', 'room_type', 'is_active']));
            }

            $copiedTeacherIds = [];

            foreach ($source->teachers as $teacher) {
                $copy = $newSession->teachers()->create($teacher->only(['name', 'designation', 'department', 'email', 'phone', 'pernr', 'is_active']));
                $copiedTeacherIds[$teacher->id] = $copy->id;
            }

            foreach ($source->sessionTeacherConstraints as $constraint) {
                if (! isset($copiedTeacherIds[$constraint->teacher_id])) {
                    continue;
                }

                SessionTeacherConstraint::create([
                    'exam_session_id' => $newSession->id,
                    'teacher_id' => $copiedTeacherIds[$constraint->teacher_id],
                    'is_excluded' => $constraint->is_excluded,
                    'min_duties' => $constraint->min_duties,
                    'max_duties' => $constraint->max_duties,
                    'unavailable_days' => $constraint->unavailable_days,
                ]);
            }

            return $newSession;
        });

        ActivityLog::record($newSession, 'session.duplicated', "Created from \"{$source->name}\" (rooms, teachers and teacher constraints copied).");

        $this->redirect(route('sessions.show', $newSession), navigate: true);
    }

    public function cancelDuplicate(): void
    {
        $this->reset(['duplicatingId', 'duplicateName', 'duplicateStartDate', 'duplicateEndDate']);
    }

    /**
     * Every child table (rooms, teachers, students, subjects, teacher
     * constraints, time slots, enrollments, generated seating/duties)
     * cascades on delete at the
     * database level, so this can never leave orphaned rows or fail with
     * a foreign-key error. A finalized session is the permanent
     * historical record, so it's excluded the same way every other
     * mutating action on it is (see GuardsFinalizedSession) — unlock it
     * first if it genuinely needs to go.
     */
    public function deleteSession(int $sessionId): void
    {
        $this->authorize('manage_sessions');

        $session = ExamSession::findOrFail($sessionId);

        if ($session->isFinalized()) {
            session()->flash('error', 'Finalized sessions are the permanent historical record and can\'t be deleted — unlock it first if it really needs to go.');

            return;
        }

        // report_files rows cascade with the session, but the generated
        // Excel/PDF files themselves (see ReportFileCache) live on disk
        // and aren't touched by that — clean them up here so a deleted
        // session doesn't leave orphaned reports behind.
        Storage::disk('local')->deleteDirectory("reports/{$session->id}");

        $session->delete();
        session()->flash('status', 'Session deleted.');
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.sessions.index', [
            'sessions' => ExamSession::orderByDesc('start_date')->get(),
        ]);
    }
}
