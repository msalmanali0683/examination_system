<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class DutySheetExport implements FromView, WithTitle
{
    public function __construct(private readonly ExamSession $session) {}

    public function view(): View
    {
        return view('reports.duty-sheet', [
            'teacherGroups' => (new ReportDataBuilder)->dutyRowsByTeacher($this->session),
        ]);
    }

    public function title(): string
    {
        return 'Duty Roster';
    }
}
