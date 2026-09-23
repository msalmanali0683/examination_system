<?php

namespace App\Services\Reports;

/**
 * Static metadata for every report type the Reports tab offers — label,
 * description, which formats it has, which ReportFileGenerator method
 * builds each format, which optional toggle (if any) it exposes, and
 * which data it depends on ('seating' or 'duties', gating whether the
 * report can be generated yet). The single source of truth shared by the
 * report index (Livewire\Sessions\ReportDownloads) and each report's own
 * filter-and-generate page (Livewire\Sessions\ReportShow), so the two
 * can never drift apart on what a given report type actually is.
 *
 * Every report's cache-key flag is folded in as either 'showInvigilators'
 * or 'showRoomSubject' even when the report has no checkbox for it
 * (flagLabel null) — ReportFileGenerator's methods all accept that bool
 * positionally, and the controller's query-string default (true) always
 * applies when no checkbox ever sets it otherwise, so the filters hash a
 * report's own page computes always matches what its download links and
 * ReportFileGenerator itself compute.
 */
class ReportCatalog
{
    public const TYPES = [
        'seating-chart' => [
            'label' => 'Room-wise Seating Chart',
            'description' => 'One sitting plan per room, per slot — roll numbers filled column by column, matching the department\'s usual layout.',
            'icon' => 'grid',
            'color' => 'indigo',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'seatingChartExcel', 'pdf' => 'seatingChartPdf'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => 'Print invigilator names',
            'gate' => 'seating',
        ],
        'datesheet' => [
            'label' => 'Master Datesheet',
            'description' => 'Every subject, section, date, time, room and invigilator for the whole session in one table, grouped by day.',
            'icon' => 'calendar',
            'color' => 'blue',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'datesheetExcel', 'pdf' => 'datesheetPdf'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => 'Print invigilator names',
            'gate' => 'seating',
        ],
        'formatted-datesheet' => [
            'label' => 'Formatted Datesheet',
            'description' => 'Same data as the Master Datesheet, laid out one row per subject with every room it used side by side — matches the department\'s own datesheet template.',
            'icon' => 'calendar',
            'color' => 'sky',
            'formats' => ['xlsx'],
            'methods' => ['xlsx' => 'formattedDatesheetExcel'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => null,
            'gate' => 'seating',
        ],
        'simple-datesheet' => [
            'label' => 'Simple Datesheet',
            'description' => 'Just date, day, subject and time slot — a clean at-a-glance schedule with no room or invigilator detail, grouped by day.',
            'icon' => 'calendar',
            'color' => 'teal',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'simpleDatesheetExcel', 'pdf' => 'simpleDatesheetPdf'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => null,
            'gate' => 'seating',
        ],
        'duty-roster' => [
            'label' => 'Teacher Duty Roster',
            'description' => 'Every teacher\'s invigilation duties — date, time, room and subject — grouped by teacher.',
            'icon' => 'clipboard',
            'color' => 'green',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'dutySheetExcel', 'pdf' => 'dutySheetPdf'],
            'flagKey' => 'showRoomSubject',
            'flagLabel' => 'Print room & subject',
            'gate' => 'duties',
        ],
        'teacher-attendance' => [
            'label' => 'Teacher Attendance Sheet',
            'description' => 'Print/sign-in sheet for the day — Teacher Name, Time, Room # and a blank Signature column, matching the department\'s own attendance sheet. One page per day, even across the whole exam.',
            'icon' => 'clipboard',
            'color' => 'purple',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'teacherAttendanceExcel', 'pdf' => 'teacherAttendancePdf'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => null,
            'gate' => 'duties',
        ],
        'subject-wise-seating' => [
            'label' => 'Subject-wise Seating List',
            'description' => 'Every seated student grouped by subject — roll no, name, section, room, seat and invigilator. Useful as an attendance sheet.',
            'icon' => 'document',
            'color' => 'amber',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'subjectWiseSeatingExcel', 'pdf' => 'subjectWiseSeatingPdf'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => 'Print invigilator names',
            'gate' => 'seating',
        ],
        'batch-schedule' => [
            'label' => 'Batch / Section Schedule',
            'description' => 'One class\'s full exam schedule at a time (e.g. BSAI 2A) — subject, date, time, room and invigilator, in order.',
            'icon' => 'user-group',
            'color' => 'rose',
            'formats' => ['xlsx', 'pdf'],
            'methods' => ['xlsx' => 'batchScheduleExcel', 'pdf' => 'batchSchedulePdf'],
            'flagKey' => 'showInvigilators',
            'flagLabel' => 'Print invigilator names',
            'gate' => 'seating',
        ],
    ];

    /**
     * @return string[]
     */
    public static function reportKeys(string $type): array
    {
        return collect(self::TYPES[$type]['formats'])->map(fn ($format) => "{$type}.{$format}")->all();
    }
}
