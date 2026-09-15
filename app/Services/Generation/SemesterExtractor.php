<?php

namespace App\Services\Generation;

use Illuminate\Support\Collection;

/**
 * A section string is "<program> <semester><letter>" (e.g. "BSAI 2A") — the
 * leading number is the semester/year group. Shared by the Pin Subjects to
 * Slots screen (grouping/clash-checking by semester) and report exports
 * (printing a subject's semester), so the parsing rule and its ordinal
 * label always agree between them.
 */
class SemesterExtractor
{
    public static function fromSection(string $section): ?int
    {
        preg_match('/(\d+)/', $section, $m);

        return isset($m[1]) ? (int) $m[1] : null;
    }

    /**
     * @param  Collection<int, string>  $sections
     * @return Collection<int, int> distinct semester numbers, ascending
     */
    public static function fromSections(Collection $sections): Collection
    {
        return $sections
            ->map(fn (string $section) => self::fromSection($section))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * "1st", "2nd", "3rd", "4th", "11th", "21st"...
     */
    public static function ordinal(int $semester): string
    {
        if (in_array($semester % 100, [11, 12, 13], true)) {
            return "{$semester}th";
        }

        return $semester.match ($semester % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * Every distinct semester found across $sections, as ordinal labels
     * joined by "/" (e.g. "2nd" or "2nd/4th" for a subject spanning more
     * than one, such as a repeater's section). Empty when no section
     * yielded a parseable semester.
     *
     * @param  Collection<int, string>  $sections
     */
    public static function label(Collection $sections): string
    {
        return self::fromSections($sections)->map(fn (int $s) => self::ordinal($s))->implode('/');
    }
}
