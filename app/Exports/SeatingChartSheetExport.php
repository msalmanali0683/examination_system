<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class SeatingChartSheetExport implements FromView, WithTitle
{
    public function __construct(private readonly object $chart, private readonly string $title) {}

    public function view(): View
    {
        return view('reports.seating-chart-sheet', ['chart' => $this->chart]);
    }

    public function title(): string
    {
        return $this->title;
    }
}
