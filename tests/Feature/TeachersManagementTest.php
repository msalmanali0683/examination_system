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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeachersManagementTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    public function test_staff_can_create_a_teacher_inside_the_session(): void
    {
        $session = ExamSession::factory()->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('name', 'Dr Naveed')
            ->set('email', 'naveed@example.com')
            ->call('save');

        $this->assertDatabaseHas('teachers', [
            'name' => 'Dr Naveed',
            'email' => 'naveed@example.com',
            'exam_session_id' => $session->id,
        ]);
    }

    public function test_teacher_emails_must_be_unique_within_a_session(): void
    {
        $session = ExamSession::factory()->create();
        Teacher::factory()->for($session)->create(['email' => 'taken@example.com']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('name', 'Someone Else')
            ->set('email', 'taken@example.com')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_another_session_can_have_a_teacher_with_the_same_email(): void
    {
        $sessionA = ExamSession::factory()->create();
        $sessionB = ExamSession::factory()->create();
        Teacher::factory()->for($sessionA)->create(['email' => 'shared@example.com']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $sessionB])
            ->set('name', 'Same Person, Other Department')
            ->set('email', 'shared@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $sessionB->teachers()->count());
    }

    public function test_only_this_sessions_teachers_are_listed(): void
    {
        $session = ExamSession::factory()->create();
        Teacher::factory()->for($session)->create(['name' => 'Visible Teacher']);
        Teacher::factory()->for(ExamSession::factory()->create())->create(['name' => 'Hidden Teacher']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->assertSee('Visible Teacher')
            ->assertDontSee('Hidden Teacher');
    }

    public function test_a_teacher_from_another_session_cannot_be_touched(): void
    {
        $session = ExamSession::factory()->create();
        $foreign = Teacher::factory()->for(ExamSession::factory()->create())->create(['is_active' => true]);

        foreach (['toggleActive', 'deleteTeacher'] as $action) {
            $this->assertThrows(
                fn () => Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session])->call($action, $foreign->id),
                ModelNotFoundException::class
            );
        }

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('selected', [$foreign->id])
            ->call('bulkDelete');

        $this->assertDatabaseHas('teachers', ['id' => $foreign->id, 'is_active' => true]);
    }

    public function test_toggling_active_flips_status(): void
    {
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true]);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('toggleActive', $teacher->id);

        $this->assertFalse($teacher->fresh()->is_active);
    }

    public function test_user_without_manage_teachers_permission_is_forbidden(): void
    {
        $staff = $this->staff();
        $staff->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.teachers.index', $session))
            ->assertForbidden();
    }

    public function test_bulk_delete_removes_every_selected_teacher_and_leaves_others(): void
    {
        $session = ExamSession::factory()->create();
        $toDelete = Teacher::factory()->for($session)->count(2)->create();
        $toKeep = Teacher::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('selected', $toDelete->pluck('id')->all())
            ->call('bulkDelete');

        foreach ($toDelete as $teacher) {
            $this->assertDatabaseMissing('teachers', ['id' => $teacher->id]);
        }
        $this->assertDatabaseHas('teachers', ['id' => $toKeep->id]);
    }

    public function test_select_all_on_page_toggles_every_visible_teacher_and_back_off(): void
    {
        $session = ExamSession::factory()->create();
        $ids = Teacher::factory()->for($session)->count(3)->create()->pluck('id')->all();

        $component = Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session]);

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertEqualsCanonicalizing($ids, $component->get('selected'));

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertSame([], $component->get('selected'));
    }

    public function test_deleting_a_teacher_tied_to_the_session_causes_no_error(): void
    {
        // A teacher can be referenced from three different tables at
        // once: an exclusion/duty-limit override, a generated duty, and
        // an enrollment's "taught by" column. Every one of those FKs is
        // configured to cascade or null out at the database level — this
        // proves deleting the teacher never throws, regardless.
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $teacher = Teacher::factory()->for($session)->create();
        $room = Room::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->for($session)->create();
        $student = Student::factory()->for($session)->create();

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

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteTeacher', $teacher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('teachers', ['id' => $teacher->id]);
        // Cascades: the constraint and duty rows go with the teacher...
        $this->assertDatabaseMissing('session_teacher_constraints', ['teacher_id' => $teacher->id]);
        $this->assertDatabaseMissing('duty_assignments', ['teacher_id' => $teacher->id]);
        // ...but the enrollment itself survives, just untaught now.
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'teacher_id' => null]);
    }

    public function test_a_finalized_session_refuses_teacher_changes(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true]);
        $room = Room::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $duty = DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteTeacher', $teacher->id)
            ->assertSee('finalized')
            ->call('toggleActive', $teacher->id)
            ->set('selected', [$teacher->id])
            ->call('bulkDelete');

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'is_active' => true]);
        $this->assertDatabaseHas('duty_assignments', ['id' => $duty->id]);
    }
}
