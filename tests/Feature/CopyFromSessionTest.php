<?php

namespace Tests\Feature;

use App\Livewire\Sessions\CopyFromSession;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SessionDataCopier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CopyFromSessionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function page(ExamSession $session, string $type)
    {
        return Livewire::actingAs($this->staff())->test(CopyFromSession::class, ['examSession' => $session, 'type' => $type]);
    }

    public function test_choosing_a_session_shows_its_rooms_with_nothing_ticked(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        Room::factory()->for($source)->count(3)->create();

        $this->page($target, 'rooms')
            ->call('chooseSession', $source->id)
            ->assertSet('selected', [])
            ->assertSee('Import 0 room(s)');
    }

    public function test_the_header_checkbox_ticks_every_importable_row_and_clears_them_again(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        Room::factory()->for($target)->create(['name' => 'ITC-101']);
        Room::factory()->for($source)->create(['name' => 'ITC-101']); // already in the session
        $others = Room::factory()->for($source)->count(3)->create();

        $component = $this->page($target, 'rooms')->call('chooseSession', $source->id);

        $component->call('toggleSelectAll');
        $this->assertEqualsCanonicalizing($others->pluck('id')->map(fn ($id) => (string) $id)->all(), $component->get('selected'));

        $component->call('toggleSelectAll');
        $this->assertSame([], $component->get('selected'));
    }

    public function test_the_page_is_two_steps_pick_a_session_then_pick_rooms(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create(['name' => 'Physics Midterm']);
        Room::factory()->for($source)->create(['name' => 'PHY-LAB-1']);

        $component = $this->page($target, 'rooms');

        // Step 1: the other sessions are listed, their rooms are not.
        $component->assertSee('Physics Midterm')->assertDontSee('PHY-LAB-1');

        // Step 2: opening a session lists its rooms.
        $component->call('chooseSession', $source->id)->assertSee('PHY-LAB-1');

        // ...and it's one click back to the session list.
        $component->call('chooseAnother')->assertSet('sourceSessionId', '')->assertDontSee('PHY-LAB-1');
    }

    public function test_selected_rooms_are_copied_as_new_rows_owned_by_this_session(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        $keep = Room::factory()->for($source)->create(['name' => 'ITC-101', 'rows' => 10, 'columns' => 5, 'capacity' => 40, 'room_type' => 'lab']);
        $leave = Room::factory()->for($source)->create(['name' => 'ITC-102']);

        $this->page($target, 'rooms')
            ->call('chooseSession', $source->id)
            ->set('selected', [(string) $keep->id])
            ->call('importSelected')
            ->assertRedirect(route('sessions.rooms.index', $target));

        $copy = $target->rooms()->where('name', 'ITC-101')->firstOrFail();
        $this->assertNotSame($keep->id, $copy->id);
        $this->assertSame([10, 5, 40, 'lab'], [$copy->rows, $copy->columns, $copy->capacity, $copy->room_type]);
        $this->assertSame(1, $target->rooms()->count());

        // The source is untouched, and the copy is independent of it.
        $this->assertSame(2, $source->rooms()->count());
        $copy->update(['capacity' => 1]);
        $this->assertSame(40, $keep->fresh()->capacity);
        $this->assertDatabaseHas('activity_logs', ['exam_session_id' => $target->id, 'action' => 'rooms.copied']);
    }

    public function test_rooms_already_in_this_session_are_skipped_not_duplicated(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        Room::factory()->for($target)->create(['name' => 'ITC-101', 'capacity' => 10]);
        $dup = Room::factory()->for($source)->create(['name' => 'itc-101 ', 'capacity' => 99]);
        $fresh = Room::factory()->for($source)->create(['name' => 'ITC-102']);

        $this->page($target, 'rooms')
            ->call('chooseSession', $source->id)
            // Even a forced tick can't create a second copy.
            ->set('selected', [(string) $dup->id, (string) $fresh->id])
            ->call('importSelected');

        $this->assertSame(2, $target->rooms()->count());
        $this->assertSame(10, $target->rooms()->where('name', 'ITC-101')->value('capacity'));
    }

    public function test_only_rooms_of_the_chosen_session_can_be_copied(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        $third = ExamSession::factory()->create();
        $mine = Room::factory()->for($source)->create(['name' => 'ITC-101']);
        $theirs = Room::factory()->for($third)->create(['name' => 'SECRET-1']);

        $this->page($target, 'rooms')
            ->call('chooseSession', $source->id)
            ->set('selected', [(string) $mine->id, (string) $theirs->id])
            ->call('importSelected');

        $this->assertSame(['ITC-101'], $target->rooms()->pluck('name')->all());
    }

    public function test_a_session_cannot_be_its_own_source(): void
    {
        $target = ExamSession::factory()->create();
        $room = Room::factory()->for($target)->create();

        $this->page($target, 'rooms')
            ->call('chooseSession', $target->id)
            ->set('selected', [(string) $room->id])
            ->call('importSelected')
            ->assertHasErrors(['sourceSessionId']);

        $this->assertSame(1, $target->rooms()->count());
    }

    public function test_nothing_is_copied_without_a_selection(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        Room::factory()->for($source)->create();

        $this->page($target, 'rooms')
            ->call('chooseSession', $source->id)
            ->call('importSelected')
            ->assertSee('Select at least one room');

        $this->assertSame(0, $target->rooms()->count());
    }

    public function test_teachers_are_copied_with_their_duty_constraints_remapped_to_the_new_rows(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        $teacher = Teacher::factory()->for($source)->create(['name' => 'Huria Ali', 'email' => 'huria@example.com']);
        SessionTeacherConstraint::create([
            'exam_session_id' => $source->id,
            'teacher_id' => $teacher->id,
            'is_excluded' => false,
            'min_duties' => 1,
            'max_duties' => 3,
            'unavailable_days' => [2, 4],
        ]);

        $this->page($target, 'teachers')
            ->call('chooseSession', $source->id)
            ->call('toggleSelectAll')
            ->call('importSelected')
            ->assertRedirect(route('sessions.teachers.index', $target));

        $copy = $target->teachers()->where('email', 'huria@example.com')->firstOrFail();
        $this->assertNotSame($teacher->id, $copy->id);
        $this->assertDatabaseHas('session_teacher_constraints', [
            'exam_session_id' => $target->id,
            'teacher_id' => $copy->id,
            'min_duties' => 1,
            'max_duties' => 3,
        ]);
        $this->assertSame([2, 4], SessionTeacherConstraint::where('teacher_id', $copy->id)->first()->unavailable_days);

        // The source's own constraint still points at the source's teacher.
        $this->assertDatabaseHas('session_teacher_constraints', ['exam_session_id' => $source->id, 'teacher_id' => $teacher->id]);
        $this->assertDatabaseMissing('session_teacher_constraints', ['exam_session_id' => $target->id, 'teacher_id' => $teacher->id]);
    }

    public function test_duty_constraints_can_be_left_behind(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        $teacher = Teacher::factory()->for($source)->create();
        SessionTeacherConstraint::create(['exam_session_id' => $source->id, 'teacher_id' => $teacher->id, 'is_excluded' => true]);

        $this->page($target, 'teachers')
            ->call('chooseSession', $source->id)
            ->call('toggleSelectAll')
            ->set('withConstraints', false)
            ->call('importSelected');

        $this->assertSame(1, $target->teachers()->count());
        $this->assertDatabaseCount('session_teacher_constraints', 1);
    }

    public function test_teachers_already_in_this_session_are_recognised_by_pernr_email_or_name(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        Teacher::factory()->for($target)->create(['name' => 'Someone', 'pernr' => '111', 'email' => null]);
        Teacher::factory()->for($target)->create(['name' => 'Other', 'pernr' => null, 'email' => 'Same@Example.com']);
        Teacher::factory()->for($target)->create(['name' => 'No Contact', 'pernr' => null, 'email' => null]);

        $byPernr = Teacher::factory()->for($source)->create(['name' => 'Renamed', 'pernr' => '111', 'email' => null]);
        $byEmail = Teacher::factory()->for($source)->create(['name' => 'Other Name', 'pernr' => null, 'email' => 'same@example.com']);
        $byName = Teacher::factory()->for($source)->create(['name' => 'no contact', 'pernr' => null, 'email' => null]);
        // Same name but a different email: a different person, not a duplicate.
        $different = Teacher::factory()->for($source)->create(['name' => 'Someone', 'pernr' => null, 'email' => 'other@example.com']);

        $already = (new SessionDataCopier)->teacherIdsAlreadyIn($source, $target)->all();

        $this->assertEqualsCanonicalizing([$byPernr->id, $byEmail->id, $byName->id], $already);
        $this->assertNotContains($different->id, $already);

        $result = (new SessionDataCopier)->copyTeachers($source, $target);
        $this->assertSame(['copied' => 1, 'skipped' => 3], $result);
    }

    public function test_the_finalized_session_refuses_a_copy(): void
    {
        $target = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $source = ExamSession::factory()->create();
        Room::factory()->for($source)->create();

        $this->page($target, 'rooms')
            ->call('chooseSession', $source->id)
            ->call('importSelected');

        $this->assertSame(0, $target->rooms()->count());
    }

    public function test_the_page_lists_other_sessions_only_and_flags_rooms_already_present(): void
    {
        $target = ExamSession::factory()->create(['name' => 'Target Session']);
        $source = ExamSession::factory()->create(['name' => 'Source Session']);
        Room::factory()->for($target)->create(['name' => 'ITC-101']);
        Room::factory()->for($source)->create(['name' => 'ITC-101']);
        Room::factory()->for($source)->create(['name' => 'ITC-102']);

        $component = $this->page($target, 'rooms');
        $component->assertSee('Source Session')->assertDontSee('Target Session (');

        $component->call('chooseSession', $source->id)
            ->assertSee('Already in this session')
            ->assertSee('ITC-102')
            ->assertSee('Import 0 room(s)')
            // Ticking "all" counts only what can actually be imported.
            ->call('toggleSelectAll')
            ->assertSee('Import 1 room(s)');
    }

    public function test_the_type_cannot_be_switched_from_the_browser(): void
    {
        $target = ExamSession::factory()->create();
        $source = ExamSession::factory()->create();
        Teacher::factory()->for($source)->create();

        $this->assertThrows(
            fn () => $this->page($target, 'rooms')->set('type', 'teachers'),
            CannotUpdateLockedPropertyException::class
        );
    }

    public function test_permissions_follow_the_type_being_copied(): void
    {
        $session = ExamSession::factory()->create();

        $noRooms = $this->staff();
        $noRooms->permissionOverrides()->create(['permission' => 'manage_rooms', 'granted' => false]);
        $this->actingAs($noRooms)->get(route('sessions.copy', [$session, 'rooms']))->assertForbidden();
        $this->actingAs($noRooms)->get(route('sessions.copy', [$session, 'teachers']))->assertOk();

        $noTeachers = $this->staff();
        $noTeachers->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);
        $this->actingAs($noTeachers)->get(route('sessions.copy', [$session, 'teachers']))->assertForbidden();
        $this->actingAs($noTeachers)->get(route('sessions.copy', [$session, 'rooms']))->assertOk();
    }

    public function test_only_rooms_and_teachers_can_be_copied(): void
    {
        $session = ExamSession::factory()->create();

        $this->actingAs($this->staff())->get(route('sessions.copy', [$session, 'students']))->assertNotFound();
    }
}
