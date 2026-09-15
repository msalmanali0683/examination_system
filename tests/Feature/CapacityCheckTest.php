<?php

namespace Tests\Feature;

use App\Livewire\Sessions\CapacityCheck;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionRoom;
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $this->seedSlotWithSubjects($session, 1, 3);
        Teacher::factory()->count(2)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->call('checkCurrent')
            ->assertSet('showCurrent', true)
            ->assertViewHas('currentRequirements', fn ($requirements) => $requirements->count() === 1 && $requirements->first()->isMet());
    }

    public function test_check_what_if_simulates_the_chosen_number_of_subjects_per_room(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);

        for ($i = 0; $i < 3; $i++) {
            Room::factory()->create(['rows' => 2, 'columns' => 1, 'capacity' => 2]);
        }
        Room::factory()->create(['rows' => 2, 'columns' => 3, 'capacity' => 6]);

        $this->seedSlotWithSubjects($session, 3, 2);

        $component = Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('whatIfSubjectsPerRoom', 3)
            ->call('checkWhatIf')
            ->assertSet('showWhatIf', true);

        $requirements = $component->viewData('whatIfRequirements');
        $this->assertSame(1, $requirements->first()->roomsNeeded);

        // Never persisted to the session.
        $this->assertSame('strict', $session->fresh()->seating_strategy);
    }

    public function test_what_if_subjects_per_room_must_be_at_least_two(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->set('whatIfSubjectsPerRoom', 1)
            ->call('checkWhatIf')
            ->assertHasErrors(['whatIfSubjectsPerRoom'])
            ->assertSet('showWhatIf', false);
    }
}
