<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\ConflictGraphBuilder;
use PHPUnit\Framework\TestCase;

class ConflictGraphBuilderTest extends TestCase
{
    /**
     * A plain stand-in for an Enrollment row — the builder only reads
     * ->student_id/->subject_id, so a real (DB-backed) Eloquent model
     * isn't needed to exercise the algorithm in a pure unit test.
     */
    private function enrollment(int $studentId, int $subjectId): object
    {
        return (object) ['student_id' => $studentId, 'subject_id' => $subjectId];
    }

    public function test_builds_edges_for_every_pair_a_student_shares(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 10),
            $this->enrollment(1, 20),
            $this->enrollment(1, 30),
        ]);

        $graph = (new ConflictGraphBuilder)->build($enrollments);

        $this->assertSame(1, $graph[10][20]);
        $this->assertSame(1, $graph[20][10]);
        $this->assertSame(1, $graph[10][30]);
        $this->assertSame(1, $graph[20][30]);
    }

    public function test_weight_accumulates_across_multiple_students(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 10),
            $this->enrollment(1, 20),
            $this->enrollment(2, 10),
            $this->enrollment(2, 20),
            $this->enrollment(3, 10),
            $this->enrollment(3, 20),
        ]);

        $graph = (new ConflictGraphBuilder)->build($enrollments);

        $this->assertSame(3, $graph[10][20]);
    }

    public function test_a_student_taking_only_one_subject_creates_no_edges(): void
    {
        $enrollments = collect([$this->enrollment(1, 10)]);

        $graph = (new ConflictGraphBuilder)->build($enrollments);

        $this->assertSame([], $graph);
    }

    public function test_duplicate_subject_for_the_same_student_does_not_self_link(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 10),
            $this->enrollment(1, 10),
        ]);

        $graph = (new ConflictGraphBuilder)->build($enrollments);

        $this->assertSame([], $graph);
    }
}
