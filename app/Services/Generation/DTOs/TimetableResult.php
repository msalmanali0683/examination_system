<?php

namespace App\Services\Generation\DTOs;

use Illuminate\Support\Collection;

final class TimetableResult
{
    /**
     * @param  array<int, int>  $assignments  subject_id => time_slot_id
     * @param  Collection<int, ConflictReportRow>  $conflicts
     */
    public function __construct(
        public readonly array $assignments,
        public readonly Collection $conflicts,
    ) {
    }
}
