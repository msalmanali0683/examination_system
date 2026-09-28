<?php

namespace Tests\Feature;

use App\Livewire\Subjects\Index;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubjectsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    public function test_user_without_manage_subjects_permission_is_forbidden(): void
    {
        $staff = $this->staff();
        $staff->permissionOverrides()->create(['permission' => 'manage_subjects', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.subjects.index', $session))
            ->assertForbidden();
    }

    public function test_subjects_are_listed_and_searchable(): void
    {
        $session = ExamSession::factory()->create();
        Subject::factory()->for($session)->create(['code' => 'CS02115|11', 'title' => 'Programming Fundamentals']);
        Subject::factory()->for($session)->create(['code' => 'MAT10130|11', 'title' => 'Pre-Calculus I']);

        $component = Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session]);
        $this->assertCount(2, $component->viewData('subjects'));

        $component->set('search', 'Pre-Calculus');
        $this->assertCount(1, $component->viewData('subjects'));
        $this->assertSame('MAT10130|11', $component->viewData('subjects')->first()->code);
    }

    public function test_only_this_sessions_subjects_are_listed(): void
    {
        $session = ExamSession::factory()->create();
        Subject::factory()->for($session)->create(['code' => 'CS101|11']);
        Subject::factory()->for(ExamSession::factory()->create())->create(['code' => 'EE999|11']);

        $component = Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session]);

        $this->assertSame(['CS101|11'], $component->viewData('subjects')->pluck('code')->all());
    }

    public function test_merging_two_subjects_moves_enrollments_and_flags_the_merged_one(): void
    {
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $keep = Subject::factory()->for($session)->create(['code' => 'EE07205|11', 'title' => 'Digital Logic and Design']);
        $mergeAway = Subject::factory()->for($session)->create(['code' => 'EES07104|11', 'title' => 'Digital Logic Design']);
        $student = Student::factory()->for($session)->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $mergeAway->id,
        ]);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
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

    public function test_a_subject_from_another_session_cannot_be_merged_in(): void
    {
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $keep = Subject::factory()->for($session)->create();
        $foreign = Subject::factory()->for(ExamSession::factory()->create())->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('selected', [(string) $keep->id, (string) $foreign->id])
            ->set('survivorId', (string) $keep->id)
            ->call('confirmMerge');

        $this->assertNull($foreign->fresh()->merged_into_id);
    }

    public function test_merging_is_blocked_on_a_finalized_session(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $keep = Subject::factory()->for($session)->create();
        $mergeAway = Subject::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('selected', [(string) $keep->id, (string) $mergeAway->id])
            ->set('survivorId', (string) $keep->id)
            ->call('confirmMerge');

        $this->assertNull($mergeAway->fresh()->merged_into_id);
    }

    public function test_opening_the_merge_modal_with_fewer_than_two_selected_shows_an_error(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('selected', [$subject->id])
            ->call('openMergeModal')
            ->assertSet('showMergeModal', false);
    }

    public function test_select_all_on_page_excludes_already_merged_subjects(): void
    {
        $session = ExamSession::factory()->create();
        $active = Subject::factory()->for($session)->count(2)->create();
        $survivor = Subject::factory()->for($session)->create();
        $merged = Subject::factory()->for($session)->create(['merged_into_id' => $survivor->id]);

        $pageIds = [...$active->pluck('id')->all(), $survivor->id];

        $component = Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session]);
        $component->call('toggleSelectAllOnPage', $pageIds);

        $this->assertEqualsCanonicalizing($pageIds, $component->get('selected'));
        $this->assertNotContains($merged->id, $component->get('selected'));
    }

    /** A subject with one student, a timetable slot and a seat, in the given session. */
    private function fullSubject(ExamSession $session, string $code): array
    {
        $subject = Subject::factory()->for($session)->create(['code' => $code]);
        $student = Student::factory()->for($session)->create();
        $enrollment = Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);
        $room = Room::factory()->for($session)->create();
        SeatAssignment::create(['exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1]);

        return [$subject, $student, $enrollment];
    }

    public function test_deleting_a_subject_removes_its_enrollments_seats_and_slot_but_keeps_the_students_and_other_subjects(): void
    {
        $session = ExamSession::factory()->create();
        [$doomed, $student, $enrollment] = $this->fullSubject($session, 'DEL101|11');
        [$kept, $keptStudent, $keptEnrollment] = $this->fullSubject($session, 'KEEP202|11');

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $doomed->id)
            ->assertSee('Deleted subject DEL101|11 and 1 enrollment');

        $this->assertNull(Subject::find($doomed->id));
        $this->assertNull(Enrollment::find($enrollment->id));
        $this->assertSame(0, SeatAssignment::where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(0, SubjectSlotAssignment::where('subject_id', $doomed->id)->count());
        $this->assertNotNull(Student::find($student->id), 'the student stays');

        $this->assertNotNull(Subject::find($kept->id));
        $this->assertNotNull(Enrollment::find($keptEnrollment->id));
        $this->assertSame(1, SeatAssignment::where('enrollment_id', $keptEnrollment->id)->count());
        $this->assertSame(1, SubjectSlotAssignment::where('subject_id', $kept->id)->count());

        $this->assertDatabaseHas('activity_logs', ['exam_session_id' => $session->id, 'action' => 'subjects.deleted']);
    }

    public function test_bulk_delete_removes_every_ticked_subject_only(): void
    {
        $session = ExamSession::factory()->create();
        $a = Subject::factory()->for($session)->create(['code' => 'A|11']);
        $b = Subject::factory()->for($session)->create(['code' => 'B|11']);
        $c = Subject::factory()->for($session)->create(['code' => 'C|11']);
        $foreign = Subject::factory()->for(ExamSession::factory()->create())->create(['code' => 'F|11']);

        $page = Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('selected', [(string) $a->id, (string) $b->id, (string) $foreign->id])
            ->call('bulkDelete')
            ->assertSet('selected', [])
            ->assertSee('Deleted 2 subjects');

        $this->assertNull(Subject::find($a->id));
        $this->assertNull(Subject::find($b->id));
        $this->assertNotNull(Subject::find($c->id));
        $this->assertNotNull(Subject::find($foreign->id), "another session's subject is never touched");
    }

    public function test_bulk_delete_with_nothing_ticked_says_so(): void
    {
        $session = ExamSession::factory()->create();
        Subject::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('bulkDelete')
            ->assertSee('Select at least one subject');

        $this->assertSame(1, $session->subjects()->count());
    }

    public function test_a_subject_of_another_session_cannot_be_deleted_from_here(): void
    {
        $mine = ExamSession::factory()->create();
        $theirs = ExamSession::factory()->create();
        $foreign = Subject::factory()->for($theirs)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $mine])
            ->call('deleteSubject', $foreign->id)
            ->assertSee('no longer exists');

        $this->assertNotNull(Subject::find($foreign->id));
    }

    public function test_deleting_a_survivor_also_removes_the_retired_codes_merged_into_it(): void
    {
        $session = ExamSession::factory()->create();
        $survivor = Subject::factory()->for($session)->create(['code' => 'EE07205|11']);
        $alias = Subject::factory()->for($session)->create(['code' => 'EES07104|11', 'merged_into_id' => $survivor->id]);
        $other = Subject::factory()->for($session)->create(['code' => 'OTHER|11']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $survivor->id)
            ->assertSee('and 1 retired merged code');

        $this->assertNull(Subject::find($survivor->id));
        $this->assertNull(Subject::find($alias->id), 'no empty "Active" ghost is left behind');
        $this->assertNotNull(Subject::find($other->id));
    }

    public function test_deleting_a_retired_merged_code_leaves_its_survivor_alone(): void
    {
        $session = ExamSession::factory()->create();
        $survivor = Subject::factory()->for($session)->create();
        $alias = Subject::factory()->for($session)->create(['merged_into_id' => $survivor->id]);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $alias->id);

        $this->assertNull(Subject::find($alias->id));
        $this->assertNotNull(Subject::find($survivor->id));
    }

    public function test_deleting_is_blocked_on_a_finalized_session(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $subject = Subject::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $subject->id)
            ->set('selected', [(string) $subject->id])
            ->call('bulkDelete');

        $this->assertNotNull(Subject::find($subject->id));
    }

    public function test_a_user_without_manage_subjects_cannot_delete(): void
    {
        $staff = $this->staff();
        $staff->permissionOverrides()->create(['permission' => 'manage_subjects', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create();

        $this->actingAs($staff)->get(route('sessions.subjects.index', $session))->assertForbidden();

        try {
            Livewire::actingAs($staff)->test(Index::class, ['examSession' => $session])->call('deleteSubject', $subject->id);
        } catch (\Throwable) {
            // refusing is the expected outcome
        }

        $this->assertNotNull(Subject::find($subject->id));
    }

    public function test_the_page_offers_delete_per_row_and_for_the_selection(): void
    {
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS-500']);

        $page = Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session]);

        $page->assertSee('aria-label="Delete CS-500"', false);
        $page->assertDontSee('Delete Selected');
        $page->set('selected', [(string) $subject->id])->assertSee('Delete Selected');
    }

    public function test_deleting_from_a_generated_session_sends_it_back_to_draft_and_says_to_regenerate(): void
    {
        $session = ExamSession::factory()->create(['status' => 'generated']);
        [$doomed] = $this->fullSubject($session, 'DEL303|11');
        $this->fullSubject($session, 'STAY404|11');

        $page = Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $doomed->id)
            ->assertSet('needsRegeneration', true)
            ->assertSee('need to be regenerated')
            ->assertSee('Regenerate them in this order');

        $this->assertSame('draft', $session->fresh()->status, 'a generated session drops back to draft, like after removing all enrollments');

        // the banner walks through the pipeline in the right order, with working links
        $html = $page->html();
        $this->assertStringContainsString(route('sessions.timetable', $session), $html);
        $this->assertStringContainsString(route('sessions.seating', $session), $html);
        $this->assertStringContainsString(route('sessions.duties', $session), $html);
        $this->assertLessThan(strpos($html, route('sessions.seating', $session)), strpos($html, route('sessions.timetable', $session)));
        $this->assertLessThan(strpos($html, route('sessions.duties', $session)), strpos($html, route('sessions.seating', $session)));
    }

    public function test_deleting_when_only_a_timetable_exists_still_asks_for_regeneration(): void
    {
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $subject = Subject::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);
        $other = Subject::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $other->id)
            ->assertSet('needsRegeneration', true);

        $this->assertSame('draft', $session->fresh()->status);
    }

    public function test_deleting_from_a_session_with_nothing_generated_does_not_nag_about_regeneration(): void
    {
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $subject = Subject::factory()->for($session)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteSubject', $subject->id)
            ->assertSet('needsRegeneration', false)
            ->assertDontSee('Regenerate them in this order')
            ->assertDontSee('need to be regenerated');
    }
}
