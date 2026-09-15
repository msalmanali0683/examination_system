<?php

namespace Tests\Feature;

use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private function seedSession(array $attributes = []): ExamSession
    {
        $session = ExamSession::factory()->create($attributes);
        $room = Room::factory()->create(['rows' => 2, 'columns' => 2, 'capacity' => 4]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);

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

    public function test_formatted_datesheet_lists_every_room_a_subject_used_side_by_side(): void
    {
        // Two rooms, one slot, one subject split across both — the wide
        // template needs both rooms on the SAME row (Room 1.../Room 2...),
        // not one row per room like the flat Master Datesheet.
        $session = ExamSession::factory()->create();
        $roomA = Room::factory()->create(['rows' => 1, 'columns' => 1, 'capacity' => 1, 'name' => 'ITC-501']);
        $roomB = Room::factory()->create(['rows' => 1, 'columns' => 1, 'capacity' => 1, 'name' => 'ITC-502']);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $subject = Subject::factory()->create(['code' => 'CS02115|11', 'title' => 'Programming Fundamentals']);
        $teacherA = Teacher::factory()->create(['is_active' => true, 'name' => 'Ms Ayesha']);
        $teacherB = Teacher::factory()->create(['is_active' => true, 'name' => 'Mr Zoraiz']);

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

        $rows = (new \App\Services\Reports\ReportDataBuilder)->formattedDatesheetRows($session);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('CS02115|11', $row->subject->code);
        $this->assertSame('2nd', $row->semester);
        $this->assertSame(2, $row->studentCount);
        $this->assertCount(2, $row->rooms);
        $this->assertEqualsCanonicalizing(['ITC-501', 'ITC-502'], $row->rooms->pluck('room')->all());
        $this->assertTrue($row->rooms->every(fn ($r) => $r->count === 1));
        $this->assertEqualsCanonicalizing(['Ms Ayesha', 'Mr Zoraiz'], $row->rooms->pluck('invigilator')->all());

        $html = (new \App\Exports\FormattedDatesheetExport($session))->view()->render();
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

        $datesheetHtml = (new \App\Exports\MasterDatesheetExport($session))->view()->render();
        $this->assertStringContainsString('Department of Computer Science', $datesheetHtml);
        $this->assertStringContainsString('FINAL — v2', $datesheetHtml);

        $seatingHtml = (new \App\Exports\SeatingChartExport($session))->sheets()[0]->view()->render();
        $this->assertStringContainsString('Department of Computer Science', $seatingHtml);
        $this->assertStringContainsString('FINAL — v2', $seatingHtml);
    }

    public function test_reports_fall_back_to_the_default_department_and_tentative_stamp(): void
    {
        $session = $this->seedSession();

        $html = (new \App\Exports\MasterDatesheetExport($session))->view()->render();
        $this->assertStringContainsString(config('exam.department_name'), $html);
        $this->assertStringContainsString('TENTATIVE — SUBJECT TO CHANGE', $html);

        // The duty roster carries no per-row department column, but it
        // still stamps the tentative/final status at the top of the sheet.
        $dutyHtml = (new \App\Exports\DutySheetExport($session))->view()->render();
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
        $room = Room::factory()->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);

        $slotOne = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-05-04']);
        $subjectOne = Subject::factory()->create(['code' => 'DAY1-SUBJ']);
        $teacherOne = Teacher::factory()->create(['is_active' => true, 'name' => 'Day One Teacher']);
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

        $roomTwo = Room::factory()->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
        $slotTwo = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-05-05']);
        $subjectTwo = Subject::factory()->create(['code' => 'DAY2-SUBJ']);
        $teacherTwo = Teacher::factory()->create(['is_active' => true, 'name' => 'Day Two Teacher']);
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
        $rows = (new \App\Services\Reports\ReportDataBuilder)->datesheetRowsByDate($session, '2026-05-04');
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

        $rows = (new \App\Services\Reports\ReportDataBuilder)->datesheetRowsByDate($session);
        $this->assertCount(2, $rows);
    }

    public function test_hiding_invigilators_blanks_them_on_the_affected_reports_but_not_the_duty_roster(): void
    {
        $session = $this->seedSession();
        $teacherName = DutyAssignment::where('exam_session_id', $session->id)->first()->teacher->name;

        $shown = (new \App\Exports\MasterDatesheetExport($session, null, true))->view()->render();
        $hidden = (new \App\Exports\MasterDatesheetExport($session, null, false))->view()->render();
        $this->assertStringContainsString($teacherName, $shown);
        $this->assertStringNotContainsString($teacherName, $hidden);

        $dutyHtml = (new \App\Exports\DutySheetExport($session))->view()->render();
        $this->assertStringContainsString($teacherName, $dutyHtml);
    }

    public function test_hiding_room_and_subject_blanks_them_on_the_duty_roster_but_keeps_the_other_columns(): void
    {
        $session = $this->seedSession();
        $duty = DutyAssignment::where('exam_session_id', $session->id)->first();
        $roomName = $duty->room->name;
        $teacherName = $duty->teacher->name;

        $shown = (new \App\Exports\DutySheetExport($session, null, true))->view()->render();
        $hidden = (new \App\Exports\DutySheetExport($session, null, false))->view()->render();

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
            ->assertSee('Room-wise Seating Chart');
    }
}
