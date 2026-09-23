<?php

namespace App\Services\Reports;

use App\Exports\BatchScheduleExport;
use App\Exports\DutySheetExport;
use App\Exports\FormattedDatesheetExport;
use App\Exports\MasterDatesheetExport;
use App\Exports\SeatingChartExport;
use App\Exports\SimpleDatesheetExport;
use App\Exports\SubjectWiseSeatingExport;
use App\Exports\TeacherAttendanceExport;
use App\Models\ExamSession;
use App\Models\ReportFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The single place that knows how to build each of the 11 report
 * downloads — shared by ReportDownloadController (serve the cached file,
 * building it first if this is the first request for it) and
 * Livewire\Sessions\ReportDownloads::regenerate() (force a fresh build),
 * so both always agree on exactly what a given report + filter
 * combination looks like.
 */
class ReportFileGenerator
{
    private const DISK = 'local';

    public function __construct(private ReportFileCache $cache = new ReportFileCache) {}

    public function seatingChartExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'seating-chart.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new SeatingChartExport($session, $date, $showInvigilators, $timeSlotIds), $path, self::DISK)
        );
    }

    public function seatingChartPdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'seating-chart.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            function ($path) use ($session, $date, $timeSlotIds, $showInvigilators) {
                $charts = (new ReportDataBuilder)->seatingCharts($session, $date, $timeSlotIds);

                Pdf::loadView('reports.seating-chart-pdf', ['charts' => $charts, 'session' => $session, 'showInvigilators' => $showInvigilators])
                    ->setPaper('a4', 'landscape')
                    ->save($path, self::DISK);
            }
        );
    }

    public function datesheetExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'datesheet.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new MasterDatesheetExport($session, $date, $showInvigilators, $timeSlotIds), $path, self::DISK)
        );
    }

    public function datesheetPdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'datesheet.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            function ($path) use ($session, $date, $timeSlotIds, $showInvigilators) {
                $rowsByDate = (new ReportDataBuilder)->datesheetRowsByDate($session, $date, $timeSlotIds);

                Pdf::loadView('reports.master-datesheet-pdf', ['rowsByDate' => $rowsByDate, 'session' => $session, 'showInvigilators' => $showInvigilators])
                    ->setPaper('a4', 'landscape')
                    ->save($path, self::DISK);
            }
        );
    }

    public function formattedDatesheetExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'formatted-datesheet.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new FormattedDatesheetExport($session, $date, $showInvigilators, $timeSlotIds), $path, self::DISK)
        );
    }

    /**
     * showInvigilators is unused here — the Simple Datesheet has no
     * invigilator column — but is still accepted and folded into the
     * cache key filters, matching every other non-duty-roster report, so
     * the shared "Print invigilator names" checkbox produces the same
     * filters shape the enqueue()/regenerate() calls expect.
     */
    public function simpleDatesheetExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'simple-datesheet.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new SimpleDatesheetExport($session, $date, $timeSlotIds), $path, self::DISK)
        );
    }

    public function simpleDatesheetPdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'simple-datesheet.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            function ($path) use ($session, $date, $timeSlotIds) {
                $rowsByDate = (new ReportDataBuilder)->simpleDatesheetRowsByDate($session, $date, $timeSlotIds);

                Pdf::loadView('reports.simple-datesheet-pdf', ['rowsByDate' => $rowsByDate, 'session' => $session])
                    ->setPaper('a4', 'portrait')
                    ->save($path, self::DISK);
            }
        );
    }

    public function dutySheetExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showRoomSubject, bool $force = false): ReportFile
    {
        return $this->run($session, 'duty-roster.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showRoomSubject' => $showRoomSubject]), $force,
            fn ($path) => Excel::store(new DutySheetExport($session, $date, $showRoomSubject, $timeSlotIds), $path, self::DISK)
        );
    }

    public function dutySheetPdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showRoomSubject, bool $force = false): ReportFile
    {
        return $this->run($session, 'duty-roster.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showRoomSubject' => $showRoomSubject]), $force,
            function ($path) use ($session, $date, $timeSlotIds, $showRoomSubject) {
                $teacherGroups = (new ReportDataBuilder)->dutyRowsByTeacher($session, $date, $timeSlotIds);

                Pdf::loadView('reports.duty-sheet-pdf', ['teacherGroups' => $teacherGroups, 'session' => $session, 'showRoomSubject' => $showRoomSubject])
                    ->setPaper('a4', 'portrait')
                    ->save($path, self::DISK);
            }
        );
    }

    public function subjectWiseSeatingExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'subject-wise-seating.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new SubjectWiseSeatingExport($session, $date, $showInvigilators, $timeSlotIds), $path, self::DISK)
        );
    }

    public function subjectWiseSeatingPdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'subject-wise-seating.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            function ($path) use ($session, $date, $timeSlotIds, $showInvigilators) {
                $subjects = (new ReportDataBuilder)->subjectWiseSeatingRows($session, $date, $timeSlotIds);

                Pdf::loadView('reports.subject-wise-seating-pdf', ['subjects' => $subjects, 'session' => $session, 'showInvigilators' => $showInvigilators])
                    ->setPaper('a4', 'portrait')
                    ->save($path, self::DISK);
            }
        );
    }

    public function batchScheduleExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'batch-schedule.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new BatchScheduleExport($session, $date, $showInvigilators, $timeSlotIds), $path, self::DISK)
        );
    }

    public function batchSchedulePdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'batch-schedule.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            function ($path) use ($session, $date, $timeSlotIds, $showInvigilators) {
                $sections = (new ReportDataBuilder)->batchScheduleRows($session, $date, $timeSlotIds);

                Pdf::loadView('reports.batch-schedule-pdf', ['sections' => $sections, 'session' => $session, 'showInvigilators' => $showInvigilators])
                    ->setPaper('a4', 'portrait')
                    ->save($path, self::DISK);
            }
        );
    }

    /**
     * showInvigilators is unused here — the sheet always lists every
     * teacher on duty, it doesn't have anything to hide — but is still
     * accepted and folded into the cache key filters, matching every
     * other report, so the shared "Print invigilator names" checkbox
     * produces the same filters shape the enqueue()/regenerate() calls
     * expect.
     */
    public function teacherAttendanceExcel(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'teacher-attendance.xlsx', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            fn ($path) => Excel::store(new TeacherAttendanceExport($session, $date, $timeSlotIds), $path, self::DISK)
        );
    }

    public function teacherAttendancePdf(ExamSession $session, ?string $date, ?array $timeSlotIds, bool $showInvigilators, bool $force = false): ReportFile
    {
        return $this->run($session, 'teacher-attendance.pdf', $this->normalizeFilters($date, $timeSlotIds, ['showInvigilators' => $showInvigilators]), $force,
            function ($path) use ($session, $date, $timeSlotIds) {
                $rowsByDate = (new ReportDataBuilder)->teacherAttendanceRows($session, $date, $timeSlotIds);

                Pdf::loadView('reports.teacher-attendance-pdf', ['rowsByDate' => $rowsByDate, 'session' => $session])
                    ->setPaper('a4', 'landscape')
                    ->save($path, self::DISK);
            }
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function run(ExamSession $session, string $reportKey, array $filters, bool $force, callable $write): ReportFile
    {
        return $force
            ? $this->cache->regenerate($session, $reportKey, $filters, $write)
            : $this->cache->remember($session, $reportKey, $filters, $write);
    }

    /**
     * The single source of truth for what "the same report" means —
     * reused by ReportDownloads::cacheStatus() so it can ask the cache
     * about a report without regenerating it, using the exact filter
     * shape a real generate call here would use.
     *
     * @param  int[]|null  $timeSlotIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function normalizeFilters(?string $date, ?array $timeSlotIds, array $extra): array
    {
        $slots = $timeSlotIds ? implode(',', collect($timeSlotIds)->sort()->values()->all()) : null;

        return ['date' => $date, 'slots' => $slots, ...$extra];
    }
}
