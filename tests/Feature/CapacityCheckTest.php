<?php

namespace Tests\Feature;

use App\Livewire\Sessions\CapacityCheck;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CapacityCheckTest extends TestCase
{
    use RefreshDatabase;

    private static int $rollNoSequence = 0;

    private function seedSlotWithSubjects(ExamSession $session, int $subjectCount, int $studentsPerSubject): TimeSlot
    {
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        foreach (range(1, $subjectCount) as $i) {
            $subject = Subject::factory()->create();

            for ($s = 0; $s < $studentsPerSubject; $s++) {
                $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
                Enrollment::factory()->create([
                    'exam_session_id' => $session->id,
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'section' => 'A',
                ]);
            }

            SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);
        }

        return $slot;
    }

    public function test_user_without_generate_roster_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'generate_roster', 'granted' => false]);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->assertForbidden();
    }

    public function test_check_current_shows_requirements_for_the_sessions_saved_strategy(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $this->seedSlotWithSubjects($session, 1, 3);
        Teacher::factory()->for($session)->count(2)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->call('checkCurrent')
            ->assertSet('showCurrent', true)
            ->assertViewHas('currentRequirements', fn ($requirements) => $requirements->count() === 1 && $requirements->first()->isMet());
    }

    public function test_simulate_slots_auto_groups_clash_free_enrollments_without_any_timetable(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Room::factory()->for($session)->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        // Enrollments only — no TimeSlot/SubjectSlotAssignment at all,
        // matching "usable right after uploading the enrollment sheet".
        // Four small, clash-free (distinct students) subjects easily fit
        // together in the four active rooms, so the auto-packer should
        // group all of them into a single simulated slot.
        foreach (range(1, 4) as $i) {
            $subject = Subject::factory()->create();

            for ($s = 0; $s < 3; $s++) {
                $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
                Enrollment::factory()->create([
                    'exam_session_id' => $session->id,
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'section' => 'A',
                ]);
            }
        }

        $component = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('slotsPerDay', 1)
            ->call('simulateSlots')
            ->assertSet('showSlotSimulation', true)
            ->assertViewHas('daysNeeded', 1);

        $requirements = $component->viewData('slotRequirements');
        $this->assertCount(1, $requirements);
        $this->assertSame(12, $requirements->first()->studentCount);
    }

    public function test_slots_per_day_must_be_at_least_one(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('slotsPerDay', 0)
            ->call('simulateSlots')
            ->assertHasErrors(['slotsPerDay'])
            ->assertSet('showSlotSimulation', false)
            ->assertDispatched('open-modal');
    }

    public function test_max_subjects_per_slot_is_passed_through_to_the_simulator(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Room::factory()->for($session)->count(2)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        foreach (range(1, 2) as $i) {
            $subject = Subject::factory()->create();
            $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
            Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'section' => 'A']);
        }

        $component = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('maxSubjectsPerSlot', '1')
            ->call('simulateSlots')
            ->assertHasNoErrors();

        // With capacity for both subjects in one slot but capped at 1
        // each, they must end up in two separate simulated slots.
        $this->assertCount(2, $component->viewData('slotRequirements'));
    }

    public function test_max_subjects_per_slot_must_be_at_least_the_minimum(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('minSubjectsPerSlot', '3')
            ->set('maxSubjectsPerSlot', '2')
            ->call('simulateSlots')
            ->assertHasErrors(['maxSubjectsPerSlot'])
            ->assertSet('showSlotSimulation', false)
            ->assertDispatched('open-modal');
    }

    public function test_simulation_query_only_includes_the_bounds_actually_set(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        // per_day and strategy always ride along (real defaults, unlike min/max which default to "unset").
        $component = Livewire::actingAs($staff)->test(CapacityCheck::class, ['examSession' => $session]);
        $this->assertSame(['per_day' => 2, 'strategy' => 'strict'], $component->instance()->simulationQuery());

        $component->set('minSubjectsPerSlot', '2');
        $this->assertSame(['min' => 2, 'per_day' => 2, 'strategy' => 'strict'], $component->instance()->simulationQuery());

        $component->set('maxSubjectsPerSlot', '5');
        $this->assertSame(['min' => 2, 'max' => 5, 'per_day' => 2, 'strategy' => 'strict'], $component->instance()->simulationQuery());

        $component->set('slotsPerDay', 3);
        $this->assertSame(['min' => 2, 'max' => 5, 'per_day' => 3, 'strategy' => 'strict'], $component->instance()->simulationQuery());

        $component->set('seatingStrategy', 'mixed')->set('mixedSubjectsPerRoom', 3);
        $this->assertSame(['min' => 2, 'max' => 5, 'per_day' => 3, 'strategy' => 'mixed', 'mixed_per_room' => 3], $component->instance()->simulationQuery());
    }

    public function test_simulated_slots_are_labeled_by_day_according_to_slots_per_day(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        foreach (Subject::factory()->count(4)->create() as $subject) {
            $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
            Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'section' => 'A']);
        }

        $component = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('slotsPerDay', 2)
            ->set('maxSubjectsPerSlot', '1')
            ->call('simulateSlots');

        $labels = $component->viewData('slotRequirements')->pluck('label')->all();

        $this->assertSame([
            'Day 1, Slot 1 (1 subject)',
            'Day 1, Slot 2 (1 subject)',
            'Day 2, Slot 1 (1 subject)',
            'Day 2, Slot 2 (1 subject)',
        ], $labels);
    }

    public function test_download_links_use_the_current_min_and_max_once_a_simulation_has_run(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);

        $html = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('minSubjectsPerSlot', '1')
            ->set('maxSubjectsPerSlot', '3')
            ->call('simulateSlots')
            ->html();

        $this->assertStringContainsString(htmlspecialchars(route('sessions.capacity-simulation.xlsx', [$session, 'min' => 1, 'max' => 3, 'per_day' => 2])), $html);
        $this->assertStringContainsString(htmlspecialchars(route('sessions.capacity-simulation.pdf', [$session, 'min' => 1, 'max' => 3, 'per_day' => 2])), $html);
    }

    public function test_re_simulating_with_an_invalid_slots_per_day_does_not_crash_on_cached_results(): void
    {
        // Regression: after a successful simulate, slotRequirementsData
        // stays populated on the component. A second attempt with an
        // invalid slotsPerDay (0) must not divide by it while
        // re-rendering the still-cached (non-empty) results.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('slotsPerDay', 1)
            ->call('simulateSlots')
            ->assertSet('showSlotSimulation', true)
            ->set('slotsPerDay', 0)
            ->call('simulateSlots')
            ->assertHasErrors(['slotsPerDay'])
            ->assertViewHas('daysNeeded', 0);
    }

    public function test_the_seating_strategy_defaults_to_the_sessions_own_saved_strategy(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'combine_sections']);

        $component = Livewire::actingAs($staff)->test(CapacityCheck::class, ['examSession' => $session]);

        $this->assertSame('combine_sections', $component->get('seatingStrategy'));
    }

    public function test_an_invalid_seating_strategy_is_rejected(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('seatingStrategy', 'not-a-real-strategy')
            ->call('simulateSlots')
            ->assertHasErrors(['seatingStrategy'])
            ->assertSet('showSlotSimulation', false);
    }

    public function test_mixed_subjects_per_room_is_required_when_the_strategy_is_mixed(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('seatingStrategy', 'mixed')
            ->set('mixedSubjectsPerRoom', 1)
            ->call('simulateSlots')
            ->assertHasErrors(['mixedSubjectsPerRoom']);
    }

    public function test_choosing_combine_sections_fits_what_strict_alone_cannot(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create();

        foreach (['A', 'B'] as $section) {
            for ($i = 0; $i < 5; $i++) {
                $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
                Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'section' => $section]);
            }
        }

        $strict = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->call('simulateSlots');
        $this->assertTrue($strict->viewData('slotRequirements')->first()->hasUnseatedStudents);

        $combined = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('seatingStrategy', 'combine_sections')
            ->call('simulateSlots');
        $this->assertFalse($combined->viewData('slotRequirements')->first()->hasUnseatedStudents);
    }
}
