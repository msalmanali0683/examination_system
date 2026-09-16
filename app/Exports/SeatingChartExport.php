<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SeatingChartExport implements WithMultipleSheets
{
    /**
     * @param  int[]|null  $timeSlotIds
     */
    public function __construct(private readonly ExamSession $session, private readonly ?string $date = null, private readonly bool $showInvigilators = true, private readonly ?array $timeSlotIds = null) {}

    public function sheets(): array
    {
        $charts = (new ReportDataBuilder)->seatingCharts($this->session, $this->date, $this->timeSlotIds);

        $usedTitles = [];

        return $charts->map(function ($chart) use (&$usedTitles) {
            $title = $this->uniqueTitle($chart, $usedTitles);
            $usedTitles[$title] = true;

            return new SeatingChartSheetExport($chart, $title, $this->session, $this->showInvigilators);
        })->all();
    }

    private function uniqueTitle(object $chart, array $usedTitles): string
    {
        $base = substr($chart->room->name.' '.$chart->timeSlot->date->format('d-M').' '.substr($chart->timeSlot->start_time, 0, 5), 0, 28);
        $base = str_replace([':'], '', $base);

        $title = $base;
        $suffix = 2;
        while (isset($usedTitles[$title])) {
            $title = substr($base, 0, 28).'-'.$suffix;
            $suffix++;
        }

        return $title;
    }
}
