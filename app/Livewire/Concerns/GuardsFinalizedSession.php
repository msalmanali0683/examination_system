<?php

namespace App\Livewire\Concerns;

use App\Models\ExamSession;

/**
 * Shared "is this session read-only" guard for every Livewire component
 * that mutates session-scoped data. A finalized session is locked: nothing
 * that changes rooms, teachers, slots, enrollments, or generated
 * seats/duties should be allowed until it's unlocked again from the
 * session page.
 */
trait GuardsFinalizedSession
{
    protected function blockedByFinalization(ExamSession $session): bool
    {
        if ($session->isFinalized()) {
            session()->flash('error', 'This session is finalized and read-only. Unlock it from the session page to make changes.');

            return true;
        }

        return false;
    }
}
