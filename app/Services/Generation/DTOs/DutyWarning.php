<?php

namespace App\Services\Generation\DTOs;

final class DutyWarning
{
    /**
     * @param  string  $type  'understaffed' (a room/slot couldn't get enough
     *                        eligible invigilators) or 'unmet_minimum' (a
     *                        teacher stayed below their session minimum
     *                        even after rebalancing)
     */
    public function __construct(
        public readonly int $timeSlotId,
        public readonly ?int $roomId,
        public readonly string $message,
        public readonly string $type = 'understaffed',
    ) {
    }
}
