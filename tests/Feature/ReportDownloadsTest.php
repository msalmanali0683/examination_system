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

    private function seedSession(): ExamSession
    {
        $session = ExamSession::factory()->create();
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
