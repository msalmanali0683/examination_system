<?php

namespace App\Livewire\Sessions;

use App\Mail\TeacherDutySheetMail;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Services\Reports\ReportDataBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class ReportDownloads extends Component
{
    public ExamSession $examSession;

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

    public function render()
    {
        return view('livewire.sessions.report-downloads', [
            'hasSeating' => SeatAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'hasDuties' => DutyAssignment::where('exam_session_id', $this->examSession->id)->exists(),
        ]);
    }
}
