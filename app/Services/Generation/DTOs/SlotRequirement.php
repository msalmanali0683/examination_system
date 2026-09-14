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
    ) {
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
        return ! $this->hasUnseatedStudents && $this->roomsShortfall() === 0 && $this->teachersShortfall() === 0;
    }
}
