<?php

namespace App\Services;

class SubjectCodeNormalizer
{
    /**
     * Normalize a raw course code from an SIS export into one opaque,
     * comparable string. Real exports are inconsistent (`CS02115|11`,
     * `CS4710|11`, `CS02203` with no suffix, `CS06301| 11` with a stray
     * space) but the `|NN` suffix does NOT reliably indicate a class
     * section (verified against real data: e.g. `PHY01115|11` is shared
     * across three different sections). So it is never parsed apart —
     * just whitespace-cleaned and used as-is as the subject's identity.
     */
    public static function normalize(string $raw): string
    {
        $value = trim($raw);
        $value = preg_replace('/\s*\|\s*/', '|', $value);

        return preg_replace('/\s+/', ' ', $value);
    }
}
