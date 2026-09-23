<?php

namespace Tests\Feature;

use App\Livewire\Sessions\SectionTeachers;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SectionTeachersTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(ExamSession $session, Subject $subject, string $section, ?int $teacherId = null): void
    {
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => Student::factory(),
            'subject_id' => $subject->id,
            'section' => $section,
            'teacher_id' => $teacherId,
        ]);
    }

    public function test_a_pair_with_no_teacher_shows_as_missing(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enroll($session, $subject, 'BSAI 1A');

        $rows = Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->teacher);
    }

    public function test_a_pair_where_every_student_shares_the_same_teacher_shows_that_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $teacher = Teacher::factory()->create(['name' => 'Huria Ali']);
        $this->enroll($session, $subject, 'BSAI 1A', $teacher->id);
        $this->enroll($session, $subject, 'BSAI 1A', $teacher->id);

        $rows = Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertSame($teacher->id, $rows->first()->teacher->id);
    }

    public function test_a_pair_split_across_two_different_teachers_shows_as_mixed(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $teacherA = Teacher::factory()->create();
        $teacherB = Teacher::factory()->create();
        $this->enroll($session, $subject, 'BSAI 1A', $teacherA->id);
        $this->enroll($session, $subject, 'BSAI 1A', $teacherB->id);

        $rows = Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->viewData('rows');

        $this->assertSame('mixed', $rows->first()->teacher);
    }

    public function test_changing_the_teacher_overwrites_an_already_assigned_one(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $oldTeacher = Teacher::factory()->create(['is_active' => true]);
        $newTeacher = Teacher::factory()->create(['is_active' => true]);
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => $oldTeacher->id,
        ]);

        Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->set("selection.{$subject->id}.BSAI 1A", (string) $newTeacher->id)
            ->call('changeTeacher', $subject->id, 'BSAI 1A');

        $this->assertSame($newTeacher->id, $enrollment->fresh()->teacher_id);
    }

    public function test_changing_the_teacher_fills_in_a_missing_one_too(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->set("selection.{$subject->id}.BSAI 1A", (string) $teacher->id)
            ->call('changeTeacher', $subject->id, 'BSAI 1A');

        $this->assertSame($teacher->id, $enrollment->fresh()->teacher_id);
    }

    public function test_changing_the_teacher_normalizes_a_mixed_pair_onto_one_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $teacherA = Teacher::factory()->create();
        $teacherB = Teacher::factory()->create(['is_active' => true]);
        $enrollmentA = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => $teacherA->id,
        ]);
        $enrollmentB = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->set("selection.{$subject->id}.BSAI 1A", (string) $teacherB->id)
            ->call('changeTeacher', $subject->id, 'BSAI 1A');

        $this->assertSame($teacherB->id, $enrollmentA->fresh()->teacher_id);
        $this->assertSame($teacherB->id, $enrollmentB->fresh()->teacher_id);
    }

    public function test_changing_teacher_without_a_selection_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enroll($session, $subject, 'BSAI 1A');

        Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->call('changeTeacher', $subject->id, 'BSAI 1A')
            ->assertSee('Pick a teacher');
    }

    public function test_changing_a_teacher_only_affects_the_targeted_subject_and_section(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create(['code' => 'CS101']);
        $subjectB = Subject::factory()->create(['code' => 'CS202']);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $originalTeacher = Teacher::factory()->create();

        $target = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 1A', 'teacher_id' => $originalTeacher->id,
        ]);
        $otherSection = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 1B', 'teacher_id' => $originalTeacher->id,
        ]);
        $otherSubject = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 1A', 'teacher_id' => $originalTeacher->id,
        ]);

        Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->set("selection.{$subjectA->id}.BSAI 1A", (string) $teacher->id)
            ->call('changeTeacher', $subjectA->id, 'BSAI 1A');

        $this->assertSame($teacher->id, $target->fresh()->teacher_id);
        $this->assertSame($originalTeacher->id, $otherSection->fresh()->teacher_id);
        $this->assertSame($originalTeacher->id, $otherSubject->fresh()->teacher_id);
    }

    public function test_search_filters_by_subject_code_or_title(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $subjectB = Subject::factory()->create(['code' => 'MAT101', 'title' => 'Calculus']);
        $this->enroll($session, $subjectA, 'BSAI 1A');
        $this->enroll($session, $subjectB, 'BSAI 1A');

        $rows = Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->set('search', 'Calculus')
            ->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('MAT101', $rows->first()->code);
    }

    public function test_change_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(SectionTeachers::class, ['examSession' => $session])
            ->set("selection.{$subject->id}.BSAI 1A", (string) $teacher->id)
            ->call('changeTeacher', $subject->id, 'BSAI 1A');

        $this->assertNull($enrollment->fresh()->teacher_id);
    }

    public function test_user_without_manage_sessions_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_sessions', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.section-teachers', $session))
            ->assertForbidden();
    }
}
