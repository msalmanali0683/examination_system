<?php

namespace Tests\Feature;

use App\Exports\CurrentCapacityCheckExport;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SlotCapacitySimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentCapacityDownloadTest extends TestCase
{
    use RefreshDatabase;

    private static int $rollNoSequence = 0;

    private function enroll(ExamSession $session, Subject $subject, string $section, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'section' => $section,
            ]);
        }
    }

    public function test_user_without_generate_roster_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'generate_roster', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.current-capacity-check.xlsx', $session))
            ->assertForbidden();
    }

    public function test_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $response = $this->actingAs($staff)->get(route('sessions.current-capacity-check.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $response = $this->actingAs($staff)->get(route('sessions.current-capacity-check.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_with_no_timetable_yet_the_report_still_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.current-capacity-check.xlsx', $session))
            ->assertOk();
    }

    public function test_the_datesheet_and_room_allocation_sections_reflect_the_real_timetable(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        Room::factory()->for($session)->create(['name' => 'ITC-310', 'rows' => 5, 'columns' => 1, 'capacity' => 5]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enroll($session, $subject, 'BSCS 1A', 3);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        Teacher::factory()->for($session)->count(2)->create(['is_active' => true]);

        $html = (new CurrentCapacityCheckExport(
            $session,
            (new SlotCapacitySimulator)->subjectRequirements($session),
            (new RequirementCalculator)->calculate($session),
        ))->view()->render();

        $this->assertStringContainsString('Intro to Programming', $html);
        $this->assertStringContainsString('ITC-310', $html);
        $this->assertMatchesRegularExpression('/CS101.*Intro to Programming.*BSCS 1A.*>3</s', $html);
    }
}
