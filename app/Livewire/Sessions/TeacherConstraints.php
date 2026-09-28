<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TeacherConstraints extends Component
{
    use GuardsFinalizedSession;

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
        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $constraint = $this->constraintFor($teacherId);
        $this->apply($constraint, ['is_excluded' => ! $constraint->is_excluded]);
    }

    public function updateMinDuties(int $teacherId, string $value): void
    {
        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $constraint = $this->constraintFor($teacherId);
        $min = $value === '' ? null : max(0, (int) $value);

        if ($this->contradictsMax($min, $constraint->max_duties)) {
            session()->flash('error', "Min duties ({$min}) cannot be greater than this teacher's max ({$constraint->max_duties}).");

            return;
        }

        $this->apply($constraint, ['min_duties' => $min]);
    }

    public function updateMaxDuties(int $teacherId, string $value): void
    {
        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $constraint = $this->constraintFor($teacherId);
        $max = $value === '' ? null : max(0, (int) $value);

        if ($this->contradictsMax($constraint->min_duties, $max)) {
            session()->flash('error', "Max duties ({$max}) cannot be less than this teacher's min ({$constraint->min_duties}).");

            return;
        }

        $this->apply($constraint, ['max_duties' => $max]);
    }

    /**
     * Only an explicit contradiction (both limits set by hand, min above max) is refused; a max below the
     * session-wide default minimum is a legitimate "this teacher only does one duty".
     */
    private function contradictsMax(?int $min, ?int $max): bool
    {
        return $min !== null && $max !== null && $min > $max;
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

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $min = $this->bulkMinDuties === '' ? null : max(0, (int) $this->bulkMinDuties);
        $max = $this->bulkMaxDuties === '' ? null : max(0, (int) $this->bulkMaxDuties);

        if ($min !== null && $max !== null && $min > $max) {
            session()->flash('error', 'Min duties cannot be greater than max duties.');

            return;
        }

        DB::transaction(function () use ($min, $max) {
            foreach ($this->examSession->teachers()->where('is_active', true)->pluck('id') as $teacherId) {
                $this->apply($this->constraintFor($teacherId, verified: true), [
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
        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $constraint = $this->constraintFor($teacherId);
        $unavailable = $constraint->unavailable_days ?? [];

        $unavailable = in_array($day, $unavailable, true)
            ? array_values(array_diff($unavailable, [$day]))
            : array_values([...$unavailable, $day]);

        $this->apply($constraint, ['unavailable_days' => $unavailable ?: null]);
    }

    /**
     * Marks every active teacher available (or unavailable) on $day at
     * once — the bulk equivalent of clicking each teacher's checkbox for
     * that day individually.
     */
    public function toggleDayForAll(int $day, bool $available): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        DB::transaction(function () use ($day, $available) {
            foreach ($this->examSession->teachers()->where('is_active', true)->pluck('id') as $teacherId) {
                $constraint = $this->constraintFor($teacherId, verified: true);
                $unavailable = $constraint->unavailable_days ?? [];

                $unavailable = $available
                    ? array_values(array_diff($unavailable, [$day]))
                    : array_values(array_unique([...$unavailable, $day]));

                $this->apply($constraint, ['unavailable_days' => $unavailable ?: null]);
            }
        });

        session()->flash('status', 'Day availability updated for every teacher.');
    }

    /**
     * $verified is for the bulk actions, which loop over this session's own teachers and so have already
     * established ownership; single-teacher actions take an id from the browser and must check it.
     */
    private function constraintFor(int $teacherId, bool $verified = false): SessionTeacherConstraint
    {
        $this->authorize('manage_sessions');

        abort_unless($verified || $this->examSession->teachers()->whereKey($teacherId)->exists(), 404);

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
        $constraints = $this->examSession->sessionTeacherConstraints()->get()->keyBy('teacher_id');

        // A day reads as "all available" only while no teacher has it
        // marked unavailable — driving the bulk checkbox's checked state.
        $allAvailableByDay = collect(self::DAYS)->keys()->mapWithKeys(
            fn ($day) => [$day => $constraints->every(fn ($c) => ! in_array($day, $c->unavailable_days ?? [], true))]
        );

        return view('livewire.sessions.teacher-constraints', [
            'teachers' => $this->examSession->teachers()->where('is_active', true)->withCount('unavailableSlots')->orderBy('name')->get(),
            'constraints' => $constraints,
            'allAvailableByDay' => $allAvailableByDay,
        ]);
    }
}
