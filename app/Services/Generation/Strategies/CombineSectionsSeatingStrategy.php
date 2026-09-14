<?php

namespace App\Services\Generation\Strategies;

use App\Services\Generation\DTOs\SeatingResult;
use Illuminate\Support\Collection;

/**
 * Same room-exclusivity rule as Strict, but groups by subject only —
 * multiple sections of the same subject can share a room together, since
 * there's no cross-subject cheating concern within one subject.
 */
class CombineSectionsSeatingStrategy extends StrictSeatingStrategy
{
    public function allocate(Collection $enrollments, array $rooms): SeatingResult
    {
        $groups = $enrollments
            ->groupBy(fn ($e) => (string) $e->subject_id)
            ->map(fn (Collection $group) => $group->pluck('id')->all());

        return $this->allocateGroups($groups, $rooms);
    }
}
