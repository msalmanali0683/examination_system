<?php

namespace Tests\Feature;

use App\Livewire\Students\Index;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentsManagementTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function openPage(ExamSession $session)
    {
        return Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session]);
    }

    public function test_staff_can_create_a_student_inside_the_session(): void
    {
        $session = ExamSession::factory()->create();

        $this->openPage($session)
            ->set('roll_no', '70112233')
            ->set('name', 'Ayesha Khan')
            ->set('program', 'BS Computer Science')
            ->call('save');

        $this->assertDatabaseHas('students', ['roll_no' => '70112233', 'name' => 'Ayesha Khan', 'exam_session_id' => $session->id]);
    }

    public function test_roll_numbers_must_be_unique_within_a_session(): void
    {
        $session = ExamSession::factory()->create();
        Student::factory()->for($session)->create(['roll_no' => '70112233']);

        $this->openPage($session)
            ->set('roll_no', '70112233')
            ->set('name', 'Someone Else')
            ->call('save')
            ->assertHasErrors(['roll_no']);
    }

    public function test_the_same_roll_number_can_exist_in_another_session(): void
    {
        $sessionA = ExamSession::factory()->create();
        $sessionB = ExamSession::factory()->create();
        Student::factory()->for($sessionA)->create(['roll_no' => '70112233']);

        $this->openPage($sessionB)
            ->set('roll_no', '70112233')
            ->set('name', 'Same Student, Other Department')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $sessionB->students()->count());
    }

    public function test_only_this_sessions_students_are_listed(): void
    {
        $session = ExamSession::factory()->create();
        Student::factory()->for($session)->create(['name' => 'Visible Student']);
        Student::factory()->for(ExamSession::factory()->create())->create(['name' => 'Hidden Student']);

        $this->openPage($session)
            ->assertSee('Visible Student')
            ->assertDontSee('Hidden Student');
    }

    public function test_a_student_from_another_session_cannot_be_touched(): void
    {
        $session = ExamSession::factory()->create();
        $foreign = Student::factory()->for(ExamSession::factory()->create())->create(['name' => 'Foreign']);

        foreach (['editStudent', 'deleteStudent'] as $action) {
            $this->assertThrows(
                fn () => $this->openPage($session)->call($action, $foreign->id),
                ModelNotFoundException::class
            );
        }

        $this->openPage($session)->set('selected', [$foreign->id])->call('bulkDelete');
        $this->openPage($session)->call('deleteAllStudents');

        $this->assertDatabaseHas('students', ['id' => $foreign->id, 'name' => 'Foreign']);
    }

    public function test_staff_can_edit_a_student(): void
    {
        $session = ExamSession::factory()->create();
        $student = Student::factory()->for($session)->create(['name' => 'Old Name']);

        $this->openPage($session)
            ->call('editStudent', $student->id)
            ->set('name', 'New Name')
            ->call('save');

        $this->assertSame('New Name', $student->fresh()->name);
    }

    public function test_deleting_a_student_also_removes_their_enrollments(): void
    {
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $student = Student::factory()->for($session)->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => Subject::factory()->for($session),
        ]);

        $this->openPage($session)
            ->call('deleteStudent', $student->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseMissing('enrollments', ['id' => $enrollment->id]);
    }

    public function test_a_finalized_session_refuses_student_deletion(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $student = Student::factory()->for($session)->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => Subject::factory()->for($session),
        ]);

        $this->openPage($session)
            ->call('deleteStudent', $student->id)
            ->assertSee('finalized')
            ->set('selected', [$student->id])
            ->call('bulkDelete')
            ->call('deleteAllStudents');

        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_bulk_delete_removes_every_selected_student_and_leaves_others(): void
    {
        $session = ExamSession::factory()->create();
        $toDelete = Student::factory()->for($session)->count(2)->create();
        $toKeep = Student::factory()->for($session)->create();

        $this->openPage($session)
            ->set('selected', $toDelete->pluck('id')->all())
            ->call('bulkDelete');

        foreach ($toDelete as $student) {
            $this->assertDatabaseMissing('students', ['id' => $student->id]);
        }
        $this->assertDatabaseHas('students', ['id' => $toKeep->id]);
    }

    public function test_delete_all_removes_every_student_in_the_session_and_only_that_session(): void
    {
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        Student::factory()->for($session)->count(3)->create();
        $survivor = Student::factory()->for($other)->create();

        $this->openPage($session)
            ->call('deleteAllStudents')
            ->assertSee('3 student(s) deleted');

        $this->assertSame(0, $session->students()->count());
        $this->assertDatabaseHas('students', ['id' => $survivor->id]);
    }

    public function test_delete_all_only_matches_the_current_search_filter(): void
    {
        $session = ExamSession::factory()->create();
        $matching = Student::factory()->for($session)->create(['roll_no' => '70111111', 'name' => 'Ali Raza']);
        $other = Student::factory()->for($session)->create(['roll_no' => '70222222', 'name' => 'Bilal Ahmed']);

        $this->openPage($session)
            ->set('search', 'Raza')
            ->call('deleteAllStudents');

        $this->assertDatabaseMissing('students', ['id' => $matching->id]);
        $this->assertDatabaseHas('students', ['id' => $other->id]);
    }

    public function test_delete_all_with_no_students_shows_a_friendly_message(): void
    {
        $session = ExamSession::factory()->create();

        $this->openPage($session)
            ->call('deleteAllStudents')
            ->assertSee('No students to delete');
    }

    public function test_select_all_on_page_toggles_every_visible_student_and_back_off(): void
    {
        $session = ExamSession::factory()->create();
        $ids = Student::factory()->for($session)->count(3)->create()->pluck('id')->all();

        $component = $this->openPage($session);

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertEqualsCanonicalizing($ids, $component->get('selected'));

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertSame([], $component->get('selected'));
    }

    public function test_user_without_manage_enrollments_permission_is_forbidden(): void
    {
        $staff = $this->staff();
        $staff->permissionOverrides()->create(['permission' => 'manage_enrollments', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.students.index', $session))
            ->assertForbidden();
    }

    public function test_searching_filters_by_roll_no_or_name(): void
    {
        $session = ExamSession::factory()->create();
        Student::factory()->for($session)->create(['roll_no' => '70111111', 'name' => 'Ali Raza']);
        Student::factory()->for($session)->create(['roll_no' => '70222222', 'name' => 'Bilal Ahmed']);

        $this->openPage($session)
            ->set('search', 'Raza')
            ->assertViewHas('students', fn ($students) => $students->count() === 1 && $students->first()->name === 'Ali Raza');
    }
}
