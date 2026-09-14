<?php

namespace App\Services\Generation;

use Illuminate\Support\Collection;

class ConflictGraphBuilder
{
    /**
     * Two subjects "conflict" if any student is enrolled in both. Builds an
     * adjacency map weighted by how many students they share, so the
     * generator can process the hardest-to-place subjects first and pick
     * the least-bad slot when a clash-free one doesn't exist.
     *
     * @param  Collection<int, \App\Models\Enrollment>  $enrollments
     * @return array<int, array<int, int>> subject_id => [other_subject_id => shared_student_count]
     */
    public function build(Collection $enrollments): array
    {
        $subjectsByStudent = $enrollments
            ->groupBy('student_id')
            ->map(fn (Collection $rows) => $rows->pluck('subject_id')->unique()->values());

        $graph = [];

        foreach ($subjectsByStudent as $subjectIds) {
            $ids = $subjectIds->all();
            $count = count($ids);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = [$ids[$i], $ids[$j]];
                    $graph[$a][$b] = ($graph[$a][$b] ?? 0) + 1;
                    $graph[$b][$a] = ($graph[$b][$a] ?? 0) + 1;
                }
            }
        }

        return $graph;
    }
}
