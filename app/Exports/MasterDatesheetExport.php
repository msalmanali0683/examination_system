<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class MasterDatesheetExport implements FromView, WithTitle
{
    public function __construct(private readonly ExamSession $session, private readonly ?string $date = null, private readonly bool $showInvigilators = true) {}

    public function view(): View
    {
        return view('reports.master-datesheet', [
            'rowsByDate' => (new ReportDataBuilder)->datesheetRowsByDate($this->session, $this->date),
            'session' => $this->session,
            'showInvigilators' => $this->showInvigilators,
        ]);
    }

    public function title(): string
    {
        return 'Datesheet';
    }
}
