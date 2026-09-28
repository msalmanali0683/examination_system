<?php

namespace App\Services\Reports;

use App\Models\ExamSession;
use App\Models\ReportFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generate-once-and-serve caching for report downloads: a large session's
 * PDF/Excel report can take long enough to build that generating it inline
 * in the HTTP request risks a web server/browser timeout. The actual build
 * now always runs on the queue (see App\Jobs\GenerateReportFile) — this
 * class only tracks the "queued -> processing -> ready|failed" placeholder
 * row (enqueue()/markProcessing()/markFailed()) and, once a build has
 * actually run, the generate-if-missing/regenerate mechanics the job calls
 * into via ReportFileGenerator.
 */
class ReportFileCache
{
    private const DISK = 'local';

    /**
     * Every table whose rows show up in some report. A cached file is only as good as the data it was built
     * from, so each table contributes its row count, id sum and newest update time to the stamp.
     */
    private const STAMPED_TABLES = ['time_slots', 'subject_slot_assignments', 'seat_assignments', 'duty_assignments', 'enrollments', 'rooms', 'teachers', 'students', 'subjects'];

    private const DIR = 'reports';

    /**
     * Returns the cached file for this report + filters if one exists on
     * disk, generating it via $write first if not. $write receives the
     * disk-relative path it must save the file to (on the "local" disk).
     * Only ever called from inside the queue job — never from an HTTP
     * request — since $write can take well over a minute for a large
     * session.
     *
     * @param  array<string, mixed>  $filters
     */
    public function remember(ExamSession $session, string $reportKey, array $filters, callable $write): ReportFile
    {
        return $this->find($session, $reportKey, $filters)
            ?? $this->generate($session, $reportKey, $filters, $write);
    }

    /**
     * Creates (or reuses) the "queued" placeholder row an HTTP request can
     * check for instead of building the report itself. Returns the row
     * plus whether the caller should actually dispatch a job: false when a
     * build for this exact report + filters is already queued/processing
     * (don't pile up duplicate jobs), or already ready on disk and
     * $forceFresh wasn't requested. $forceFresh is used by the explicit
     * "Regenerate" action, which should always kick off a fresh build even
     * if a ready copy already exists.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ReportFile, 1: bool}
     */
    public function enqueue(ExamSession $session, string $reportKey, array $filters, bool $forceFresh = false): array
    {
        $hash = $this->hash($filters);

        return DB::transaction(function () use ($session, $reportKey, $filters, $hash, $forceFresh) {
            $record = $this->query($session, $reportKey, $filters)->lockForUpdate()->first();

            if ($record && in_array($record->status, [ReportFile::STATUS_QUEUED, ReportFile::STATUS_PROCESSING], true)) {
                return [$record, false];
            }

            if (! $forceFresh && $record && $record->status === ReportFile::STATUS_READY && Storage::disk(self::DISK)->exists($record->disk_path) && ! $this->isStale($record, $session)) {
                return [$record, false];
            }

            $record = ReportFile::updateOrCreate(
                ['exam_session_id' => $session->id, 'report_key' => $reportKey, 'filters_hash' => $hash],
                ['filters' => $filters, 'status' => ReportFile::STATUS_QUEUED, 'error' => null, 'disk_path' => null, 'generated_at' => null, 'data_stamp' => null]
            );

            return [$record, true];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function markProcessing(ExamSession $session, string $reportKey, array $filters): void
    {
        $this->query($session, $reportKey, $filters)->update(['status' => ReportFile::STATUS_PROCESSING]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function markFailed(ExamSession $session, string $reportKey, array $filters, string $message): void
    {
        $this->query($session, $reportKey, $filters)->update([
            'status' => ReportFile::STATUS_FAILED,
            'error' => Str::limit($message, 500),
        ]);
    }

    /**
     * Every tracked row for these report keys + filters (queued,
     * processing, ready or failed) — the basis for the status line next to
     * a report's download buttons without triggering a build.
     *
     * @param  string[]  $reportKeys
     * @param  array<string, mixed>  $filters
     */
    public function statuses(ExamSession $session, array $reportKeys, array $filters): Collection
    {
        $stamp = $this->currentStamp($session);

        return ReportFile::where('exam_session_id', $session->id)
            ->whereIn('report_key', $reportKeys)
            ->where('filters_hash', $this->hash($filters))
            ->get()
            ->each(fn (ReportFile $row) => $row->setAttribute('is_stale', $row->status === ReportFile::STATUS_READY && $row->data_stamp !== $stamp));
    }

    /**
     * A fingerprint of everything a report is built from: the details printed in its header plus, for each
     * table it draws on, how many rows the session has, their id sum and the newest update. Moving one seat,
     * reassigning one duty, renaming a room or flipping the session to "Final" all change it; merely finalizing
     * or unlocking the session does not, since no report prints that.
     */
    public function currentStamp(ExamSession $session): string
    {
        $parts = [DB::table('exam_sessions')->where('id', $session->id)->first(['name', 'department_name', 'report_status', 'report_version', 'start_date', 'end_date'])];

        foreach (self::STAMPED_TABLES as $table) {
            $checksum = $this->contentChecksum($table);
            $row = DB::table($table)->where('exam_session_id', $session->id)
                ->selectRaw('count(*) as c, coalesce(sum(id), 0) as s, max(updated_at) as m, '.$checksum.' as x')
                ->first();
            $parts[] = [$table, (int) $row->c, (string) $row->s, $row->m, (string) $row->x];
        }

        return md5(json_encode($parts));
    }

    /**
     * updated_at only has one-second resolution, so two changes inside the same second could leave it — and the
     * row count and id sum — unchanged. For the tables where WHO sits WHERE is the whole report, a sum over the
     * columns that carry that (multiplied together, so swapping two students' seats or two teachers' rooms still
     * changes it) closes that gap.
     */
    private function contentChecksum(string $table): string
    {
        $g = DB::connection()->getQueryGrammar();
        $col = fn (string $name) => $g->wrap($name);

        $expression = match ($table) {
            'seat_assignments' => $col('enrollment_id').' * ('.$col('row_number').' * 1000 + '.$col('column_number').') + '.$col('room_id').' * 17 + '.$col('time_slot_id').' * 13',
            'duty_assignments' => $col('teacher_id').' * ('.$col('room_id').' * 1000 + '.$col('time_slot_id').')',
            'subject_slot_assignments' => $col('subject_id').' * (coalesce('.$col('time_slot_id').', 0) + 1) + '.$col('is_excluded').' * 7',
            'enrollments' => $col('student_id').' * 7 + '.$col('subject_id').' * 13 + coalesce('.$col('teacher_id').', 0) * 17',
            default => null,
        };

        return $expression === null ? '0' : 'coalesce(sum('.$expression.'), 0)';
    }

    private function isStale(ReportFile $record, ExamSession $session): bool
    {
        return $record->data_stamp === null || $record->data_stamp !== $this->currentStamp($session);
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

        if (! $record || $record->status !== ReportFile::STATUS_READY) {
            return null;
        }

        if (Storage::disk(self::DISK)->exists($record->disk_path)) {
            // The schedule changed after this file was built: serving it would hand out an outdated report.
            return $this->isStale($record, $session) ? null : $record;
        }

        // The DB record survived but the file itself is gone (e.g. the
        // storage directory was cleared by hand) — drop the stale row so
        // the next remember() regenerates cleanly instead of tracking a
        // file that no longer exists.
        $record->delete();

        return null;
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

        // A "queued"/"processing" placeholder row (see enqueue()) has no
        // disk_path yet — nothing to delete from disk in that case.
        if ($record->disk_path) {
            Storage::disk(self::DISK)->delete($record->disk_path);
        }

        $record->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function generate(ExamSession $session, string $reportKey, array $filters, callable $write): ReportFile
    {
        $hash = $this->hash($filters);
        $relativePath = self::DIR."/{$session->id}/".pathinfo($reportKey, PATHINFO_FILENAME)."-{$hash}.".pathinfo($reportKey, PATHINFO_EXTENSION);
        $stamp = $this->currentStamp($session);

        Storage::disk(self::DISK)->makeDirectory(self::DIR."/{$session->id}");

        $write($relativePath);

        return ReportFile::updateOrCreate(
            ['exam_session_id' => $session->id, 'report_key' => $reportKey, 'filters_hash' => $hash],
            ['filters' => $filters, 'data_stamp' => $stamp, 'disk_path' => $relativePath, 'generated_at' => now(), 'status' => ReportFile::STATUS_READY, 'error' => null]
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
