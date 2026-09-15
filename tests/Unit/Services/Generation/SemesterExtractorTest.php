<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\SemesterExtractor;
use PHPUnit\Framework\TestCase;

class SemesterExtractorTest extends TestCase
{
    public function test_from_section_reads_the_leading_number(): void
    {
        $this->assertSame(2, SemesterExtractor::fromSection('BSAI 2A'));
        $this->assertSame(1, SemesterExtractor::fromSection('BSAI 1D'));
        $this->assertNull(SemesterExtractor::fromSection('No Digits Here'));
    }

    public function test_label_joins_every_distinct_semester_touched(): void
    {
        $sections = collect(['BSAI 2A', 'BSAI 2B', 'BSAI 4A']);

        $this->assertSame('2nd/4th', SemesterExtractor::label($sections));
    }

    public function test_dominant_picks_the_semester_with_the_most_students(): void
    {
        // 29 students in semester 2 sections, 1 repeater in a semester 4
        // section — the subject overwhelmingly belongs to semester 2.
        $countBySection = ['BSAI 2A' => 15, 'BSAI 2B' => 14, 'BSAI 4A' => 1];

        $this->assertSame(2, SemesterExtractor::dominant($countBySection));
    }

    public function test_dominant_is_not_fooled_by_a_repeater_into_matching_an_unrelated_subject(): void
    {
        // Regression: a subject nominally in semester 2 with a single
        // repeater from semester 4 was, before dominant(), classified as
        // belonging to BOTH semester 2 and semester 4 (the full distinct
        // set) — so a completely unrelated semester-4 subject looked
        // "same semester" to it and got forced onto a different day for
        // no real reason. dominant() must side with the majority.
        $subjectA = ['BSAI 2A' => 15, 'BSAI 2B' => 14, 'BSAI 4A' => 1]; // mostly semester 2
        $subjectB = ['BSAI 4A' => 30]; // purely semester 4

        $this->assertSame(2, SemesterExtractor::dominant($subjectA));
        $this->assertSame(4, SemesterExtractor::dominant($subjectB));
        $this->assertNotSame(SemesterExtractor::dominant($subjectA), SemesterExtractor::dominant($subjectB));
    }

    public function test_dominant_accepts_a_collection_as_well_as_a_plain_array(): void
    {
        $countBySection = collect(['BSAI 3A' => 10, 'BSAI 3B' => 5]);

        $this->assertSame(3, SemesterExtractor::dominant($countBySection));
    }

    public function test_dominant_is_null_when_nothing_is_parseable(): void
    {
        $this->assertNull(SemesterExtractor::dominant(['No Digits' => 5]));
        $this->assertNull(SemesterExtractor::dominant([]));
    }
}
