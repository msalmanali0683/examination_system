<?php

namespace Tests\Unit\Services\Generation\Strategies;

use App\Services\Generation\Strategies\MixedSeatingStrategy;
use PHPUnit\Framework\TestCase;

class MixedSeatingStrategyTest extends TestCase
{
    private function enrollment(int $id, int $subjectId): object
    {
        return (object) ['id' => $id, 'subject_id' => $subjectId, 'section' => 'A'];
    }

    private function room(int $roomId, int $rows, int $columns, array $occupied = []): array
    {
        return ['room_id' => $roomId, 'rows' => $rows, 'columns' => $columns, 'capacity' => $rows * $columns, 'occupied' => $occupied];
    }

    public function test_two_subjects_with_room_to_spare_are_never_seated_adjacently(): void
    {
        // 3 of subject 100, 3 of subject 200, room is 3 rows x 2 columns —
        // plenty of room to alternate subjects down each column.
        $enrollments = collect([
            $this->enrollment(1, 100),
            $this->enrollment(2, 100),
            $this->enrollment(3, 100),
            $this->enrollment(4, 200),
            $this->enrollment(5, 200),
            $this->enrollment(6, 200),
        ]);

        $result = (new MixedSeatingStrategy)->allocate($enrollments, [$this->room(1, 3, 2)]);

        $this->assertCount(6, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());

        $bySeat = [];
        foreach ($result->placements as $p) {
            $bySeat["{$p->row}:{$p->column}"] = $enrollments->firstWhere('id', $p->enrollmentId)->subject_id;
        }

        // No two adjacent seats (up/down/left/right) share a subject.
        foreach ($bySeat as $key => $subjectId) {
            [$row, $col] = array_map('intval', explode(':', $key));
            foreach ([[$row - 1, $col], [$row + 1, $col], [$row, $col - 1], [$row, $col + 1]] as [$r, $c]) {
                if (isset($bySeat["{$r}:{$c}"])) {
                    $this->assertNotSame($subjectId, $bySeat["{$r}:{$c}"], "Seats ({$row},{$col}) and ({$r},{$c}) share subject {$subjectId}");
                }
            }
        }
    }

    public function test_impossible_adjacency_avoidance_is_reported_not_silently_dropped(): void
    {
        // A single 1x2 room with only one subject enrolled leaves no way to
        // avoid same-subject adjacency between the two seats — but this is
        // a degenerate case (only one subject exists at all), included for
        // completeness of the warning path rather than as a realistic case.
        $enrollments = collect([
            $this->enrollment(1, 100),
            $this->enrollment(2, 100),
        ]);

        $result = (new MixedSeatingStrategy)->allocate($enrollments, [$this->room(1, 2, 1)]);

        $this->assertCount(2, $result->placements);
        $this->assertTrue($result->warnings->isNotEmpty());
    }

    public function test_locked_neighbor_seat_is_respected_for_adjacency(): void
    {
        // Seat (1,1) is already locked to subject 100. The only other
        // candidate is also subject 100, so the adjacent seat (2,1) can't
        // avoid it — must be reported.
        $enrollments = collect([$this->enrollment(1, 100)]);

        $result = (new MixedSeatingStrategy)->allocate($enrollments, [
            $this->room(1, 2, 1, occupied: [['row' => 1, 'column' => 1, 'subject_id' => 100]]),
        ]);

        $this->assertCount(1, $result->placements);
        $this->assertSame(2, $result->placements[0]->row);
        $this->assertTrue($result->warnings->isNotEmpty());
    }

    public function test_no_capacity_left_is_reported_as_a_warning(): void
    {
        $enrollments = collect([$this->enrollment(1, 100), $this->enrollment(2, 200)]);

        $result = (new MixedSeatingStrategy)->allocate($enrollments, [$this->room(1, 1, 1)]);

        $this->assertCount(1, $result->placements);
        $this->assertCount(1, $result->warnings);
    }
}
