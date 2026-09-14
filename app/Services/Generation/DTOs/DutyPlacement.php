<?php

namespace App\Services\Generation\DTOs;

final class DutyPlacement
{
    public function __construct(
        public readonly int $teacherId,
        public readonly int $timeSlotId,
        public readonly int $roomId,
    ) {
    }
}
