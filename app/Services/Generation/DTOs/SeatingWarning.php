<?php

namespace App\Services\Generation\DTOs;

final class SeatingWarning
{
    /**
     * @param  string  $type  'unseated' (no room capacity left — this student
     *                        has no seat at all) or 'adjacency' (seated, but
     *                        next to another student of the same subject
     *                        because avoidance was impossible)
     */
    public function __construct(
        public readonly int $enrollmentId,
        public readonly string $message,
        public readonly string $type = 'unseated',
    ) {
    }
}
