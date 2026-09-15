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
    ) {
    }

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

    public function teachersShortfall(): int
    {
        return max(0, $this->teachersNeeded - $this->teachersAvailable);
    }

    public function isMet(): bool
    {
        return ! $this->hasUnseatedStudents && $this->roomsShortfall() === 0 && $this->teachersShortfall() === 0 && ! $this->hasUnresolvedClash;
    }
}
