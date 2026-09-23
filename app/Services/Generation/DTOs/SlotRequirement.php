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
        public readonly bool $hasUnresolvedClash = false,
        /** @var string[] */
        public readonly array $clashDetails = [],
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
     * Deliberately excludes teachersShortfall(): seating itself doesn't
     * need teachers at all, only rooms and students. Duty assignment is
     * the stage that actually needs teachers, and it already runs after
     * seating and copes with a shortfall gracefully (whatever teachers
     * exist get assigned, the rest surfaces as a warning) rather than
     * refusing to run — seating shouldn't hold itself to a stricter
     * standard than the stage that actually depends on the number. The
     * teachers column stays fully visible for planning; it just no
     * longer blocks generation.
     */
    public function isMet(): bool
    {
        return ! $this->hasUnseatedStudents && $this->roomsShortfall() === 0 && ! $this->hasUnresolvedClash;
    }
}
