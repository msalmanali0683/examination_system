<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use Livewire\Component;

class TeacherConstraints extends Component
{
    public ExamSession $examSession;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    public function toggleExcluded(int $teacherId): void
    {
        $constraint = $this->constraintFor($teacherId);
        $this->apply($constraint, ['is_excluded' => ! $constraint->is_excluded]);
    }

    public function updateMinDuties(int $teacherId, string $value): void
    {
        $this->apply($this->constraintFor($teacherId), [
            'min_duties' => $value === '' ? null : max(0, (int) $value),
        ]);
    }

    public function updateMaxDuties(int $teacherId, string $value): void
    {
        $this->apply($this->constraintFor($teacherId), [
            'max_duties' => $value === '' ? null : max(0, (int) $value),
        ]);
    }

    private function constraintFor(int $teacherId): SessionTeacherConstraint
    {
        $this->authorize('manage_sessions');

        return SessionTeacherConstraint::firstOrNew([
            'exam_session_id' => $this->examSession->id,
            'teacher_id' => $teacherId,
        ]);
    }

    /**
     * Save the constraint, or delete it if it no longer differs from the
     * session-wide defaults (mirrors how per-user permission overrides work).
     */
    private function apply(SessionTeacherConstraint $constraint, array $attributes): void
    {
        $constraint->fill($attributes);

        if (! $constraint->is_excluded && $constraint->min_duties === null && $constraint->max_duties === null) {
            if ($constraint->exists) {
                $constraint->delete();
            }

            return;
        }

        $constraint->save();
    }

    public function render()
    {
        return view('livewire.sessions.teacher-constraints', [
            'teachers' => Teacher::where('is_active', true)->orderBy('name')->get(),
            'constraints' => $this->examSession->sessionTeacherConstraints()->get()->keyBy('teacher_id'),
        ]);
    }
}
