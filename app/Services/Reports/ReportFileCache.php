<?php

namespace App\Services\Reports;

use App\Models\ExamSession;
use App\Models\ReportFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Generate-once-and-serve caching for report downloads: a large session's
 * PDF/Excel report can take long enough to build that regenerating it on
 * every single download risks a timeout. The first request for a given
 * report + exact filter combination builds it and saves it to disk;
 * every request after that serves the saved file until an admin
 * explicitly regenerates it (see ReportDownloads::regenerate()).
 */
class ReportFileCache
{
    private const DISK = 'local';

    private const DIR = 'reports';

    /**
     * Returns the cached file for this report + filters if one exists on
     * disk, generating it via $write first if not. $write receives the
     * disk-relative path it must save the file to (on the "local" disk).
     *
     * @param  array<string, mixed>  $filters
     */
    public function remember(ExamSession $session, string $reportKey, array $filters, callable $write): ReportFile
    {
        return $this->find($session, $reportKey, $filters)
            ?? $this->generate($session, $reportKey, $filters, $write);
    }

    /**
     * Deletes any cached file for this report + filters, then generates a
     * fresh one immediately — the explicit "Regenerate" action.
     *
     * @param  array<string, mixed>  $filters
     */
    public function regenerate(ExamSession $session, string $reportKey, array $filters, callable $write): ReportFile
    {
        $this->forget($session, $reportKey, $filters);

        return $this->generate($session, $reportKey, $filters, $write);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function find(ExamSession $session, string $reportKey, array $filters): ?ReportFile
    {
        $record = $this->query($session, $reportKey, $filters)->first();

        if (! $record) {
            return null;
        }

        if (Storage::disk(self::DISK)->exists($record->disk_path)) {
            return $record;
        }

        // The DB record survived but the file itself is gone (e.g. the
        // storage directory was cleared by hand) — drop the stale row so
        // the next remember() regenerates cleanly instead of tracking a
        // file that no longer exists.
        $record->delete();

        return null;
    }

    /**
     * The most recent generated_at across any of the given report keys
     * for this exact filter combination, or null if none of them are
     * cached — used to show "generated 2 hours ago" / "not yet
     * generated" next to a report's download buttons without triggering
     * a build.
     *
     * @param  string[]  $reportKeys
     * @param  array<string, mixed>  $filters
     */
    public function generatedAt(ExamSession $session, array $reportKeys, array $filters): ?Carbon
    {
        $max = ReportFile::where('exam_session_id', $session->id)
            ->whereIn('report_key', $reportKeys)
            ->where('filters_hash', $this->hash($filters))
            ->max('generated_at');

        return $max ? Carbon::parse($max) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function forget(ExamSession $session, string $reportKey, array $filters): void
    {
        $record = $this->query($session, $reportKey, $filters)->first();

        if (! $record) {
            return;
        }

        Storage::disk(self::DISK)->delete($record->disk_path);
        $record->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function generate(ExamSession $session, string $reportKey, array $filters, callable $write): ReportFile
    {
        $hash = $this->hash($filters);
        $relativePath = self::DIR."/{$session->id}/".pathinfo($reportKey, PATHINFO_FILENAME)."-{$hash}.".pathinfo($reportKey, PATHINFO_EXTENSION);

        Storage::disk(self::DISK)->makeDirectory(self::DIR."/{$session->id}");

        $write($relativePath);

        return ReportFile::updateOrCreate(
            ['exam_session_id' => $session->id, 'report_key' => $reportKey, 'filters_hash' => $hash],
            ['filters' => $filters, 'disk_path' => $relativePath, 'generated_at' => now()]
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(ExamSession $session, string $reportKey, array $filters)
    {
        return ReportFile::where('exam_session_id', $session->id)
            ->where('report_key', $reportKey)
            ->where('filters_hash', $this->hash($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hash(array $filters): string
    {
        ksort($filters);

        return md5(json_encode($filters));
    }
}
