<?php

namespace App\Services\Generation;

/**
 * A subject_slot_assignments.conflict_note can be written by either
 * TimetableGenerator's main pass or GenerationConstraints::updatePin()'s
 * recordClashNoteForSameDay(), and both use the same three phrasings.
 * Shared here so the two places that need to tell them apart (Capacity
 * Check's requirement table and the Unavoidable Clashes/Alerts lists)
 * can't drift out of sync.
 */
final class ConflictNoteClassifier
{
    /**
     * True for a genuine, blocking problem: an exact-same-slot
     * double-booking, or a subject that doesn't fit alone in any slot's
     * room capacity. False only for a note that is purely an
     * informational same-day (different-slot) pairing.
     *
     * A single note can combine a same-day phrase with a real-clash
     * phrase (recordClashNoteForSameDay() can append both to one
     * subject), so "mentions same day" alone isn't a safe alert test —
     * a real-clash phrase always wins.
     */
    public static function isBlockingClash(string $note): bool
    {
        return str_contains($note, 'exact same time slot')
            || str_contains($note, 'room capacity left')
            || ! str_contains($note, 'on the same day');
    }
}
