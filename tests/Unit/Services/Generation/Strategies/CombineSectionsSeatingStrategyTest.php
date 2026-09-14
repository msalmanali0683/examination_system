<?php

namespace Tests\Unit\Services\Generation\Strategies;

use App\Services\Generation\Strategies\CombineSectionsSeatingStrategy;
use PHPUnit\Framework\TestCase;

class CombineSectionsSeatingStrategyTest extends TestCase
{
    private function enrollment(int $id, int $subjectId, string $section): object
    {
        return (object) ['id' => $id, 'subject_id' => $subjectId, 'section' => $section];
    }

    private function room(int $roomId, int $rows, int $columns): array
    {
        return ['room_id' => $roomId, 'rows' => $rows, 'columns' => $columns, 'capacity' => $rows * $columns, 'occupied' => []];
    }

    public function test_different_sections_of_the_same_subject_share_a_room(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 100, 'A'),
            $this->enrollment(2, 100, 'B'),
        ]);

        $result = (new CombineSectionsSeatingStrategy)->allocate($enrollments, [$this->room(1, 5, 5)]);

        $roomOf = collect($result->placements)->pluck('roomId', 'enrollmentId');
        $this->assertSame($roomOf[1], $roomOf[2]);
    }

    public function test_different_subjects_still_never_share_a_room(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 100, 'A'),
            $this->enrollment(2, 200, 'A'),
        ]);

        $result = (new CombineSectionsSeatingStrategy)->allocate($enrollments, [
            $this->room(1, 5, 5),
            $this->room(2, 5, 5),
        ]);

        $roomOf = collect($result->placements)->pluck('roomId', 'enrollmentId');
        $this->assertNotSame($roomOf[1], $roomOf[2]);
    }
}
