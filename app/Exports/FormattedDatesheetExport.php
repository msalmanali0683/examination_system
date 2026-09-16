<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class FormattedDatesheetExport implements FromView, WithTitle
{
    /**
     * @param  int[]|null  $timeSlotIds
     */
    public function __construct(private readonly ExamSession $session, private readonly ?string $date = null, private readonly bool $showInvigilators = true, private readonly ?array $timeSlotIds = null) {}

    public function view(): View
    {
        return view('reports.formatted-datesheet', [
            'rows' => (new ReportDataBuilder)->formattedDatesheetRows($this->session, $this->date, $this->timeSlotIds),
            'session' => $this->session,
            'showInvigilators' => $this->showInvigilators,
        ]);
    }

    public function title(): string
    {
        return 'Datesheet';
    }
}
