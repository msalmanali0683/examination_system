<?php

namespace App\Livewire\Sessions;

use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use Livewire\Component;

class ReportDownloads extends Component
{
    public ExamSession $examSession;

    public function render()
    {
        return view('livewire.sessions.report-downloads', [
            'hasSeating' => SeatAssignment::where('exam_session_id', $this->examSession->id)->exists(),
            'hasDuties' => DutyAssignment::where('exam_session_id', $this->examSession->id)->exists(),
        ]);
    }
}
