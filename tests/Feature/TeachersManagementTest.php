<?php

namespace Tests\Feature;

use App\Livewire\Teachers\Index;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeachersManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_a_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'Dr Naveed')
            ->set('email', 'naveed@example.com')
            ->call('save');

        $this->assertDatabaseHas('teachers', ['name' => 'Dr Naveed', 'email' => 'naveed@example.com']);
    }

    public function test_teacher_emails_must_be_unique(): void
    {
        Teacher::factory()->create(['email' => 'taken@example.com']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'Someone Else')
            ->set('email', 'taken@example.com')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_toggling_active_flips_status(): void
    {
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('toggleActive', $teacher->id);

        $this->assertFalse($teacher->fresh()->is_active);
    }

    public function test_user_without_manage_teachers_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/teachers')
            ->assertForbidden();
    }

    public function test_bulk_delete_removes_every_selected_teacher_and_leaves_others(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $toDelete = Teacher::factory()->count(2)->create();
        $toKeep = Teacher::factory()->create();

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('selected', $toDelete->pluck('id')->all())
            ->call('bulkDelete');

        foreach ($toDelete as $teacher) {
            $this->assertDatabaseMissing('teachers', ['id' => $teacher->id]);
        }
        $this->assertDatabaseHas('teachers', ['id' => $toKeep->id]);
    }

    public function test_select_all_on_page_toggles_every_visible_teacher_and_back_off(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $teachers = Teacher::factory()->count(3)->create();
        $ids = $teachers->pluck('id')->all();

        $component = Livewire::actingAs($staff)->test(Index::class);

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertEqualsCanonicalizing($ids, $component->get('selected'));

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertSame([], $component->get('selected'));
    }

    public function test_deleting_a_teacher_tied_to_an_active_session_causes_no_error(): void
    {
        // A teacher can be referenced from three different tables at
        // once: an exclusion/duty-limit override, a generated duty, and
        // an enrollment's "taught by" column. Every one of those FKs is
        // configured to cascade or null out at the database level — this
        // proves deleting the teacher never throws, regardless.
        $staff = User::factory()->create(['role' => 'staff']);
        $teacher = Teacher::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();

        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'is_excluded' => true,
        ]);
        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteTeacher', $teacher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('teachers', ['id' => $teacher->id]);
        // Cascades: the constraint and duty rows go with the teacher...
        $this->assertDatabaseMissing('session_teacher_constraints', ['teacher_id' => $teacher->id]);
        $this->assertDatabaseMissing('duty_assignments', ['teacher_id' => $teacher->id]);
        // ...but the enrollment itself survives, just untaught now.
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'teacher_id' => null]);
    }

    public function test_deleting_a_teacher_tied_to_a_finalized_session_still_causes_no_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $teacher = Teacher::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteTeacher', $teacher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('teachers', ['id' => $teacher->id]);
    }
}
