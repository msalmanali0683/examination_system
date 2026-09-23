<?php

namespace App\Services\Generation\DTOs;

final class SlotRequirement
{
    public function __construct(
        public readonly int $timeSlotId,
        public readonly string $label,
        public readonly int $studentCount,
        public readonly int $roomsNeeded,
        public readonly int $roomsAvailable,
        public readonly int $teachersNeeded,
        public readonly int $teachersAvailable,
        public readonly bool $hasUnseatedStudents,
        public readonly int $seatsAvailable = 0,
        /**
         * A genuine, blocking problem: either a real double-booking (two
         * subjects sharing a student ended up in the exact same time
         * slot, which is actually impossible for that student to sit),
         * or a subject that doesn't fit alone in any slot's active room
         * capacity. This is what blocks Generate Seating.
         */
        public readonly bool $hasUnresolvedClash = false,
        /** @var string[] */
        public readonly array $clashDetails = [],
        /**
         * Two papers from the same semester sharing a day (but never
         * the same slot) — a student just sits two exams that day, which
         * is inconvenient but not impossible. Seating runs per-slot, so
         * this has no effect on it either way; it's surfaced for
         * planning only and never blocks generation.
         */
        public readonly bool $hasUnresolvedAlert = false,
        /** @var string[] */
        public readonly array $alertDetails = [],
        /**
         * The total number of rooms the whole system has, regardless of
         * session — defaults to "unknown" (never triggers
         * exceedsSystemWideRooms()) for callers that don't have this
         * figure, e.g. SlotCapacitySimulator's hypothetical slots.
         */
        public readonly int $roomsAvailableSystemWide = PHP_INT_MAX,
    ) {}

    /**
     * Raw total capacity across active rooms, compared against the number
     * of students needing seats — a plainer sanity number than the room
     * count above it. Purely informational: isMet() below still goes by
     * the real per-room simulation (roomsShortfall/hasUnseatedStudents),
     * since enough *total* seats doesn't guarantee they're shaped right
     * (see RequirementCalculator's own shortfall-consistency fix) — this
     * number existing alongside a "Short" badge is not a contradiction.
     */
    public function seatsShortfall(): int
    {
        return max(0, $this->studentCount - $this->seatsAvailable);
    }

    public function roomsShortfall(): int
    {
        return max(0, $this->roomsNeeded - $this->roomsAvailable);
    }

    /**
     * True when the shortfall can't be solved by simply activating more
     * of the session's existing rooms — the system doesn't have that
     * many rooms at all, so the fix is a different seating strategy
     * (combine sections/subjects) or fewer clashes landing in this slot,
     * not "activate one more room".
     */
    public function exceedsSystemWideRooms(): bool
    {
        return $this->roomsNeeded > $this->roomsAvailableSystemWide;
    }

    public function teachersShortfall(): int
    {
        return max(0, $this->teachersNeeded - $this->teachersAvailable);
    }

    /**
     * Deliberately excludes teachersShortfall() and hasUnresolvedAlert():
     * neither actually affects whether seating can be generated. Seating
     * only needs rooms and students; a teacher shortfall is a duty-stage
     * concern (which already copes with it via a warning, not a refusal
     * to run), and a same-day (not same-slot) alert has zero effect on
     * a per-slot process either way. Both stay fully visible for
     * planning — they just don't gate this specific check.
     */
    public function isMet(): bool
    {
        return ! $this->hasUnseatedStudents && $this->roomsShortfall() === 0 && ! $this->hasUnresolvedClash;
    }
}
