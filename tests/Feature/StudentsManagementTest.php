<?php

namespace Tests\Feature;

use App\Livewire\Students\Index;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_a_student(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('roll_no', '70112233')
            ->set('name', 'Ayesha Khan')
            ->set('program', 'BS Computer Science')
            ->call('save');

        $this->assertDatabaseHas('students', ['roll_no' => '70112233', 'name' => 'Ayesha Khan']);
    }

    public function test_roll_numbers_must_be_unique(): void
    {
        Student::factory()->create(['roll_no' => '70112233']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('roll_no', '70112233')
            ->set('name', 'Someone Else')
            ->call('save')
            ->assertHasErrors(['roll_no']);
    }

    public function test_staff_can_edit_a_student(): void
    {
        $student = Student::factory()->create(['name' => 'Old Name']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('editStudent', $student->id)
            ->set('name', 'New Name')
            ->call('save');

        $this->assertSame('New Name', $student->fresh()->name);
    }

    public function test_a_student_with_no_history_can_be_deleted(): void
    {
        $student = Student::factory()->create();
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteStudent', $student->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }

    /**
     * Deleting a student cascades to their enrollments (cascadeOnDelete()
     * on enrollments.student_id), and from there to their seat assignment
     * — for a finalized session that would silently erase part of its
     * permanent seating chart, so this is blocked the same way deleting
     * the session itself is blocked while finalized.
     */
    public function test_deleting_a_student_enrolled_in_a_finalized_session_is_blocked(): void
    {
        $student = Student::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $subject = Subject::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteStudent', $student->id)
            ->assertSee("can't be deleted");

        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_deleting_a_student_enrolled_only_in_a_non_finalized_session_is_allowed(): void
    {
        $student = Student::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $subject = Subject::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteStudent', $student->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }

    public function test_bulk_delete_removes_every_selected_student_and_leaves_others(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $toDelete = Student::factory()->count(2)->create();
        $toKeep = Student::factory()->create();

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('selected', $toDelete->pluck('id')->all())
            ->call('bulkDelete');

        foreach ($toDelete as $student) {
            $this->assertDatabaseMissing('students', ['id' => $student->id]);
        }
        $this->assertDatabaseHas('students', ['id' => $toKeep->id]);
    }

    public function test_bulk_delete_skips_students_with_finalized_enrollments_but_deletes_the_rest(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $safeStudent = Student::factory()->create();
        $protectedStudent = Student::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $subject = Subject::factory()->create();

        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $protectedStudent->id,
            'subject_id' => $subject->id,
        ]);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('selected', [$safeStudent->id, $protectedStudent->id])
            ->call('bulkDelete')
            ->assertSee('skipped');

        $this->assertDatabaseMissing('students', ['id' => $safeStudent->id]);
        $this->assertDatabaseHas('students', ['id' => $protectedStudent->id]);
    }

    public function test_delete_all_removes_every_student_and_skips_none_with_no_finalized_enrollments(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        Student::factory()->count(3)->create();

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteAllStudents')
            ->assertSee('3 student(s) deleted');

        $this->assertDatabaseCount('students', 0);
    }

    public function test_delete_all_only_matches_the_current_search_filter(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $matching = Student::factory()->create(['roll_no' => '70111111', 'name' => 'Ali Raza']);
        $other = Student::factory()->create(['roll_no' => '70222222', 'name' => 'Bilal Ahmed']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('search', 'Raza')
            ->call('deleteAllStudents');

        $this->assertDatabaseMissing('students', ['id' => $matching->id]);
        $this->assertDatabaseHas('students', ['id' => $other->id]);
    }

    public function test_delete_all_skips_students_with_finalized_enrollments_but_deletes_the_rest(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $safeStudent = Student::factory()->create();
        $protectedStudent = Student::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $subject = Subject::factory()->create();

        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $protectedStudent->id,
            'subject_id' => $subject->id,
        ]);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteAllStudents')
            ->assertSee('skipped');

        $this->assertDatabaseMissing('students', ['id' => $safeStudent->id]);
        $this->assertDatabaseHas('students', ['id' => $protectedStudent->id]);
    }

    public function test_delete_all_with_no_students_shows_a_friendly_message(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteAllStudents')
            ->assertSee('No students to delete');
    }

    public function test_select_all_on_page_toggles_every_visible_student_and_back_off(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $students = Student::factory()->count(3)->create();
        $ids = $students->pluck('id')->all();

        $component = Livewire::actingAs($staff)->test(Index::class);

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertEqualsCanonicalizing($ids, $component->get('selected'));

        $component->call('toggleSelectAllOnPage', $ids);
        $this->assertSame([], $component->get('selected'));
    }

    public function test_user_without_manage_enrollments_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_enrollments', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/students')
            ->assertForbidden();
    }

    public function test_searching_filters_by_roll_no_or_name(): void
    {
        Student::factory()->create(['roll_no' => '70111111', 'name' => 'Ali Raza']);
        Student::factory()->create(['roll_no' => '70222222', 'name' => 'Bilal Ahmed']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('search', 'Raza')
            ->assertViewHas('students', fn ($students) => $students->count() === 1 && $students->first()->name === 'Ali Raza');
    }
}
