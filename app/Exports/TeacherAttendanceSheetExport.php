<?php

namespace App\Exports;

use App\Models\ExamSession;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class TeacherAttendanceSheetExport implements FromView, WithTitle
{
    public function __construct(
        private readonly ExamSession $session,
        private readonly string $dateKey,
        private readonly Collection $rows,
        private readonly string $title,
    ) {}

    public function view(): View
    {
        return view('reports.teacher-attendance', [
            'session' => $this->session,
            'dateKey' => $this->dateKey,
            'rows' => $this->rows,
        ]);
    }

    public function title(): string
    {
        return $this->title;
    }
}
