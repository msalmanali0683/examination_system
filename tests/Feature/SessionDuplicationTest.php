<?php

namespace Tests\Feature;

use App\Livewire\Sessions\Index;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionRoom;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SessionDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicating_a_session_copies_rooms_teacher_constraints_and_settings_but_not_exam_data(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $source = ExamSession::factory()->create([
            'name' => 'Midterm Spring 2026',
            'seating_strategy' => 'mixed',
            'mixed_subjects_per_room' => 3,
            'invigilators_per_room' => 4,
            'teacher_subject_exclusion' => true,
        ]);

        $room = Room::factory()->create();
        SessionRoom::create(['exam_session_id' => $source->id, 'room_id' => $room->id, 'is_active' => true, 'capacity_override' => 20]);

        $teacher = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $source->id,
            'teacher_id' => $teacher->id,
            'min_duties' => 1,
            'max_duties' => 3,
            'unavailable_days' => [1, 3],
        ]);

        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $source->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
        TimeSlot::factory()->create(['exam_session_id' => $source->id]);

        $component = Livewire::actingAs($staff)->test(Index::class);
        $component->call('startDuplicate', $source->id);
        $component->set('duplicateName', 'Midterm Fall 2026');
        $component->set('duplicateStartDate', '2026-10-01');
        $component->set('duplicateEndDate', '2026-10-10');
        $component->call('confirmDuplicate');

        $new = ExamSession::where('name', 'Midterm Fall 2026')->firstOrFail();

        $this->assertSame('draft', $new->status);
        $this->assertSame('mixed', $new->seating_strategy);
        $this->assertSame(3, $new->mixed_subjects_per_room);
        $this->assertSame(4, $new->invigilators_per_room);
        $this->assertTrue($new->teacher_subject_exclusion);

        $this->assertDatabaseHas('session_rooms', [
            'exam_session_id' => $new->id,
            'room_id' => $room->id,
            'capacity_override' => 20,
        ]);
        $this->assertDatabaseHas('session_teacher_constraints', [
            'exam_session_id' => $new->id,
            'teacher_id' => $teacher->id,
            'min_duties' => 1,
            'max_duties' => 3,
        ]);

        // Exam-specific data never carries over.
        $this->assertSame(0, $new->enrollments()->count());
        $this->assertSame(0, $new->timeSlots()->count());

        $this->assertDatabaseHas('activity_logs', [
            'exam_session_id' => $new->id,
            'action' => 'session.duplicated',
        ]);
    }

    public function test_user_without_manage_sessions_permission_cannot_duplicate(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_sessions', 'granted' => false]);
        ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get('/sessions')
            ->assertForbidden();
    }
}
