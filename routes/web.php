<?php

use App\Http\Controllers\MissingTeacherTemplateController;
use App\Http\Controllers\ReportDownloadController;
use App\Livewire\Dashboard;
use App\Livewire\Rooms\Import as RoomsImport;
use App\Livewire\Rooms\Index as RoomsIndex;
use App\Livewire\Sessions\DutyBoard;
use App\Livewire\Sessions\EnrollmentImport;
use App\Livewire\Sessions\GenerationConstraints;
use App\Livewire\Sessions\IgnoredMissingTeachers;
use App\Livewire\Sessions\Index as SessionsIndex;
use App\Livewire\Sessions\MissingTeachersImport;
use App\Livewire\Sessions\ReportShow;
use App\Livewire\Sessions\SeatingChart;
use App\Livewire\Sessions\Show as SessionsShow;
use App\Livewire\Students\Index as StudentsIndex;
use App\Livewire\Subjects\Index as SubjectsIndex;
use App\Livewire\Teachers\Import as TeachersImport;
use App\Livewire\Teachers\Index as TeachersIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Services\Reports\ReportCatalog;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('dashboard', Dashboard::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('users', UsersIndex::class)
    ->middleware(['auth'])
    ->name('users.index');

Route::get('rooms', RoomsIndex::class)
    ->middleware(['auth'])
    ->name('rooms.index');

Route::get('rooms/import', RoomsImport::class)
    ->middleware(['auth'])
    ->name('rooms.import');

Route::get('teachers', TeachersIndex::class)
    ->middleware(['auth'])
    ->name('teachers.index');

Route::get('teachers/import', TeachersImport::class)
    ->middleware(['auth'])
    ->name('teachers.import');

Route::get('subjects', SubjectsIndex::class)
    ->middleware(['auth'])
    ->name('subjects.index');

Route::get('students', StudentsIndex::class)
    ->middleware(['auth'])
    ->name('students.index');

Route::get('sessions', SessionsIndex::class)
    ->middleware(['auth'])
    ->name('sessions.index');

Route::get('sessions/{examSession}', SessionsShow::class)
    ->middleware(['auth'])
    ->name('sessions.show');

Route::get('sessions/{examSession}/enrollments/import', EnrollmentImport::class)
    ->middleware(['auth'])
    ->name('sessions.enrollments.import');

Route::get('sessions/{examSession}/generate', GenerationConstraints::class)
    ->middleware(['auth'])
    ->name('sessions.generate');

Route::get('sessions/{examSession}/missing-teachers/import', MissingTeachersImport::class)
    ->middleware(['auth'])
    ->name('sessions.missing-teachers.import');

Route::get('sessions/{examSession}/missing-teachers/template.csv', [MissingTeacherTemplateController::class, 'download'])
    ->middleware(['auth'])
    ->name('sessions.missing-teachers.template');

Route::get('sessions/{examSession}/missing-teachers/ignored', IgnoredMissingTeachers::class)
    ->middleware(['auth'])
    ->name('sessions.missing-teachers.ignored');

Route::get('sessions/{examSession}/seating', SeatingChart::class)
    ->middleware(['auth'])
    ->name('sessions.seating');

Route::get('sessions/{examSession}/duties', DutyBoard::class)
    ->middleware(['auth'])
    ->name('sessions.duties');

Route::middleware(['auth'])->prefix('sessions/{examSession}/reports')->name('sessions.reports.')->group(function () {
    Route::get('seating-chart.xlsx', [ReportDownloadController::class, 'seatingChartExcel'])->name('seating-chart.xlsx');
    Route::get('seating-chart.pdf', [ReportDownloadController::class, 'seatingChartPdf'])->name('seating-chart.pdf');
    Route::get('datesheet.xlsx', [ReportDownloadController::class, 'datesheetExcel'])->name('datesheet.xlsx');
    Route::get('datesheet.pdf', [ReportDownloadController::class, 'datesheetPdf'])->name('datesheet.pdf');
    Route::get('formatted-datesheet.xlsx', [ReportDownloadController::class, 'formattedDatesheetExcel'])->name('formatted-datesheet.xlsx');
    Route::get('simple-datesheet.xlsx', [ReportDownloadController::class, 'simpleDatesheetExcel'])->name('simple-datesheet.xlsx');
    Route::get('simple-datesheet.pdf', [ReportDownloadController::class, 'simpleDatesheetPdf'])->name('simple-datesheet.pdf');
    Route::get('duty-roster.xlsx', [ReportDownloadController::class, 'dutySheetExcel'])->name('duty-roster.xlsx');
    Route::get('duty-roster.pdf', [ReportDownloadController::class, 'dutySheetPdf'])->name('duty-roster.pdf');
    Route::get('teacher-attendance.xlsx', [ReportDownloadController::class, 'teacherAttendanceExcel'])->name('teacher-attendance.xlsx');
    Route::get('teacher-attendance.pdf', [ReportDownloadController::class, 'teacherAttendancePdf'])->name('teacher-attendance.pdf');
    Route::get('subject-wise-seating.xlsx', [ReportDownloadController::class, 'subjectWiseSeatingExcel'])->name('subject-wise-seating.xlsx');
    Route::get('subject-wise-seating.pdf', [ReportDownloadController::class, 'subjectWiseSeatingPdf'])->name('subject-wise-seating.pdf');
    Route::get('batch-schedule.xlsx', [ReportDownloadController::class, 'batchScheduleExcel'])->name('batch-schedule.xlsx');
    Route::get('batch-schedule.pdf', [ReportDownloadController::class, 'batchSchedulePdf'])->name('batch-schedule.pdf');
});

// Registered after the literal .xlsx/.pdf download routes above so those
// still match first — this wildcard only catches a plain report-type
// slug with no extension, e.g. /reports/teacher-attendance.
Route::get('sessions/{examSession}/reports/{reportType}', ReportShow::class)
    ->middleware(['auth'])
    ->whereIn('reportType', array_keys(ReportCatalog::TYPES))
    ->name('sessions.reports.show');

require __DIR__.'/auth.php';
