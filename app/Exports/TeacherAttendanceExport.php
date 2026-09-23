<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * One sheet per exam day, each matching the department's own "Attendance
 * Sheet" sign-in template exactly — a Room-wise Seating Chart-style split
 * (see SeatingChartExport), since each day's sheet is meant to be printed
 * and signed separately even when downloaded together as one workbook.
 */
class TeacherAttendanceExport implements WithMultipleSheets
{
    /**
     * @param  int[]|null  $timeSlotIds
     */
    public function __construct(
        private readonly ExamSession $session,
        private readonly ?string $date = null,
        private readonly ?array $timeSlotIds = null,
    ) {}

    public function sheets(): array
    {
        $rowsByDate = (new ReportDataBuilder)->teacherAttendanceRows($this->session, $this->date, $this->timeSlotIds);

        return $rowsByDate
            ->map(fn ($rows, $dateKey) => new TeacherAttendanceSheetExport(
                $this->session,
                $dateKey,
                $rows,
                Carbon::parse($dateKey)->format('l d-M')
            ))
            ->values()
            ->all();
    }
}
