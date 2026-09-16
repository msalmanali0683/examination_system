<?php

namespace App\Livewire\Sessions;

use App\Jobs\GenerateReportFile;
use App\Mail\TeacherDutySheetMail;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\ReportFile;
use App\Models\SeatAssignment;
use App\Models\TimeSlot;
use App\Services\Reports\ReportDataBuilder;
use App\Services\Reports\ReportFileCache;
use App\Services\Reports\ReportFileGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class ReportDownloads extends Component
{
    /**
     * Every report type this panel offers, mapped to the report_key(s)
     * it's stored under (see ReportFileCache) — the basis for both the
     * status line and the Regenerate button.
     */
    private const REPORT_TYPES = [
        'seating-chart' => ['seating-chart.xlsx', 'seating-chart.pdf'],
        'datesheet' => ['datesheet.xlsx', 'datesheet.pdf'],
        'formatted-datesheet' => ['formatted-datesheet.xlsx'],
        'duty-roster' => ['duty-roster.xlsx', 'duty-roster.pdf'],
        'subject-wise-seating' => ['subject-wise-seating.xlsx', 'subject-wise-seating.pdf'],
        'batch-schedule' => ['batch-schedule.xlsx', 'batch-schedule.pdf'],
    ];

    /**
     * Same shape as REPORT_TYPES, pairing each report_key with the
     * ReportFileGenerator method that builds it — what Regenerate needs to
     * dispatch the right background job for each file.
     */
    private const GENERATOR_METHODS = [
        'seating-chart' => [['seating-chart.xlsx', 'seatingChartExcel'], ['seating-chart.pdf', 'seatingChartPdf']],
        'datesheet' => [['datesheet.xlsx', 'datesheetExcel'], ['datesheet.pdf', 'datesheetPdf']],
        'formatted-datesheet' => [['formatted-datesheet.xlsx', 'formattedDatesheetExcel']],
        'duty-roster' => [['duty-roster.xlsx', 'dutySheetExcel'], ['duty-roster.pdf', 'dutySheetPdf']],
        'subject-wise-seating' => [['subject-wise-seating.xlsx', 'subjectWiseSeatingExcel'], ['subject-wise-seating.pdf', 'subjectWiseSeatingPdf']],
        'batch-schedule' => [['batch-schedule.xlsx', 'batchScheduleExcel'], ['batch-schedule.pdf', 'batchSchedulePdf']],
    ];

    public ExamSession $examSession;

    /**
     * Empty string means "every date" — otherwise a Y-m-d value that gets
     * appended as a ?date= query filter on every download link below.
     */
    public string $filterDate = '';

    /**
     * Time slot IDs checked for the selected date — empty means "every
     * slot that day" (the whole-day filter above still applies on its
     * own). Cleared whenever the date changes, since a different date's
     * slot IDs don't apply.
     *
     * @var int[]
     */
    public array $filterSlotIds = [];

    /**
     * Whether the Seating Chart, Datesheet, Batch Schedule and
     * Subject-wise Seating reports print invigilator names — off by
     * request when a copy needs to be shared before duties are settled.
     * The Duty Roster itself is unaffected; it exists to show invigilators.
     */
    public bool $showInvigilators = true;

    /**
     * Duty Roster only — whether its Room / Subject(s) columns print. No
     * other report has this option.
     */
    public bool $showRoomSubjectOnDuty = true;

    public function updatedFilterDate(): void
    {
        $this->filterSlotIds = [];
    }

    /**
     * Queues a fresh build of this report (Excel and PDF together, both
     * filter-combination sensitive) in the background — never runs inline,
     * since a large session's report can take well over a minute. Every
     * download link keeps serving the previously cached copy until the new
     * one is ready; the status line below updates automatically via
     * polling once it is.
     */
    public function regenerate(string $reportType): void
    {
        $this->authorize('view_reports');

        $pairs = self::GENERATOR_METHODS[$reportType] ?? null;

        if (! $pairs) {
            return;
        }

        $date = $this->filterDate !== '' ? $this->filterDate : null;
        $slots = ! empty($this->filterSlotIds) ? $this->filterSlotIds : null;
        $flag = $reportType === 'duty-roster' ? $this->showRoomSubjectOnDuty : $this->showInvigilators;
        $flagKey = $reportType === 'duty-roster' ? 'showRoomSubject' : 'showInvigilators';

        $cache = new ReportFileCache;
        $generator = new ReportFileGenerator;
        $dispatched = 0;

        foreach ($pairs as [$reportKey, $method]) {
            $filters = $generator->normalizeFilters($date, $slots, [$flagKey => $flag]);
            [, $shouldDispatch] = $cache->enqueue($this->examSession, $reportKey, $filters, forceFresh: true);

            if ($shouldDispatch) {
                GenerateReportFile::dispatch($this->examSession->id, $reportKey, $filters, $method, $date, $slots, $flag, true);
                $dispatched++;
            }
        }

        if ($dispatched > 0) {
            GenerateReportFile::spawnBackgroundDrain();
        }

        session()->flash('status', $dispatched > 0
            ? 'Regenerating in the background — this page will update automatically once the fresh version is ready.'
            : 'Already regenerating — hang tight, this page will update automatically.');
    }

    /**
     * The exact filter shape ReportFileGenerator would use for this
     * report type given the panel's current selections — the basis for
     * asking the cache "is there already a generated copy of this?"
     * without triggering a build.
     *
     * @return array<string, mixed>
     */
    private function currentFilters(string $reportType): array
    {
        $date = $this->filterDate !== '' ? $this->filterDate : null;
        $slots = ! empty($this->filterSlotIds) ? $this->filterSlotIds : null;
        $flag = $reportType === 'duty-roster'
            ? ['showRoomSubject' => $this->showRoomSubjectOnDuty]
            : ['showInvigilators' => $this->showInvigilators];

        return (new ReportFileGenerator)->normalizeFilters($date, $slots, $flag);
    }

    public function emailAllDutySheets(): void
    {
        $this->authorize('view_reports');

        $groups = (new ReportDataBuilder)->dutyRowsByTeacher($this->examSession);

        if ($groups->isEmpty()) {
            session()->flash('error', 'No duties generated yet — nothing to email.');

            return;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            if (! $group->teacher->email) {
                $skipped++;

                continue;
            }

            $pdf = Pdf::loadView('reports.duty-sheet-pdf', [
                'teacherGroups' => collect([$group]),
                'session' => $this->examSession,
                'showRoomSubject' => $this->showRoomSubjectOnDuty,
            ])->setPaper('a4', 'portrait')->output();

            Mail::to($group->teacher->email)->send(
                new TeacherDutySheetMail($group->teacher, $this->examSession, $pdf, $group->duties->count())
            );

            $sent++;
        }

        ActivityLog::record(
            $this->examSession,
            'duty_sheets.emailed',
            "Emailed duty sheets to {$sent} teacher(s)".($skipped ? ", skipped {$skipped} with no email on file." : '.')
        );

        session()->flash(
            'status',
            "Sent duty sheet emails to {$sent} teacher(s).".($skipped ? " {$skipped} skipped (no email on file)." : '')
        );
    }

    /**
     * The query string appended to every download link: the selected date
     * (if any), the selected slots within it (if any), and the
     * invigilator-visibility flag, only when it's off (keeps links clean
     * in the common case where it's left on).
     */
    public function reportQuery(): array
    {
        $query = [];

        if ($this->filterDate !== '') {
            $query['date'] = $this->filterDate;
        }

        if (! empty($this->filterSlotIds)) {
            $query['slots'] = implode(',', $this->filterSlotIds);
        }

        if (! $this->showInvigilators) {
            $query['show_invigilators'] = '0';
        }

        return $query;
    }

    /**
     * Same as reportQuery(), minus the invigilator flag (the Duty Roster
     * always shows invigilators, so that option never applies to it) but
     * with its own room/subject-visibility flag instead.
     */
    public function dutyReportQuery(): array
    {
        $query = [];

        if ($this->filterDate !== '') {
            $query['date'] = $this->filterDate;
        }

        if (! empty($this->filterSlotIds)) {
            $query['slots'] = implode(',', $this->filterSlotIds);
        }

        if (! $this->showRoomSubjectOnDuty) {
            $query['show_room_subject'] = '0';
        }

        return $query;
    }

    public function render()
    {
        $slotsForDate = $this->filterDate !== ''
            ? TimeSlot::where('exam_session_id', $this->examSession->id)
                ->whereDate('date', $this->filterDate)
                ->orderBy('start_time')
                ->get()
            : collect();

        $cache = new ReportFileCache;
        $reportStatus = collect(self::REPORT_TYPES)->mapWithKeys(
            fn ($keys, $type) => [$type => $this->buildStatus($cache, $keys, $this->currentFilters($type))]
        );

        return view('livewire.sessions.report-downloads', [
            'hasSeating' => SeatAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'hasDuties' => DutyAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'availableDates' => TimeSlot::where('exam_session_id', $this->examSession->id)
                ->select('date')
                ->distinct()
                ->orderBy('date')
                ->pluck('date'),
            'slotsForDate' => $slotsForDate,
            'reportStatus' => $reportStatus,
            'anyReportInProgress' => $reportStatus->contains(fn ($status) => $status['state'] === 'in_progress'),
        ]);
    }

    /**
     * Reduces every tracked row for a report type (Excel and PDF can each
     * be at a different point in their own lifecycle, since a single
     * download-link click only builds the one format clicked) down to one
     * status the view can show: "in_progress" wins if either format is
     * still queued/building, otherwise the most recent successful build
     * wins, otherwise a failure, otherwise "never generated".
     *
     * @param  string[]  $reportKeys
     * @param  array<string, mixed>  $filters
     * @return array{state: string, generatedAt: ?Carbon, error: ?string}
     */
    private function buildStatus(ReportFileCache $cache, array $reportKeys, array $filters): array
    {
        $rows = $cache->statuses($this->examSession, $reportKeys, $filters);

        if ($rows->contains(fn ($row) => in_array($row->status, [ReportFile::STATUS_QUEUED, ReportFile::STATUS_PROCESSING], true))) {
            return ['state' => 'in_progress', 'generatedAt' => null, 'error' => null];
        }

        $generatedAt = $rows->where('status', ReportFile::STATUS_READY)->max('generated_at');

        if ($generatedAt) {
            return ['state' => 'ready', 'generatedAt' => $generatedAt, 'error' => null];
        }

        $failed = $rows->firstWhere('status', ReportFile::STATUS_FAILED);

        if ($failed) {
            return ['state' => 'failed', 'generatedAt' => null, 'error' => $failed->error];
        }

        return ['state' => 'none', 'generatedAt' => null, 'error' => null];
    }
}
