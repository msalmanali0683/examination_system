<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TeacherConstraints extends Component
{
    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    public ExamSession $examSession;

    public string $bulkMinDuties = '';

    public string $bulkMaxDuties = '';

    public function days(): array
    {
        return self::DAYS;
    }

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

    /**
     * Sets min/max duties for every active teacher in this session at once.
     * An empty field means "leave that field at the config default" for
     * everyone, same as clearing an individual teacher's field — it does
     * not skip teachers, it resets them.
     */
    public function applyBulkDuties(): void
    {
        $this->authorize('manage_sessions');

        $min = $this->bulkMinDuties === '' ? null : max(0, (int) $this->bulkMinDuties);
        $max = $this->bulkMaxDuties === '' ? null : max(0, (int) $this->bulkMaxDuties);

        if ($min !== null && $max !== null && $min > $max) {
            session()->flash('error', 'Min duties cannot be greater than max duties.');

            return;
        }

        DB::transaction(function () use ($min, $max) {
            foreach (Teacher::where('is_active', true)->pluck('id') as $teacherId) {
                $this->apply($this->constraintFor($teacherId), [
                    'min_duties' => $min,
                    'max_duties' => $max,
                ]);
            }
        });

        session()->flash('status', 'Min/max duties updated for every teacher.');
    }

    /**
     * $day is an ISO weekday number (1=Monday .. 6=Saturday).
     */
    public function toggleDayAvailable(int $teacherId, int $day): void
    {
        $constraint = $this->constraintFor($teacherId);
        $unavailable = $constraint->unavailable_days ?? [];

        $unavailable = in_array($day, $unavailable, true)
            ? array_values(array_diff($unavailable, [$day]))
            : array_values([...$unavailable, $day]);

        $this->apply($constraint, ['unavailable_days' => $unavailable ?: null]);
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

        if (! $constraint->is_excluded
            && $constraint->min_duties === null
            && $constraint->max_duties === null
            && empty($constraint->unavailable_days)) {
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
