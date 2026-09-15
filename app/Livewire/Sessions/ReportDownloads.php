<?php

namespace App\Livewire\Sessions;

use App\Mail\TeacherDutySheetMail;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\TimeSlot;
use App\Services\Reports\ReportDataBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class ReportDownloads extends Component
{
    public ExamSession $examSession;

    /**
     * Empty string means "every date" — otherwise a Y-m-d value that gets
     * appended as a ?date= query filter on every download link below.
     */
    public string $filterDate = '';

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
     * (if any) and the invigilator-visibility flag, only when it's off
     * (keeps links clean in the common case where it's left on).
     */
    public function reportQuery(): array
    {
        $query = [];

        if ($this->filterDate !== '') {
            $query['date'] = $this->filterDate;
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

        if (! $this->showRoomSubjectOnDuty) {
            $query['show_room_subject'] = '0';
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.sessions.report-downloads', [
            'hasSeating' => SeatAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'hasDuties' => DutyAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'availableDates' => TimeSlot::where('exam_session_id', $this->examSession->id)
                ->select('date')
                ->distinct()
                ->orderBy('date')
                ->pluck('date'),
        ]);
    }
}
