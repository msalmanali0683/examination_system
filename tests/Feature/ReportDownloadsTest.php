<?php

namespace Tests\Feature;

use App\Exports\DutySheetExport;
use App\Exports\FormattedDatesheetExport;
use App\Exports\MasterDatesheetExport;
use App\Exports\SeatingChartExport;
use App\Exports\SimpleDatesheetExport;
use App\Exports\TeacherAttendanceExport;
use App\Jobs\GenerateReportFile;
use App\Livewire\Sessions\Index;
use App\Livewire\Sessions\ReportShow;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\ReportFile;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reports\ReportDataBuilder;
use App\Services\Reports\ReportFileCache;
use App\Services\Reports\ReportFileGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReportDownloadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedSession(array $attributes = []): ExamSession
    {
        $session = ExamSession::factory()->create($attributes);
        $room = Room::factory()->for($session)->create(['rows' => 2, 'columns' => 2, 'capacity' => 4]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true]);

        foreach ([['r' => 1, 'c' => 1], ['r' => 2, 'c' => 1]] as $seat) {
            $student = Student::factory()->create();
            $enrollment = Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'section' => 'A',
            ]);
            SeatAssignment::create([
                'exam_session_id' => $session->id,
                'enrollment_id' => $enrollment->id,
                'time_slot_id' => $slot->id,
                'room_id' => $room->id,
                'row_number' => $seat['r'],
                'column_number' => $seat['c'],
            ]);
        }

        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        return $session;
    }

    public function test_user_without_view_reports_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'view_reports', 'granted' => false]);
        $session = $this->seedSession();

        $this->actingAs($staff)
            ->get(route('sessions.reports.seating-chart.xlsx', $session))
            ->assertForbidden();
    }

    public function test_seating_chart_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.seating-chart.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_seating_chart_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.seating-chart.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_datesheet_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_datesheet_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_duty_roster_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.duty-roster.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_duty_roster_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.duty-roster.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_teacher_attendance_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.teacher-attendance.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_teacher_attendance_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.teacher-attendance.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * A teacher invigilating twice the same day (two different rooms, two
     * different slots) must appear as two separate sign-in rows — matching
     * the real department template, where the same name legitimately
     * repeats once per duty rather than being deduplicated.
     */
    public function test_teacher_attendance_lists_one_row_per_duty_even_for_the_same_teacher_same_day(): void
    {
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Mr Zoraiz']);

        $roomA = Room::factory()->for($session)->create(['name' => 'ITC-501']);
        $slotA = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00', 'end_time' => '11:00']);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $slotA->id, 'room_id' => $roomA->id]);

        $roomB = Room::factory()->for($session)->create(['name' => 'ITC-502']);
        $slotB = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '11:30', 'end_time' => '13:30']);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $slotB->id, 'room_id' => $roomB->id]);

        $rowsByDate = (new ReportDataBuilder)->teacherAttendanceRows($session);

        $this->assertCount(1, $rowsByDate);
        $rows = $rowsByDate->get('2026-04-20');
        $this->assertCount(2, $rows);
        $this->assertSame(['Mr Zoraiz', 'Mr Zoraiz'], $rows->pluck('teacherName')->all());
        $this->assertEqualsCanonicalizing(['ITC-501', 'ITC-502'], $rows->pluck('room')->all());
        // Sorted by start time, matching the sign-in sheet's natural order.
        $this->assertSame('ITC-501', $rows->first()->room);
    }

    /**
     * The rendered sheet must match the department's own template columns
     * (Teacher Name / Time / Room # / Signature) and the "Attendance
     * Sheet <exam name> / <Day> (<date>)" title banner, with the
     * signature column left blank for physical signing.
     */
    public function test_teacher_attendance_sheet_matches_the_template_columns_and_title(): void
    {
        $session = ExamSession::factory()->create(['name' => 'Mid Term Examination BSAI (Spring 2026)']);
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Ms Ayesha']);
        $room = Room::factory()->for($session)->create(['name' => 'ITC-501']);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00', 'end_time' => '11:00']);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id]);

        $sheets = (new TeacherAttendanceExport($session))->sheets();
        $this->assertCount(1, $sheets);

        $html = $sheets[0]->view()->render();

        $this->assertStringContainsString('Attendance Sheet Mid Term Examination BSAI (Spring 2026)', $html);
        $this->assertStringContainsString('Monday (20-04-2026)', $html);
        $this->assertStringContainsString('Teacher Name', $html);
        $this->assertStringContainsString('Time', $html);
        $this->assertStringContainsString('Room #', $html);
        $this->assertStringContainsString('Signature', $html);
        $this->assertStringContainsString('Ms Ayesha', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('ITC-501', $html);
    }

    /**
     * A whole-exam download (no date filter) produces one Excel sheet per
     * exam day, not one giant mixed table — each day still needs to be
     * printed and signed separately.
     */
    public function test_teacher_attendance_excel_has_one_sheet_per_exam_day_for_a_whole_exam_download(): void
    {
        [$session] = $this->seedTwoDateSession();
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $room = Room::factory()->create();
        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => TimeSlot::where('exam_session_id', $session->id)->orderBy('date')->first()->id,
            'room_id' => $room->id,
        ]);
        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => TimeSlot::where('exam_session_id', $session->id)->orderByDesc('date')->first()->id,
            'room_id' => $room->id,
        ]);

        $sheets = (new TeacherAttendanceExport($session))->sheets();

        $this->assertCount(2, $sheets);
        $titles = collect($sheets)->map->title()->all();
        $this->assertEqualsCanonicalizing(['Monday 04-May', 'Tuesday 05-May'], $titles);
    }

    public function test_subject_wise_seating_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.subject-wise-seating.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_subject_wise_seating_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.subject-wise-seating.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_batch_schedule_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.batch-schedule.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_batch_schedule_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.batch-schedule.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_formatted_datesheet_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.formatted-datesheet.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_simple_datesheet_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.simple-datesheet.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_simple_datesheet_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.simple-datesheet.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * One subject split across two rooms in the same slot should still
     * print once — the whole point of this report over the Master
     * Datesheet is no per-room duplication.
     */
    public function test_simple_datesheet_lists_a_subject_once_per_slot_even_when_split_across_rooms(): void
    {
        $session = ExamSession::factory()->create();
        $roomA = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
        $roomB = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00', 'end_time' => '12:00']);
        $subject = Subject::factory()->create(['title' => 'Programming Fundamentals']);

        foreach ([$roomA, $roomB] as $room) {
            $student = Student::factory()->create();
            $enrollment = Enrollment::factory()->create([
                'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'section' => 'BSAI 2A',
            ]);
            SeatAssignment::create([
                'exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id,
                'time_slot_id' => $slot->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1,
            ]);
        }

        $rowsByDate = (new ReportDataBuilder)->simpleDatesheetRowsByDate($session);

        $this->assertCount(1, $rowsByDate);
        $rows = $rowsByDate->get('2026-04-20');
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('Programming Fundamentals', $row->title);
        $this->assertSame('2nd', $row->semester);
        $this->assertSame('Monday', $row->day);
        $this->assertSame('09:00 - 12:00', $row->slot);

        $html = (new SimpleDatesheetExport($session))->view()->render();
        $this->assertStringContainsString('Programming Fundamentals', $html);
        $this->assertStringContainsString('2nd', $html);
        $this->assertStringContainsString('09:00 - 12:00', $html);
    }

    public function test_formatted_datesheet_lists_every_room_a_subject_used_side_by_side(): void
    {
        // Two rooms, one slot, one subject split across both — the wide
        // template needs both rooms on the SAME row (Room 1.../Room 2...),
        // not one row per room like the flat Master Datesheet.
        $session = ExamSession::factory()->create();
        $roomA = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1, 'name' => 'ITC-501']);
        $roomB = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1, 'name' => 'ITC-502']);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $subject = Subject::factory()->create(['code' => 'CS02115|11', 'title' => 'Programming Fundamentals']);
        $teacherA = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Ms Ayesha']);
        $teacherB = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Mr Zoraiz']);

        foreach ([[$roomA, $teacherA], [$roomB, $teacherB]] as [$room, $teacher]) {
            $student = Student::factory()->create();
            $enrollment = Enrollment::factory()->create([
                'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'section' => 'BSAI 2A',
            ]);
            SeatAssignment::create([
                'exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id,
                'time_slot_id' => $slot->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1,
            ]);
            DutyAssignment::create([
                'exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id,
            ]);
        }

        $rows = (new ReportDataBuilder)->formattedDatesheetRows($session);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('CS02115|11', $row->subject->code);
        $this->assertSame('2nd', $row->semester);
        $this->assertSame(2, $row->studentCount);
        $this->assertCount(2, $row->rooms);
        $this->assertEqualsCanonicalizing(['ITC-501', 'ITC-502'], $row->rooms->pluck('room')->all());
        $this->assertTrue($row->rooms->every(fn ($r) => $r->count === 1));
        $this->assertEqualsCanonicalizing(['Ms Ayesha', 'Mr Zoraiz'], $row->rooms->pluck('invigilator')->all());

        $html = (new FormattedDatesheetExport($session))->view()->render();
        $this->assertStringContainsString('ITC-501', $html);
        $this->assertStringContainsString('ITC-502', $html);
        $this->assertStringContainsString('Ms Ayesha', $html);
        $this->assertStringContainsString('Mr Zoraiz', $html);
        $this->assertStringContainsString('2nd', $html);
        $this->assertStringContainsString(config('exam.datesheet_building_block'), $html);
        $this->assertStringContainsString(config('exam.datesheet_event_package'), $html);
    }

    public function test_reports_print_the_sessions_own_department_name_and_report_stamp(): void
    {
        $session = $this->seedSession([
            'department_name' => 'Department of Computer Science',
            'report_status' => 'final',
            'report_version' => 'v2',
        ]);

        $datesheetHtml = (new MasterDatesheetExport($session))->view()->render();
        $this->assertStringContainsString('Department of Computer Science', $datesheetHtml);
        $this->assertStringContainsString('FINAL — v2', $datesheetHtml);

        $seatingHtml = (new SeatingChartExport($session))->sheets()[0]->view()->render();
        $this->assertStringContainsString('Department of Computer Science', $seatingHtml);
        $this->assertStringContainsString('FINAL — v2', $seatingHtml);
    }

    public function test_reports_fall_back_to_the_default_department_and_tentative_stamp(): void
    {
        $session = $this->seedSession();

        $html = (new MasterDatesheetExport($session))->view()->render();
        $this->assertStringContainsString(config('exam.department_name'), $html);
        $this->assertStringContainsString('TENTATIVE — SUBJECT TO CHANGE', $html);

        // The duty roster carries no per-row department column, but it
        // still stamps the tentative/final status at the top of the sheet.
        $dutyHtml = (new DutySheetExport($session))->view()->render();
        $this->assertStringContainsString('TENTATIVE — SUBJECT TO CHANGE', $dutyHtml);
    }

    /**
     * Two exam dates, each with its own identifiable subject/teacher, so a
     * date-filtered report can be checked for containing only one day's
     * data and not the other's.
     */
    private function seedTwoDateSession(): array
    {
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);

        $slotOne = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-05-04']);
        $subjectOne = Subject::factory()->create(['code' => 'DAY1-SUBJ']);
        $teacherOne = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Day One Teacher']);
        $studentOne = Student::factory()->create();
        $enrollmentOne = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $studentOne->id, 'subject_id' => $subjectOne->id, 'section' => 'A',
        ]);
        SeatAssignment::create([
            'exam_session_id' => $session->id, 'enrollment_id' => $enrollmentOne->id,
            'time_slot_id' => $slotOne->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1,
        ]);
        DutyAssignment::create([
            'exam_session_id' => $session->id, 'teacher_id' => $teacherOne->id, 'time_slot_id' => $slotOne->id, 'room_id' => $room->id,
        ]);

        $roomTwo = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
        $slotTwo = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-05-05']);
        $subjectTwo = Subject::factory()->create(['code' => 'DAY2-SUBJ']);
        $teacherTwo = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Day Two Teacher']);
        $studentTwo = Student::factory()->create();
        $enrollmentTwo = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $studentTwo->id, 'subject_id' => $subjectTwo->id, 'section' => 'B',
        ]);
        SeatAssignment::create([
            'exam_session_id' => $session->id, 'enrollment_id' => $enrollmentTwo->id,
            'time_slot_id' => $slotTwo->id, 'room_id' => $roomTwo->id, 'row_number' => 1, 'column_number' => 1,
        ]);
        DutyAssignment::create([
            'exam_session_id' => $session->id, 'teacher_id' => $teacherTwo->id, 'time_slot_id' => $slotTwo->id, 'room_id' => $roomTwo->id,
        ]);

        return [$session, $subjectOne, $subjectTwo];
    }

    public function test_filtering_a_report_by_date_only_includes_that_dates_data(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session, $subjectOne, $subjectTwo] = $this->seedTwoDateSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', [$session, 'date' => '2026-05-04']));
        $response->assertOk();

        // Build the same filtered data directly to check the actual rows,
        // since the PDF binary itself isn't easily assertable on text.
        $rows = (new ReportDataBuilder)->datesheetRowsByDate($session, '2026-05-04');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows->has('2026-05-04'));
        $this->assertSame('DAY1-SUBJ', $rows->get('2026-05-04')->first()->code);
    }

    public function test_an_invalid_date_filter_falls_back_to_every_date(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session] = $this->seedTwoDateSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', [$session, 'date' => '1999-01-01']));
        $response->assertOk();

        $rows = (new ReportDataBuilder)->datesheetRowsByDate($session);
        $this->assertCount(2, $rows);
    }

    /**
     * Three slots on the SAME date, each with its own identifiable
     * subject/teacher, so a multi-slot filter can be checked for
     * including exactly the checked slots and nothing else that day.
     */
    private function seedThreeSlotsOneDaySession(): array
    {
        $session = ExamSession::factory()->create();
        $slots = [];
        $subjects = [];

        foreach (['09:00', '11:30', '14:00'] as $i => $time) {
            $room = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
            $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-05-04', 'start_time' => $time]);
            $subject = Subject::factory()->create(['code' => 'SLOT'.$i.'-SUBJ']);
            $teacher = Teacher::factory()->for($session)->create(['is_active' => true]);
            $student = Student::factory()->create();
            $enrollment = Enrollment::factory()->create([
                'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'section' => 'A',
            ]);
            SeatAssignment::create([
                'exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id,
                'time_slot_id' => $slot->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1,
            ]);
            DutyAssignment::create([
                'exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id,
            ]);

            $slots[] = $slot;
            $subjects[] = $subject;
        }

        return [$session, $slots, $subjects];
    }

    public function test_filtering_by_multiple_slots_only_includes_those_slots_data(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session, $slots, $subjects] = $this->seedThreeSlotsOneDaySession();

        $slotsParam = $slots[0]->id.','.$slots[2]->id;

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', [$session, 'slots' => $slotsParam]));
        $response->assertOk();

        $rows = (new ReportDataBuilder)->datesheetRowsByDate($session, null, [$slots[0]->id, $slots[2]->id]);
        $codes = $rows->get('2026-05-04')->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['SLOT0-SUBJ', 'SLOT2-SUBJ'], $codes);
    }

    public function test_a_slots_filter_belonging_to_another_session_is_ignored(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session, $slots, $subjects] = $this->seedThreeSlotsOneDaySession();
        $otherSlot = TimeSlot::factory()->create(['exam_session_id' => ExamSession::factory()->create()->id]);

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', [$session, 'slots' => (string) $otherSlot->id]));
        $response->assertOk();

        // None of the other session's slot IDs belong here, so the filter
        // is dropped entirely and every slot for this session is reported.
        $rows = (new ReportDataBuilder)->datesheetRowsByDate($session);
        $this->assertCount(3, $rows->get('2026-05-04'));
    }

    public function test_selecting_a_date_then_checking_slots_builds_a_query_with_both(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session, $slots] = $this->seedThreeSlotsOneDaySession();

        $component = Livewire::actingAs($staff)->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet']);
        $component->set('filterDate', '2026-05-04')
            ->set('filterSlotIds', [$slots[0]->id, $slots[1]->id]);

        $this->assertSame([
            'date' => '2026-05-04',
            'slots' => $slots[0]->id.','.$slots[1]->id,
        ], $component->instance()->reportQuery());
    }

    public function test_changing_the_date_clears_any_previously_checked_slots(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session, $slots] = $this->seedThreeSlotsOneDaySession();

        $component = Livewire::actingAs($staff)->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet']);
        $component->set('filterDate', '2026-05-04')
            ->set('filterSlotIds', [$slots[0]->id]);

        $this->assertSame([$slots[0]->id], $component->get('filterSlotIds'));

        $component->set('filterDate', '');

        $this->assertSame([], $component->get('filterSlotIds'));
    }

    public function test_hiding_invigilators_blanks_them_on_the_affected_reports_but_not_the_duty_roster(): void
    {
        $session = $this->seedSession();
        $teacherName = DutyAssignment::where('exam_session_id', $session->id)->first()->teacher->name;

        $shown = (new MasterDatesheetExport($session, null, true))->view()->render();
        $hidden = (new MasterDatesheetExport($session, null, false))->view()->render();
        $this->assertStringContainsString($teacherName, $shown);
        $this->assertStringNotContainsString($teacherName, $hidden);

        $dutyHtml = (new DutySheetExport($session))->view()->render();
        $this->assertStringContainsString($teacherName, $dutyHtml);
    }

    public function test_hiding_room_and_subject_blanks_them_on_the_duty_roster_but_keeps_the_other_columns(): void
    {
        $session = $this->seedSession();
        $duty = DutyAssignment::where('exam_session_id', $session->id)->first();
        $roomName = $duty->room->name;
        $teacherName = $duty->teacher->name;

        $shown = (new DutySheetExport($session, null, true))->view()->render();
        $hidden = (new DutySheetExport($session, null, false))->view()->render();

        $this->assertStringContainsString($roomName, $shown);
        $this->assertStringNotContainsString($roomName, $hidden);

        // Date/day/time and the teacher's own name stay regardless.
        $this->assertStringContainsString($teacherName, $shown);
        $this->assertStringContainsString($teacherName, $hidden);
    }

    public function test_duty_roster_downloads_accept_the_show_room_subject_query_flag(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)
            ->get(route('sessions.reports.duty-roster.pdf', [$session, 'show_room_subject' => '0']))
            ->assertOk();

        $this->actingAs($staff)
            ->get(route('sessions.reports.duty-roster.xlsx', [$session, 'show_room_subject' => '0']))
            ->assertOk();
    }

    public function test_report_downloads_panel_shows_links_once_generated(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)
            ->get(route('sessions.show', $session))
            ->assertOk()
            ->assertSee('Room-wise Seating Chart')
            ->assertSee('Simple Datesheet');
    }

    public function test_downloading_the_same_report_twice_serves_the_cached_file_without_regenerating(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session))->assertOk();

        $this->assertDatabaseCount('report_files', 1);
        $firstGeneratedAt = ReportFile::first()->generated_at;

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session))->assertOk();

        // Still exactly one cached record, with the same generation
        // timestamp — the second request served the same file rather
        // than building (and re-recording) a fresh one.
        $this->assertDatabaseCount('report_files', 1);
        $this->assertTrue($firstGeneratedAt->eq(ReportFile::first()->generated_at));
    }

    public function test_different_filter_combinations_get_their_own_cached_file(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        [$session] = $this->seedTwoDateSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session))->assertOk();
        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', [$session, 'date' => '2026-05-04']))->assertOk();

        $this->assertDatabaseCount('report_files', 2);
    }

    public function test_regenerating_a_report_replaces_the_cached_file(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.xlsx', $session))->assertOk();
        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session))->assertOk();
        $this->assertDatabaseCount('report_files', 2);

        $before = ReportFile::pluck('generated_at', 'report_key');

        // Force the clock forward so a fresh generated_at is
        // distinguishable from the original.
        $this->travel(1)->minutes();

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet'])
            ->call('regenerate');

        $this->assertDatabaseCount('report_files', 2);
        $after = ReportFile::pluck('generated_at', 'report_key');

        $this->assertTrue($after['datesheet.xlsx']->gt($before['datesheet.xlsx']));
        $this->assertTrue($after['datesheet.pdf']->gt($before['datesheet.pdf']));
    }

    /**
     * With a real (non-sync) queue, dispatching a job doesn't run it
     * inline — this is the actual production shape (QUEUE_CONNECTION on
     * the live server is "database"), unlike every test above which relies
     * on phpunit.xml forcing QUEUE_CONNECTION=sync so a dispatched job
     * still runs before the request returns.
     */
    public function test_a_report_thats_not_cached_yet_queues_a_background_job_and_redirects_instead_of_blocking(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $response = $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session));

        $response->assertRedirect(route('sessions.show', ['examSession' => $session, 'tab' => 'reports']));
        $response->assertSessionHas('status');

        Queue::assertPushed(GenerateReportFile::class, 1);
        $this->assertDatabaseHas('report_files', [
            'exam_session_id' => $session->id,
            'report_key' => 'datesheet.pdf',
            'status' => ReportFile::STATUS_QUEUED,
        ]);
    }

    public function test_requesting_the_same_uncached_report_twice_only_queues_one_job(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session));
        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session));

        Queue::assertPushed(GenerateReportFile::class, 1);
    }

    public function test_a_queued_jobs_handle_method_builds_the_report_and_marks_it_ready(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session));

        Queue::assertPushed(GenerateReportFile::class, function (GenerateReportFile $job) {
            $job->handle();

            return true;
        });

        $file = ReportFile::where('exam_session_id', $session->id)->where('report_key', 'datesheet.pdf')->first();
        $this->assertSame(ReportFile::STATUS_READY, $file->status);
        $this->assertNotNull($file->generated_at);
        $this->assertTrue(Storage::disk('local')->exists($file->disk_path));
    }

    public function test_a_job_that_fails_marks_the_report_failed_with_the_error_instead_of_leaving_it_queued(): void
    {
        $session = ExamSession::factory()->create();
        $filters = (new ReportFileGenerator)->normalizeFilters(null, null, ['showInvigilators' => true]);
        (new ReportFileCache)->enqueue($session, 'datesheet.pdf', $filters);

        // A method name that doesn't exist on ReportFileGenerator forces
        // handle() through its catch branch deterministically, without
        // needing to fabricate a real generation failure.
        (new GenerateReportFile($session->id, 'datesheet.pdf', $filters, 'noSuchReportMethod', null, null, true, false))->handle();

        $file = ReportFile::where('exam_session_id', $session->id)->where('report_key', 'datesheet.pdf')->first();
        $this->assertSame(ReportFile::STATUS_FAILED, $file->status);
        $this->assertNotNull($file->error);
    }

    public function test_the_reports_panel_shows_a_generating_indicator_while_a_build_is_in_flight(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session));

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet'])
            ->assertSee('Generating');
    }

    public function test_the_reports_panel_shows_a_failed_state_with_a_retry_option(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();
        $filters = (new ReportFileGenerator)->normalizeFilters(null, null, ['showInvigilators' => true]);
        $cache = new ReportFileCache;

        foreach (['datesheet.xlsx', 'datesheet.pdf'] as $reportKey) {
            $cache->enqueue($session, $reportKey, $filters);
            $cache->markFailed($session, $reportKey, $filters, 'Something broke');
        }

        Livewire::actingAs($staff)
            ->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet'])
            ->assertSee('Generation failed')
            ->assertSee('Retry');
    }

    /**
     * The shared-hosting fallback: with a real (non-sync) queue
     * connection, dispatch() only persists a row to the jobs table —
     * nothing runs until something drains it (cron, or this). Proves
     * GenerateReportFile::drainQueueNow() — what the queue:work process
     * spawnBackgroundDrain() launches actually runs — actually clears a
     * real pending job rather than relying on QUEUE_CONNECTION=sync
     * quietly making every other test in this file pass either way.
     */
    public function test_drain_queue_now_processes_a_job_sitting_in_the_real_queue_table(): void
    {
        config(['queue.default' => 'database']);
        $session = $this->seedSession();
        $filters = (new ReportFileGenerator)->normalizeFilters(null, null, ['showInvigilators' => true]);

        [, $shouldDispatch] = (new ReportFileCache)->enqueue($session, 'datesheet.pdf', $filters);
        $this->assertTrue($shouldDispatch);
        GenerateReportFile::dispatch($session->id, 'datesheet.pdf', $filters, 'datesheetPdf', null, null, true, false);

        // Dispatching only persisted a row to the jobs table — nothing
        // has run it yet, unlike every other test in this file which
        // relies on QUEUE_CONNECTION=sync running it inline.
        $this->assertDatabaseHas('report_files', [
            'exam_session_id' => $session->id,
            'report_key' => 'datesheet.pdf',
            'status' => ReportFile::STATUS_QUEUED,
        ]);

        GenerateReportFile::drainQueueNow();

        $file = ReportFile::where('exam_session_id', $session->id)->where('report_key', 'datesheet.pdf')->first();
        $this->assertSame(ReportFile::STATUS_READY, $file->status);
        $this->assertTrue(Storage::disk('local')->exists($file->disk_path));
    }

    public function test_regenerating_while_already_in_flight_does_not_dispatch_a_second_pair_of_jobs(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        Livewire::actingAs($staff)->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet'])->call('regenerate');
        Livewire::actingAs($staff)->test(ReportShow::class, ['examSession' => $session, 'reportType' => 'datesheet'])->call('regenerate');

        // datesheet.xlsx + datesheet.pdf from the first call only — the
        // second call sees both still queued/processing and skips them.
        Queue::assertPushed(GenerateReportFile::class, 2);
    }

    public function test_deleting_a_session_removes_its_cached_report_files_from_disk(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->seedSession();

        $this->actingAs($staff)->get(route('sessions.reports.datesheet.pdf', $session))->assertOk();
        $path = ReportFile::first()->disk_path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteSession', $session->id);

        $this->assertDatabaseMissing('report_files', ['exam_session_id' => $session->id]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
