<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\DutyAllocationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

class DutyBoard extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public ?int $activeSlotId = null;

    /**
     * The teacher currently open in the duty-list modal, as a plain array
     * (teacher name and one entry per duty with its day/time/room/lock
     * state) — null when the modal is closed. Computed fresh on click so
     * it always reflects the session's current duties.
     */
    public ?array $teacherDutyDetails = null;

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

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

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

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $duty = DutyAssignment::where('exam_session_id', $this->examSession->id)->find($dutyAssignmentId);

        if ($duty) {
            $duty->update(['is_locked' => ! $duty->is_locked]);
        }
    }

    /**
     * Handles both the very first generation (nothing assigned yet,
     * shown as the empty state's own button) and every later
     * regeneration (locked duties always left untouched) — one action
     * either way, mirroring SeatingChart::regenerate(). Duties are the
     * last stage of the pipeline (timetable -> seating -> duties), so a
     * successful run marks the session's overall status "generated".
     */
    public function regenerate(): void
    {
        $this->authorize('generate_roster');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        if (! $this->examSession->seatAssignments()->exists()) {
            session()->flash('error', 'Generate seating first — duties are assigned to the rooms actually in use each slot.');

            return;
        }

        $result = (new DutyAllocationService)->generate($this->examSession);

        if ($this->activeSlotId === null) {
            $this->activeSlotId = TimeSlot::where('exam_session_id', $this->examSession->id)
                ->whereHas('dutyAssignments')
                ->orderBy('date')
                ->orderBy('start_time')
                ->value('id');
        }

        $this->examSession->update(['status' => 'generated']);

        ActivityLog::record($this->examSession, 'duties.generated', $result->warnings->isEmpty()
            ? 'Generated duties for every slot.'
            : "Generated duties with {$result->warnings->count()} warning(s).");

        session()->flash(
            $result->warnings->isEmpty() ? 'status' : 'error',
            $result->warnings->isEmpty()
                ? 'Duties generated — locked duties were left untouched.'
                : "Duties generated with {$result->warnings->count()} warning(s) — locked duties were left untouched."
        );
    }

    /**
     * Opens the duty-list modal for a teacher's row in the Duty Fairness
     * table — every duty this teacher has in this session, in order, so
     * the admin can see exactly where a low/high count comes from
     * without leaving the page.
     */
    public function showTeacherDuties(int $teacherId): void
    {
        $this->authorize('manage_sessions');

        $teacher = Teacher::find($teacherId);

        if (! $teacher) {
            return;
        }

        $duties = DutyAssignment::where('duty_assignments.exam_session_id', $this->examSession->id)
            ->where('duty_assignments.teacher_id', $teacherId)
            ->with('room')
            ->join('time_slots', 'time_slots.id', '=', 'duty_assignments.time_slot_id')
            ->orderBy('time_slots.date')
            ->orderBy('time_slots.start_time')
            ->select('duty_assignments.*', 'time_slots.date as slot_date', 'time_slots.start_time as slot_start', 'time_slots.end_time as slot_end')
            ->get();

        $this->teacherDutyDetails = [
            'teacherName' => $teacher->name,
            'duties' => $duties->map(fn ($duty) => [
                'date' => Carbon::parse($duty->slot_date)->format('d M Y'),
                'time' => substr($duty->slot_start, 0, 5).' – '.substr($duty->slot_end, 0, 5),
                'room' => $duty->room->name,
                'locked' => $duty->is_locked,
            ])->all(),
        ];

        $this->dispatch('open-modal', 'teacher-duty-details');
    }

    /**
     * A cheap aggregate (no simulation), safe to compute on every render.
     */
    private function dutyFairness(): Collection
    {
        $counts = DutyAssignment::where('exam_session_id', $this->examSession->id)
            ->selectRaw('teacher_id, count(*) as c')
            ->groupBy('teacher_id')
            ->pluck('c', 'teacher_id');

        $constraints = SessionTeacherConstraint::where('exam_session_id', $this->examSession->id)->get()->keyBy('teacher_id');

        return Teacher::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Teacher $teacher) use ($counts, $constraints) {
                $constraint = $constraints->get($teacher->id);
                $excluded = $constraint?->is_excluded ?? false;
                $min = $constraint?->effectiveMinDuties() ?? config('exam.default_min_duties');
                $max = $constraint?->effectiveMaxDuties() ?? config('exam.default_max_duties');
                $count = $counts->get($teacher->id, 0);

                return (object) [
                    'teacher' => $teacher,
                    'count' => $count,
                    'min' => $min,
                    'max' => $max,
                    'excluded' => $excluded,
                    'met' => $excluded || $count >= $min,
                ];
            });
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
            'dutyFairness' => $this->dutyFairness(),
        ]);
    }
}
