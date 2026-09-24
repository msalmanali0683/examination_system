<?php

namespace App\Livewire\Sessions;

use App\Jobs\GenerateReportFile;
use App\Mail\TeacherDutySheetMail;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\ReportFile;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportDataBuilder;
use App\Services\Reports\ReportFileCache;
use App\Services\Reports\ReportFileGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One report's own filter-and-generate page — every filter shown here
 * (date, slots, and the report's own toggle if it has one) applies only
 * to this report, unlike the shared panel the Reports tab used to be.
 * Generation itself is unchanged: the same cache/queue/self-healing-poll
 * machinery as before (see ReportFileCache, App\Jobs\GenerateReportFile),
 * just scoped to one report type instead of looping over all of them.
 */
class ReportShow extends Component
{
    public ExamSession $examSession;

    public string $reportType;

    /**
     * Empty string means "every date" — otherwise a Y-m-d value.
     */
    public string $filterDate = '';

    /**
     * Time slot IDs checked for the selected date — empty means "every
     * slot that day".
     *
     * @var int[]
     */
    public array $filterSlotIds = [];

    /**
     * The report's own optional toggle (see ReportCatalog's flagLabel) —
     * stays true and is never shown when the report has none.
     */
    public bool $showFlag = true;

    public function mount(ExamSession $examSession, string $reportType): void
    {
        abort_unless(array_key_exists($reportType, ReportCatalog::TYPES), 404);

        $this->authorize('view_reports');
        $this->examSession = $examSession;
        $this->reportType = $reportType;
    }

    public function updatedFilterDate(): void
    {
        $this->filterSlotIds = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return ReportCatalog::TYPES[$this->reportType];
    }

    private function date(): ?string
    {
        return $this->filterDate !== '' ? $this->filterDate : null;
    }

    /**
     * @return int[]|null
     */
    private function slots(): ?array
    {
        return ! empty($this->filterSlotIds) ? $this->filterSlotIds : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        $config = $this->config();

        return (new ReportFileGenerator)->normalizeFilters($this->date(), $this->slots(), [$config['flagKey'] => $this->showFlag]);
    }

    /**
     * Queues a fresh build of every format this report has, in the
     * background — see App\Jobs\GenerateReportFile::spawnBackgroundDrain()
     * for why this never blocks the request even on shared hosting.
     */
    public function regenerate(): void
    {
        $this->authorize('view_reports');

        $config = $this->config();
        $cache = new ReportFileCache;
        $filters = $this->filters();
        $dispatched = 0;

        foreach ($config['formats'] as $format) {
            $reportKey = "{$this->reportType}.{$format}";
            [, $shouldDispatch] = $cache->enqueue($this->examSession, $reportKey, $filters, forceFresh: true);

            if ($shouldDispatch) {
                GenerateReportFile::dispatch($this->examSession->id, $reportKey, $filters, $config['methods'][$format], $this->date(), $this->slots(), $this->showFlag, true);
                $dispatched++;
            }
        }

        if ($dispatched > 0) {
            GenerateReportFile::spawnBackgroundDrain();
        }

        session()->flash('status', $dispatched > 0
            ? 'Generating in the background — this page will update automatically once ready.'
            : 'Already generating — hang tight, this page will update automatically.');
    }

    /**
     * Duty Roster only — the Blade view only renders this action for that
     * report type, so it's never reachable for any other.
     */
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
                'showRoomSubject' => $this->showFlag,
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
     * The query string appended to this report's own download links: the
     * selected date/slots, plus the report's own toggle if it has one and
     * it's been turned off (keeps links clean in the common case).
     */
    public function reportQuery(): array
    {
        $config = $this->config();
        $query = [];

        if ($this->filterDate !== '') {
            $query['date'] = $this->filterDate;
        }

        if (! empty($this->filterSlotIds)) {
            $query['slots'] = implode(',', $this->filterSlotIds);
        }

        if ($config['flagLabel'] && ! $this->showFlag) {
            $query[$config['flagKey'] === 'showRoomSubject' ? 'show_room_subject' : 'show_invigilators'] = '0';
        }

        return $query;
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $config = $this->config();

        $slotsForDate = $this->filterDate !== ''
            ? TimeSlot::where('exam_session_id', $this->examSession->id)
                ->whereDate('date', $this->filterDate)
                ->orderBy('start_time')
                ->get()
            : collect();

        $hasData = match ($config['gate']) {
            'duties' => DutyAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'timetable' => SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)->whereNotNull('time_slot_id')->exists(),
            default => SeatAssignment::where('exam_session_id', $this->examSession->id)->exists(),
        };

        $status = $this->buildStatus(new ReportFileCache, ReportCatalog::reportKeys($this->reportType), $this->filters());

        // Self-healing retry — see Sessions\ReportDownloads::render() for
        // why this is safe to call unconditionally while in progress.
        if ($status['state'] === 'in_progress') {
            GenerateReportFile::spawnBackgroundDrain();
        }

        return view('livewire.sessions.report-show', [
            'config' => $config,
            'hasData' => $hasData,
            'availableDates' => TimeSlot::where('exam_session_id', $this->examSession->id)
                ->select('date')
                ->distinct()
                ->orderBy('date')
                ->pluck('date'),
            'slotsForDate' => $slotsForDate,
            'status' => $status,
        ]);
    }

    /**
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
