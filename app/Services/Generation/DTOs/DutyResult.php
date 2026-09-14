<?php

namespace App\Services\Generation\DTOs;

use Illuminate\Support\Collection;

final class DutyResult
{
    /**
     * @param  DutyPlacement[]  $placements
     * @param  Collection<int, DutyWarning>  $warnings
     */
    public function __construct(
        public readonly array $placements,
        public readonly Collection $warnings,
    ) {
    }
}
