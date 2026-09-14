<?php

namespace App\Services\Generation\Strategies;

use App\Services\Generation\DTOs\SeatingResult;
use Illuminate\Support\Collection;

interface SeatingStrategy
{
    /**
     * @param  Collection<int, object{id: int, subject_id: int, section: string}>  $enrollments  already sorted in the desired seating order (by roll number)
     * @param  array<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array<int, array{row: int, column: int, subject_id: int}>}>  $rooms  candidate rooms, sorted largest-capacity first
     */
    public function allocate(Collection $enrollments, array $rooms): SeatingResult;
}
