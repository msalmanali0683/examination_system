<?php

namespace App\Services\Generation\DTOs;

use Illuminate\Support\Collection;

final class SeatingResult
{
    /**
     * @param  SeatPlacement[]  $placements
     * @param  Collection<int, SeatingWarning>  $warnings
     */
    public function __construct(
        public readonly array $placements,
        public readonly Collection $warnings,
    ) {
    }
}
