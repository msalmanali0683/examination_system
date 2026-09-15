<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class SubjectWiseSeatingExport implements FromView, WithTitle
{
    public function __construct(private readonly ExamSession $session, private readonly ?string $date = null, private readonly bool $showInvigilators = true) {}

    public function view(): View
    {
        return view('reports.subject-wise-seating', [
            'subjects' => (new ReportDataBuilder)->subjectWiseSeatingRows($this->session, $this->date),
            'session' => $this->session,
            'showInvigilators' => $this->showInvigilators,
        ]);
    }

    public function title(): string
    {
        return 'Subject-wise Seating';
    }
}
