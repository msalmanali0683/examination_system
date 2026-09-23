<?php

namespace Tests\Feature;

use App\Livewire\Sessions\GenerationConstraints;
use App\Livewire\Sessions\IgnoredMissingTeachers;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\MissingTeacherSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IgnoredMissingTeachersTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(ExamSession $session, Subject $subject, string $section, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => Student::factory(),
                'subject_id' => $subject->id,
                'section' => $section,
            ]);
        }
    }

    /**
     * Dismisses one subject/section pair the way the Missing Teachers
     * card's "Ignore All" action would, without exercising that whole
     * component here.
     */
    private function ignorePair(ExamSession $session, int $subjectId, string $section): void
    {
        $session->update(['ignored_missing_teacher_sections' => [
            MissingTeacherSections::key($subjectId, $section),
        ]]);
    }

    public function test_ignored_pairs_are_listed_separately_from_pending_ones(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $ignoredSubject = Subject::factory()->create(['code' => 'CS101']);
        $pendingSubject = Subject::factory()->create(['code' => 'CS202']);
        $this->enroll($session, $ignoredSubject, 'BSAI 1A');
        $this->enroll($session, $pendingSubject, 'BSAI 1A');
        $this->ignorePair($session, $ignoredSubject->id, 'BSAI 1A');

        $component = Livewire::actingAs($staff)->test(IgnoredMissingTeachers::class, ['examSession' => $session]);

        $ignoredSections = $component->viewData('ignoredSections');
        $this->assertCount(1, $ignoredSections);
        $this->assertSame('CS101', $ignoredSections->first()->code);
    }

    public function test_assigning_a_teacher_on_the_ignored_page_resolves_it_and_drops_it_from_ignored(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $this->enroll($session, $subject, 'BSAI 1A', 2);
        $this->ignorePair($session, $subject->id, 'BSAI 1A');

        Livewire::actingAs($staff)
            ->test(IgnoredMissingTeachers::class, ['examSession' => $session])
            ->set("selection.{$subject->id}.BSAI 1A", (string) $teacher->id)
            ->call('assignTeacher', $subject->id, 'BSAI 1A');

        $this->assertSame(2, Enrollment::where('subject_id', $subject->id)->where('teacher_id', $teacher->id)->count());
        $this->assertSame([], $session->fresh()->ignored_missing_teacher_sections);
    }

    public function test_restoring_one_pair_only_affects_that_pair(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create(['code' => 'CS101']);
        $subjectB = Subject::factory()->create(['code' => 'CS202']);
        $this->enroll($session, $subjectA, 'BSAI 1A');
        $this->enroll($session, $subjectB, 'BSAI 1A');
        $session->update(['ignored_missing_teacher_sections' => [
            MissingTeacherSections::key($subjectA->id, 'BSAI 1A'),
            MissingTeacherSections::key($subjectB->id, 'BSAI 1A'),
        ]]);

        Livewire::actingAs($staff)
            ->test(IgnoredMissingTeachers::class, ['examSession' => $session])
            ->call('restoreToMissingList', $subjectA->id, 'BSAI 1A');

        $remaining = $session->fresh()->ignored_missing_teacher_sections;
        $this->assertSame([MissingTeacherSections::key($subjectB->id, 'BSAI 1A')], $remaining);
    }

    public function test_restore_all_clears_the_whole_ignored_list(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enroll($session, $subject, 'BSAI 1A');
        $this->ignorePair($session, $subject->id, 'BSAI 1A');

        Livewire::actingAs($staff)
            ->test(IgnoredMissingTeachers::class, ['examSession' => $session])
            ->call('restoreAll');

        $this->assertSame([], $session->fresh()->ignored_missing_teacher_sections);
    }

    public function test_user_without_manage_sessions_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_sessions', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.missing-teachers.ignored', $session))
            ->assertForbidden();
    }

    public function test_ignored_list_page_stays_consistent_with_the_main_card_after_ignore_all(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enroll($session, $subject, 'BSAI 1A');

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('ignoreAllMissingTeachers');

        $ignoredSections = Livewire::actingAs($staff)
            ->test(IgnoredMissingTeachers::class, ['examSession' => $session->fresh()])
            ->viewData('ignoredSections');

        $this->assertCount(1, $ignoredSections);
    }
}
