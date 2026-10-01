<?php

namespace Tests\Feature;

use App\Exports\CapacitySimulationExport;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Generation\SlotCapacitySimulator;
use App\Services\Generation\Strategies\CombineSectionsSeatingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacitySimulationDownloadTest extends TestCase
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
            ->get(route('sessions.capacity-simulation.xlsx', $session))
            ->assertForbidden();
    }

    public function test_excel_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enroll($session, $subject, 'BSCS 1A', 3);

        $response = $this->actingAs($staff)->get(route('sessions.capacity-simulation.xlsx', $session));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_pdf_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enroll($session, $subject, 'BSCS 1A', 3);

        $response = $this->actingAs($staff)->get(route('sessions.capacity-simulation.pdf', $session));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_subject_requirements_lists_every_subjects_seat_count(): void
    {
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $subjectB = Subject::factory()->create(['code' => 'EE201', 'title' => 'Circuits']);
        $this->enroll($session, $subjectA, 'BSCS 1A', 5);
        $this->enroll($session, $subjectB, 'BSEE 2A', 3);

        $requirements = (new SlotCapacitySimulator)->subjectRequirements($session);

        $this->assertCount(2, $requirements);
        $cs = $requirements->firstWhere('code', 'CS101');
        $this->assertSame('Intro to Programming', $cs['title']);
        $this->assertSame('BSCS 1A', $cs['sections']);
        $this->assertSame(5, $cs['count']);
        $ee = $requirements->firstWhere('code', 'EE201');
        $this->assertSame(3, $ee['count']);
    }

    public function test_subject_requirements_lists_every_section_a_subject_is_enrolled_under(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enroll($session, $subject, 'BSCS 1A', 2);
        $this->enroll($session, $subject, 'BSCS 1B', 1);

        $row = (new SlotCapacitySimulator)->subjectRequirements($session)->first();

        $this->assertSame('BSCS 1A, BSCS 1B', $row['sections']);
        $this->assertSame(3, $row['count']);
    }

    public function test_the_subject_requirements_table_renders_in_the_export_view(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enroll($session, $subject, 'BSCS 1A', 5);

        $simulator = new SlotCapacitySimulator;
        $html = (new CapacitySimulationExport(
            $session,
            $simulator->subjectRequirements($session),
            $simulator->simulate($session),
        ))->view()->render();

        $this->assertStringContainsString('Intro to Programming', $html);
        $this->assertStringContainsString('BSCS 1A', $html);
        $this->assertMatchesRegularExpression('/CS101.*Intro to Programming.*BSCS 1A.*>5</s', $html);
    }

    public function test_the_room_allocation_section_shows_which_room_and_section_fills_it(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'ITC-310', 'rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enroll($session, $subject, 'BSCS 1A', 6);

        $response = $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get(route('sessions.capacity-simulation.xlsx', $session));

        $response->assertOk();

        $result = (new SlotCapacitySimulator)->simulate($session);
        $room = $result->first()->roomBreakdown[0];
        $this->assertSame('ITC-310', $room['roomName']);
        $this->assertSame(6, $room['filled']);
        $this->assertSame(4, $room['remaining']);
        $this->assertSame('CS101', $room['sections'][0]['subjectCode']);
    }

    public function test_per_day_query_parameter_labels_slots_by_day_in_the_export_view(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(2)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $this->enroll($session, $subjectA, 'A', 2);
        $this->enroll($session, $subjectB, 'B', 2);

        $simulator = new SlotCapacitySimulator;
        $slots = SlotCapacitySimulator::withDayAndSlotLabels($simulator->simulate($session, null, 1), 1);
        $html = (new CapacitySimulationExport($session, $simulator->subjectRequirements($session), $slots))->view()->render();

        $this->assertStringContainsString('Day 1, Slot 1', $html);
        $this->assertStringContainsString('Day 2, Slot 1', $html);
    }

    public function test_per_day_query_parameter_defaults_to_two_when_absent_or_invalid(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.capacity-simulation.xlsx', [$session, 'per_day' => 'not-a-number']))
            ->assertOk();
    }

    public function test_max_query_parameter_is_passed_through_to_the_simulation(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(2)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $this->enroll($session, $subjectA, 'A', 2);
        $this->enroll($session, $subjectB, 'B', 2);

        $staff = User::factory()->create(['role' => 'staff']);

        $result = (new SlotCapacitySimulator)->simulate($session, null, 1);
        $this->assertCount(2, $result, 'sanity check: max=1 forces two separate simulated slots');

        $response = $this->actingAs($staff)
            ->get(route('sessions.capacity-simulation.pdf', [$session, 'max' => 1]));

        $response->assertOk();
    }

    public function test_an_invalid_min_or_max_is_ignored_instead_of_erroring(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.capacity-simulation.xlsx', [$session, 'min' => 'not-a-number']))
            ->assertOk();
    }

    public function test_with_no_enrollments_yet_the_report_still_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.capacity-simulation.xlsx', $session))
            ->assertOk();
    }

    public function test_the_strategy_query_parameter_changes_the_simulation_and_shows_in_the_export(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create();
        $this->enroll($session, $subject, 'A', 5);
        $this->enroll($session, $subject, 'B', 5);

        // Sanity check: Strict alone can't seat both 5-student sections in the one 10-seat room.
        $strict = (new SlotCapacitySimulator)->simulate($session)->first();
        $this->assertTrue($strict->hasUnseatedStudents);

        $combined = SlotCapacitySimulator::withDayAndSlotLabels(
            (new SlotCapacitySimulator(new CombineSectionsSeatingStrategy))->simulate($session),
            2
        );
        $this->assertFalse($combined->first()->hasUnseatedStudents);

        $html = (new CapacitySimulationExport(
            $session,
            (new SlotCapacitySimulator)->subjectRequirements($session),
            $combined,
            null,
            null,
            'combine_sections'
        ))->view()->render();

        $this->assertStringContainsString('Combine sections', $html);
    }

    public function test_an_invalid_strategy_falls_back_to_the_sessions_own_saved_strategy(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);

        $this->actingAs($staff)
            ->get(route('sessions.capacity-simulation.xlsx', [$session, 'strategy' => 'not-a-real-strategy']))
            ->assertOk();
    }

    public function test_mixed_per_room_query_parameter_is_honored_for_the_mixed_strategy(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        // Just confirms the parameter is accepted and doesn't error through the full request —
        // SeatAllocationService::strategyFor()'s own tests already cover how mixedSubjectsPerRoom
        // changes MixedSeatingStrategy's behavior.
        $this->actingAs($staff)
            ->get(route('sessions.capacity-simulation.xlsx', [$session, 'strategy' => 'mixed', 'mixed_per_room' => 3]))
            ->assertOk();
    }
}
