<?php

namespace App\Services\Generation\DTOs;

final class SeatingWarning
{
    public function __construct(
        public readonly int $enrollmentId,
        public readonly string $message,
    ) {
    }
}
