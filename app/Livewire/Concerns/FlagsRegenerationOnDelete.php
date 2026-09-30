<?php

namespace App\Livewire\Concerns;

use App\Models\DutyAssignment;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;

/**
 * Shared by every "delete a room/teacher/student/subject" component: a delete can cascade away seats,
 * duty assignments or timetable slots, which silently breaks an already-generated plan unless the
 * session is dropped back to draft and the page is told to show the regenerate-in-order banner.
 */
trait FlagsRegenerationOnDelete
{
    /**
     * Set once a delete in this request removed data from a session that already had a generated
     * schedule, so the view can prompt to regenerate the timetable, seating plan and duties.
     */
    public bool $needsRegeneration = false;

    /**
     * Whether this session already has a timetable, seating plan or duty roster. Call this BEFORE the
     * delete that might invalidate it, then pass the result to markIfHadSchedule() after the delete.
     */
    protected function sessionHasSchedule(): bool
    {
        return SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)->whereNotNull('time_slot_id')->exists()
            || SeatAssignment::where('exam_session_id', $this->examSession->id)->exists()
            || DutyAssignment::where('exam_session_id', $this->examSession->id)->exists();
    }

    /**
     * Drops the session back to draft and flags the banner when $hadSchedule is true.
     */
    protected function markIfHadSchedule(bool $hadSchedule): void
    {
        if (! $hadSchedule) {
            return;
        }

        if ($this->examSession->status === 'generated') {
            $this->examSession->update(['status' => 'draft']);
        }

        $this->needsRegeneration = true;
    }
}
