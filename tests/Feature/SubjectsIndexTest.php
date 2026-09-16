<?php

namespace Tests\Feature;

use App\Livewire\Subjects\Index;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubjectsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_manage_subjects_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_subjects', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/subjects')
            ->assertForbidden();
    }

    public function test_subjects_are_listed_and_searchable(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        Subject::factory()->create(['code' => 'CS02115|11', 'title' => 'Programming Fundamentals']);
        Subject::factory()->create(['code' => 'MAT10130|11', 'title' => 'Pre-Calculus I']);

        $component = Livewire::actingAs($staff)->test(Index::class);
        $this->assertCount(2, $component->viewData('subjects'));

        $component->set('search', 'Pre-Calculus');
        $this->assertCount(1, $component->viewData('subjects'));
        $this->assertSame('MAT10130|11', $component->viewData('subjects')->first()->code);
    }

    public function test_merging_two_subjects_moves_enrollments_and_flags_the_merged_one(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $keep = Subject::factory()->create(['code' => 'EE07205|11', 'title' => 'Digital Logic and Design']);
        $mergeAway = Subject::factory()->create(['code' => 'EES07104|11', 'title' => 'Digital Logic Design']);
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $mergeAway->id,
        ]);

        Livewire::actingAs($staff)
            ->test(Index::class)
            // Checkbox values arrive as strings in real usage (unlike a
            // plain PHP array of int ids) — regression coverage for a bug
            // where a strict in_array() comparison against an (int)-cast
            // survivor id wrongly rejected a genuinely selected subject.
            ->set('selected', [(string) $keep->id, (string) $mergeAway->id])
            ->call('openMergeModal')
            ->assertSet('showMergeModal', true)
            ->assertDispatched('open-modal', 'merge-subjects')
            ->set('survivorId', (string) $keep->id)
            ->call('confirmMerge')
            ->assertSet('showMergeModal', false)
            ->assertSet('selected', [])
            // The modal must close via this server-dispatched event, not
            // a same-click x-on:click on the Merge button — that would
            // fire immediately regardless of whether wire:confirm's
            // dialog was accepted, closing the modal even when the
            // merge never actually ran.
            ->assertDispatched('close-modal', 'merge-subjects');

        $this->assertSame($keep->id, $enrollment->fresh()->subject_id);
        $this->assertSame($keep->id, $mergeAway->fresh()->merged_into_id);
    }

    public function test_opening_the_merge_modal_with_fewer_than_two_selected_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $subject = Subject::factory()->create();

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('selected', [$subject->id])
            ->call('openMergeModal')
            ->assertSet('showMergeModal', false);
    }

    public function test_select_all_on_page_excludes_already_merged_subjects(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $active = Subject::factory()->count(2)->create();
        $survivor = Subject::factory()->create();
        $merged = Subject::factory()->create(['merged_into_id' => $survivor->id]);

        $pageIds = [...$active->pluck('id')->all(), $survivor->id];

        $component = Livewire::actingAs($staff)->test(Index::class);
        $component->call('toggleSelectAllOnPage', $pageIds);

        $this->assertEqualsCanonicalizing($pageIds, $component->get('selected'));
        $this->assertNotContains($merged->id, $component->get('selected'));
    }
}
