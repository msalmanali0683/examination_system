<?php

namespace Tests\Feature;

use App\Livewire\Sessions\MissingTeachers;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissingTeachersTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_teacher_sections_are_listed_and_can_be_assigned_a_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);

        $withTeacher = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1A',
            'teacher_id' => Teacher::factory()->create(['is_active' => true])->id,
        ]);
        $missing1 = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1B',
            'teacher_id' => null,
        ]);
        $missing2 = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1B',
            'teacher_id' => null,
        ]);

        $component = Livewire::actingAs($staff)->test(MissingTeachers::class, ['examSession' => $session]);

        $missingSections = $component->viewData('missingTeacherSections');
        $this->assertCount(1, $missingSections);
        $this->assertSame('BSAI 1B', $missingSections->first()->section);
        $this->assertSame(2, $missingSections->first()->missing_count);

        $component->set("missingTeacherSelection.{$subject->id}.BSAI 1B", (string) $teacher->id)
            ->call('assignMissingTeacher', $subject->id, 'BSAI 1B');

        $this->assertSame($teacher->id, $missing1->fresh()->teacher_id);
        $this->assertSame($teacher->id, $missing2->fresh()->teacher_id);
        // The row that already had a teacher is left untouched.
        $this->assertNotEquals($teacher->id, $withTeacher->fresh()->teacher_id);
    }

    /**
     * This page suggests whoever already teaches the subject (any
     * enrollment for that subject_id with a teacher set, across every
     * session) instead of leaving staff to search a full alphabetical
     * teacher list for a name they might not know. The more frequently
     * used teacher should be suggested first.
     */
    public function test_missing_teacher_sections_suggest_teachers_already_linked_to_that_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $subject = Subject::factory()->create();
        $frequentTeacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Dr Frequent']);
        $rareTeacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Dr Rare']);
        $unrelatedTeacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Dr Unrelated']);
        $inactiveTeacher = Teacher::factory()->create(['is_active' => false, 'name' => 'Dr Inactive']);

        // Two sections this same session already taught by $frequentTeacher...
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'A', 'teacher_id' => $frequentTeacher->id]);
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'B', 'teacher_id' => $frequentTeacher->id]);
        // ...one from a past session taught by $rareTeacher...
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'C', 'teacher_id' => $rareTeacher->id]);
        // ...one from a past session, now taught by someone no longer active...
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'D', 'teacher_id' => $inactiveTeacher->id]);
        // ...and an unrelated subject taught by $unrelatedTeacher, which
        // must never show up as a suggestion for THIS subject.
        Enrollment::factory()->create(['subject_id' => Subject::factory()->create()->id, 'teacher_id' => $unrelatedTeacher->id]);

        $session = ExamSession::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'E', 'teacher_id' => null,
        ]);

        $component = Livewire::actingAs($staff)->test(MissingTeachers::class, ['examSession' => $session]);

        $suggested = $component->viewData('suggestedTeachersBySubject')->get($subject->id);

        $this->assertNotNull($suggested);
        $this->assertSame(['Dr Frequent', 'Dr Rare'], $suggested->pluck('name')->all());
    }

    public function test_assigning_a_missing_teacher_without_a_selection_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1A',
            'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(MissingTeachers::class, ['examSession' => $session])
            ->call('assignMissingTeacher', $subject->id, 'BSAI 1A');

        // No teacher was picked, so the still-missing enrollment is untouched.
        $this->assertDatabaseHas('enrollments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'teacher_id' => null,
        ]);
    }

    public function test_assign_to_all_gives_every_pending_pair_the_same_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);

        $missingA = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 1A', 'teacher_id' => null]);
        $missingB = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 2A', 'teacher_id' => null]);
        $alreadyTaught = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 1B', 'teacher_id' => Teacher::factory()->create()->id]);

        Livewire::actingAs($staff)
            ->test(MissingTeachers::class, ['examSession' => $session])
            ->set('bulkMissingTeacherId', (string) $teacher->id)
            ->call('assignMissingTeacherToAll');

        $this->assertSame($teacher->id, $missingA->fresh()->teacher_id);
        $this->assertSame($teacher->id, $missingB->fresh()->teacher_id);
        $this->assertNotEquals($teacher->id, $alreadyTaught->fresh()->teacher_id);
    }

    public function test_assign_to_all_without_a_selection_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $missing = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null]);

        Livewire::actingAs($staff)
            ->test(MissingTeachers::class, ['examSession' => $session])
            ->call('assignMissingTeacherToAll');

        $this->assertNull($missing->fresh()->teacher_id);
    }

    public function test_ignore_all_dismisses_pending_pairs_until_shown_again(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null]);

        $component = Livewire::actingAs($staff)->test(MissingTeachers::class, ['examSession' => $session]);
        $this->assertCount(1, $component->viewData('missingTeacherSections'));

        $component->call('ignoreAllMissingTeachers');

        // Dismissed — the page's data source is now empty even though the
        // enrollment itself is still untaught.
        $this->assertCount(0, $component->viewData('missingTeacherSections'));
        $this->assertSame(1, $component->viewData('ignoredMissingTeacherCount'));
        $this->assertDatabaseHas('enrollments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'teacher_id' => null,
        ]);

        $component->call('unignoreMissingTeachers');

        $this->assertCount(1, $component->viewData('missingTeacherSections'));
        $this->assertSame(0, $component->viewData('ignoredMissingTeacherCount'));
    }

    public function test_user_without_manage_sessions_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_sessions', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.missing-teachers', $session))
            ->assertForbidden();
    }
}
