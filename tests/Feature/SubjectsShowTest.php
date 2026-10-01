<?php

namespace Tests\Feature;

use App\Livewire\Subjects\Show;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubjectsShowTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function enroll(ExamSession $session, Subject $subject, string $section): Enrollment
    {
        $student = Student::factory()->for($session)->create();

        return Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'section' => $section,
        ]);
    }

    public function test_user_without_manage_subjects_permission_is_forbidden(): void
    {
        $staff = $this->staff();
        $staff->permissionOverrides()->create(['permission' => 'manage_subjects', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create();

        $this->actingAs($staff)
            ->get(route('sessions.subjects.show', [$session, $subject]))
            ->assertForbidden();
    }

    public function test_a_subject_from_another_session_cannot_be_opened(): void
    {
        $session = ExamSession::factory()->create();
        $foreign = Subject::factory()->for(ExamSession::factory()->create())->create();

        $this->actingAs($this->staff())
            ->get(route('sessions.subjects.show', [$session, $foreign]))
            ->assertRedirect(route('dashboard'));
    }

    public function test_sections_are_listed_with_student_counts(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create();
        $this->enroll($session, $subject, 'BSCS 1A');
        $this->enroll($session, $subject, 'BSCS 1A');
        $this->enroll($session, $subject, 'BSCS 1B');

        $component = Livewire::actingAs($this->staff())->test(Show::class, ['examSession' => $session, 'subjectId' => $subject->id]);

        $sections = $component->viewData('sections')->keyBy('section');
        $this->assertSame(2, $sections['BSCS 1A']->student_count);
        $this->assertSame(1, $sections['BSCS 1B']->student_count);
    }

    public function test_merging_sections_relabels_every_enrollment_onto_the_survivor(): void
    {
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $subject = Subject::factory()->for($session)->create();
        $a = $this->enroll($session, $subject, 'BSCS 1A');
        $b = $this->enroll($session, $subject, 'BSCS 1A ');
        $c = $this->enroll($session, $subject, 'BSCS 1B');

        Livewire::actingAs($this->staff())
            ->test(Show::class, ['examSession' => $session, 'subjectId' => $subject->id])
            ->set('selected', ['BSCS 1A', 'BSCS 1A '])
            ->call('openMergeModal')
            ->assertSet('showMergeModal', true)
            ->assertDispatched('open-modal', 'merge-sections')
            ->set('keepSection', 'BSCS 1A')
            ->call('confirmMerge')
            ->assertSet('showMergeModal', false)
            ->assertSet('selected', [])
            // The modal must close via this server-dispatched event, not a
            // same-click x-on:click on the Merge button — that would fire
            // immediately regardless of whether wire:confirm's dialog was
            // accepted, closing the modal even when the merge never ran.
            ->assertDispatched('close-modal', 'merge-sections');

        $this->assertSame('BSCS 1A', $a->fresh()->section);
        $this->assertSame('BSCS 1A', $b->fresh()->section);
        $this->assertSame('BSCS 1B', $c->fresh()->section, 'an unselected section is left alone');
    }

    public function test_opening_the_merge_modal_with_fewer_than_two_selected_shows_an_error(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create();
        $this->enroll($session, $subject, 'BSCS 1A');

        Livewire::actingAs($this->staff())
            ->test(Show::class, ['examSession' => $session, 'subjectId' => $subject->id])
            ->set('selected', ['BSCS 1A'])
            ->call('openMergeModal')
            ->assertSet('showMergeModal', false)
            ->assertSee('Select at least two sections to merge.');
    }

    public function test_merging_is_blocked_on_a_finalized_session(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $subject = Subject::factory()->for($session)->create();
        $a = $this->enroll($session, $subject, 'BSCS 1A');
        $b = $this->enroll($session, $subject, 'BSCS 1A ');

        Livewire::actingAs($this->staff())
            ->test(Show::class, ['examSession' => $session, 'subjectId' => $subject->id])
            ->set('selected', ['BSCS 1A', 'BSCS 1A '])
            ->set('keepSection', 'BSCS 1A')
            ->call('confirmMerge');

        $this->assertSame('BSCS 1A', $a->fresh()->section, 'a finalized session is never touched');
        $this->assertSame('BSCS 1A ', $b->fresh()->section, 'a finalized session is never touched');
    }

    public function test_merging_only_touches_this_subjects_enrollments(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create();
        $otherSubject = Subject::factory()->for($session)->create();
        $a = $this->enroll($session, $subject, 'BSCS 1A');
        $b = $this->enroll($session, $subject, 'BSCS 1A ');
        $unrelated = $this->enroll($session, $otherSubject, 'BSCS 1A ');

        Livewire::actingAs($this->staff())
            ->test(Show::class, ['examSession' => $session, 'subjectId' => $subject->id])
            ->set('selected', ['BSCS 1A', 'BSCS 1A '])
            ->set('keepSection', 'BSCS 1A')
            ->call('confirmMerge');

        $this->assertSame('BSCS 1A', $b->fresh()->section);
        $this->assertSame('BSCS 1A ', $unrelated->fresh()->section, "another subject's same-named section is untouched");
    }
}
