<?php

namespace App\Livewire\Sessions;

use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\DutyAllocationService;
use Livewire\Attributes\Layout;
use Livewire\Component;

class DutyBoard extends Component
{
    public ExamSession $examSession;

    public ?int $activeSlotId = null;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('view_reports');
        $this->examSession = $examSession;

        $this->activeSlotId = TimeSlot::where('exam_session_id', $examSession->id)
            ->whereHas('dutyAssignments')
            ->orderBy('date')
            ->orderBy('start_time')
            ->value('id');
    }

    public function selectSlot(int $slotId): void
    {
        $this->authorize('view_reports');
        $this->activeSlotId = $slotId;
    }

    /**
     * Manual reassignment: swaps the duty to a different teacher and locks
     * it so the next "Generate Duties" run (which only touches unlocked
     * duties) leaves this override alone.
     */
    public function reassignDuty(int $dutyAssignmentId, int $newTeacherId): void
    {
        $this->authorize('edit_assignments');

        $duty = DutyAssignment::where('exam_session_id', $this->examSession->id)->find($dutyAssignmentId);

        if (! $duty) {
            session()->flash('error', 'That duty no longer exists — the board may be out of date.');

            return;
        }

        if ($duty->is_locked) {
            session()->flash('error', 'That duty is locked — unlock it first.');

            return;
        }

        if ($duty->teacher_id === $newTeacherId) {
            return;
        }

        $teacher = Teacher::where('id', $newTeacherId)->where('is_active', true)->first();

        if (! $teacher) {
            session()->flash('error', 'That teacher is not active.');

            return;
        }

        $constraint = SessionTeacherConstraint::where('exam_session_id', $this->examSession->id)
            ->where('teacher_id', $newTeacherId)
            ->first();

        if ($constraint?->is_excluded) {
            session()->flash('error', "{$teacher->name} is excluded from this session.");

            return;
        }

        if ($constraint && ! $constraint->isAvailableOn($duty->timeSlot->date)) {
            session()->flash('error', "{$teacher->name} is marked unavailable on this day.");

            return;
        }

        $alreadyOnDutyThisSlot = DutyAssignment::where('exam_session_id', $this->examSession->id)
            ->where('time_slot_id', $duty->time_slot_id)
            ->where('teacher_id', $newTeacherId)
            ->exists();

        if ($alreadyOnDutyThisSlot) {
            session()->flash('error', "{$teacher->name} is already on duty this slot.");

            return;
        }

        $duty->update(['teacher_id' => $newTeacherId, 'is_locked' => true]);

        session()->flash('status', "Duty reassigned to {$teacher->name} — locked so it won't change on the next regeneration.");
    }

    public function toggleDutyLock(int $dutyAssignmentId): void
    {
        $this->authorize('edit_assignments');

        $duty = DutyAssignment::where('exam_session_id', $this->examSession->id)->find($dutyAssignmentId);

        if ($duty) {
            $duty->update(['is_locked' => ! $duty->is_locked]);
        }
    }

    public function regenerate(): void
    {
        $this->authorize('generate_roster');

        $result = (new DutyAllocationService)->generate($this->examSession);

        session()->flash(
            $result->warnings->isEmpty() ? 'status' : 'error',
            $result->warnings->isEmpty()
                ? 'Duties regenerated — locked duties were left untouched.'
                : "Duties regenerated with {$result->warnings->count()} warning(s) — locked duties were left untouched."
        );
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $slots = TimeSlot::where('exam_session_id', $this->examSession->id)
            ->whereHas('dutyAssignments')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $rooms = collect();

        if ($this->activeSlotId) {
            $slot = $slots->firstWhere('id', $this->activeSlotId);

            $duties = DutyAssignment::where('exam_session_id', $this->examSession->id)
                ->where('time_slot_id', $this->activeSlotId)
                ->with(['teacher', 'room'])
                ->get();

            $teacherIdsThisSlot = $duties->pluck('teacher_id');

            $constraints = SessionTeacherConstraint::where('exam_session_id', $this->examSession->id)
                ->get()
                ->keyBy('teacher_id');

            $candidates = Teacher::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->filter(function (Teacher $teacher) use ($constraints, $slot) {
                    $constraint = $constraints->get($teacher->id);

                    if ($constraint?->is_excluded) {
                        return false;
                    }

                    return ! $constraint || $constraint->isAvailableOn($slot->date);
                });

            $rooms = $duties->groupBy('room_id')->map(function ($roomDuties) use ($candidates, $teacherIdsThisSlot) {
                $room = $roomDuties->first()->room;

                $rows = $roomDuties->map(function (DutyAssignment $duty) use ($candidates, $teacherIdsThisSlot) {
                    $options = $candidates->reject(
                        fn (Teacher $t) => $t->id !== $duty->teacher_id && $teacherIdsThisSlot->contains($t->id)
                    );

                    return [
                        'duty' => $duty,
                        'options' => $options,
                    ];
                });

                return ['room' => $room, 'rows' => $rows];
            })->sortBy(fn ($r) => $r['room']->name)->values();
        }

        return view('livewire.sessions.duty-board', [
            'slots' => $slots,
            'rooms' => $rooms,
        ]);
    }
}
