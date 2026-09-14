<?php

namespace Tests\Feature;

use App\Livewire\Sessions\GenerationConstraints;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GenerationConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_save_generation_settings(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'mixed')
            ->set('mixed_subjects_per_room', 3)
            ->set('invigilators_per_room', 3)
            ->set('teacher_subject_exclusion', true)
            ->call('saveSettings');

        $this->assertSame('mixed', $session->fresh()->seating_strategy);
        $this->assertSame(3, $session->fresh()->mixed_subjects_per_room);
        $this->assertSame(3, $session->fresh()->invigilators_per_room);
        $this->assertTrue($session->fresh()->teacher_subject_exclusion);
    }

    public function test_mixed_subjects_per_room_resets_to_default_outside_mixed_mode(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict')
            ->set('mixed_subjects_per_room', 7)
            ->call('saveSettings');

        $this->assertSame(2, $session->fresh()->mixed_subjects_per_room);
    }

    public function test_pin_subjects_table_shows_a_per_section_breakdown(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1B']);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $breakdown = $component->viewData('sectionBreakdown')->get($subject->id);
        $this->assertSame(['BSAI 1A' => 2, 'BSAI 1B' => 1], $breakdown->all());
    }

    public function test_toggling_duty_matches_sections_persists_and_reverting_clears_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $component->call('toggleDutyMatchesSections', $subject->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'duty_matches_sections' => true,
        ]);

        $component->call('toggleDutyMatchesSections', $subject->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'duty_matches_sections' => false,
        ]);
    }

    public function test_pinning_and_unpinning_a_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $component->call('updatePin', $subject->id, (string) $slot->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'is_pinned' => true,
        ]);

        $component->call('updatePin', $subject->id, '');
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'is_pinned' => false,
        ]);
    }

    public function test_generate_places_non_conflicting_subjects_without_notes(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $slot1 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        $slot2 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();

        // Different students in each subject — no shared enrollment, no conflict.
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'conflict_note' => null,
        ]);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subjectB->id,
            'conflict_note' => null,
        ]);
    }

    public function test_generate_respects_a_pinned_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $slot1 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        $slot2 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('updatePin', $subject->id, (string) $slot2->id);
        $component->call('generateTimetable');

        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot2->id,
            'is_pinned' => true,
        ]);
    }

    public function test_generate_records_conflict_notes_when_a_clash_is_unavoidable(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subjects = Subject::factory()->count(3)->create();
        $student = Student::factory()->create();

        // One student takes all 3 subjects -> every pair conflicts, only 2 slots exist.
        foreach ($subjects as $subject) {
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
            ]);
        }

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertGreaterThan(0, SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('conflict_note')
            ->count());
    }

    public function test_generate_with_no_enrollments_shows_an_error_without_crashing(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertDatabaseCount('subject_slot_assignments', 0);
    }

    public function test_user_without_generate_roster_permission_cannot_run_generation(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'generate_roster', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable')
            ->assertForbidden();

        $this->assertDatabaseCount('subject_slot_assignments', 0);
    }

    public function test_generate_seating_is_blocked_when_capacity_requirement_is_not_met(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        // Only a tiny room, no active rooms in session at all — guarantees a shortfall.
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateSeating');

        $this->assertDatabaseCount('seat_assignments', 0);
    }

    public function test_generate_duties_is_blocked_until_seating_exists(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateDuties');

        $this->assertDatabaseCount('duty_assignments', 0);
        $this->assertSame('draft', $session->fresh()->status);
    }

    public function test_generate_duties_creates_assignments_and_marks_the_session_generated(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'draft', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => 1,
            'column_number' => 1,
        ]);
        Teacher::factory()->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateDuties');

        $this->assertDatabaseCount('duty_assignments', 1);
        $this->assertSame('generated', $session->fresh()->status);
    }
}
