<?php

namespace App\Services\Generation\DTOs;

final class SeatPlacement
{
    public function __construct(
        public readonly int $enrollmentId,
        public readonly int $roomId,
        public readonly int $row,
        public readonly int $column,
    ) {
    }
}
