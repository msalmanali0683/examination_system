<?php

namespace Tests\Feature;

use App\Livewire\Sessions\CapacityCheck;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Generation\SlotSharingAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SlotSharingAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(int $rooms, int $capacity): ExamSession
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        Room::factory()->for($session)->count($rooms)->create(['rows' => $capacity, 'columns' => 1, 'capacity' => $capacity]);

        return $session;
    }

    private function slot(ExamSession $session, string $date, string $time): TimeSlot
    {
        return TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => $date, 'start_time' => $time]);
    }

    /** A subject with $count brand-new students (so it shares nobody with any other subject). */
    private function subject(ExamSession $session, int $count, string $code): Subject
    {
        $subject = Subject::factory()->for($session)->create(['code' => $code, 'title' => "Title {$code}"]);

        for ($i = 0; $i < $count; $i++) {
            $student = Student::factory()->create();
            $this->enroll($session, $subject, $student);
        }

        return $subject;
    }

    private function enroll(ExamSession $session, Subject $subject, Student $student): void
    {
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1A',
        ]);
    }

    private function place(ExamSession $session, Subject $subject, TimeSlot $slot, bool $pinned = false): void
    {
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'is_pinned' => $pinned,
        ]);
    }

    private function analyze(ExamSession $session)
    {
        return (new SlotSharingAdvisor)->analyze($session)->keyBy('code');
    }

    public function test_a_subject_alone_in_its_slot_is_reported_as_fitting_beside_another_when_the_rooms_can_seat_both(): void
    {
        $session = $this->makeSession(rooms: 2, capacity: 3);
        $a = $this->subject($session, 3, 'AAA');
        $b = $this->subject($session, 3, 'BBB');
        $this->place($session, $a, $this->slot($session, '2026-04-20', '09:00'));
        $this->place($session, $b, $this->slot($session, '2026-04-21', '09:00'));

        $rows = $this->analyze($session);

        $this->assertSame('fits', $rows['BBB']['status']);
        $this->assertSame(0, $rows['BBB']['seatsShort']);
        $this->assertSame('AAA', $rows['BBB']['with']);
        $this->assertSame('20 Apr 2026 09:00', $rows['BBB']['toLabel']);
        $this->assertSame('21 Apr 2026 09:00', $rows['BBB']['fromLabel']);
    }

    public function test_it_says_how_many_more_seats_are_needed_when_the_rooms_cannot_seat_both(): void
    {
        // One 3-seat room: each subject needs a whole room under Strict, so sharing would leave 3 students
        // of the newcomer without a seat — 3 more seats (one more room of this size).
        $session = $this->makeSession(rooms: 1, capacity: 3);
        $a = $this->subject($session, 3, 'AAA');
        $b = $this->subject($session, 3, 'BBB');
        $this->place($session, $a, $this->slot($session, '2026-04-20', '09:00'));
        $this->place($session, $b, $this->slot($session, '2026-04-21', '09:00'));

        $rows = $this->analyze($session);

        $this->assertSame('short', $rows['BBB']['status']);
        $this->assertSame(3, $rows['BBB']['seatsShort']);
        $this->assertSame(1, $rows['BBB']['roomsHint']);
    }

    public function test_subjects_that_share_a_student_have_no_clash_free_slot_to_join(): void
    {
        $session = $this->makeSession(rooms: 2, capacity: 3);
        $a = $this->subject($session, 2, 'AAA');
        $b = $this->subject($session, 2, 'BBB');
        $this->enroll($session, $b, Student::whereHas('enrollments', fn ($q) => $q->where('subject_id', $a->id))->first());
        $this->place($session, $a, $this->slot($session, '2026-04-20', '09:00'));
        $this->place($session, $b, $this->slot($session, '2026-04-21', '09:00'));

        $rows = $this->analyze($session);

        $this->assertSame('none', $rows['AAA']['status']);
        $this->assertSame('none', $rows['BBB']['status']);
        $this->assertNull($rows['BBB']['toLabel']);
    }

    public function test_the_best_option_is_the_one_needing_the_fewest_extra_seats(): void
    {
        // Slot 1 holds a big subject (rooms would overflow), slot 2 a small one that fits beside it.
        $session = $this->makeSession(rooms: 2, capacity: 3);
        $big = $this->subject($session, 6, 'BIG');
        $small = $this->subject($session, 1, 'SML');
        $mover = $this->subject($session, 3, 'MOV');
        $this->place($session, $big, $this->slot($session, '2026-04-20', '09:00'));
        $this->place($session, $small, $this->slot($session, '2026-04-21', '09:00'));
        $this->place($session, $mover, $this->slot($session, '2026-04-22', '09:00'));

        $rows = $this->analyze($session);

        $this->assertSame('fits', $rows['MOV']['status']);
        $this->assertSame('SML', $rows['MOV']['with']);
    }

    public function test_a_pinned_subject_is_not_suggested_for_moving(): void
    {
        $session = $this->makeSession(rooms: 2, capacity: 3);
        $a = $this->subject($session, 3, 'AAA');
        $b = $this->subject($session, 3, 'BBB');
        $this->place($session, $a, $this->slot($session, '2026-04-20', '09:00'));
        $this->place($session, $b, $this->slot($session, '2026-04-21', '09:00'), pinned: true);

        $rows = $this->analyze($session);

        $this->assertTrue($rows->has('AAA'));
        $this->assertFalse($rows->has('BBB'));
    }

    public function test_a_subject_that_already_shares_its_slot_is_left_out(): void
    {
        $session = $this->makeSession(rooms: 3, capacity: 3);
        $a = $this->subject($session, 3, 'AAA');
        $b = $this->subject($session, 3, 'BBB');
        $c = $this->subject($session, 3, 'CCC');
        $first = $this->slot($session, '2026-04-20', '09:00');
        $this->place($session, $a, $first);
        $this->place($session, $b, $first);
        $this->place($session, $c, $this->slot($session, '2026-04-21', '09:00'));

        $rows = $this->analyze($session);

        $this->assertSame(['CCC'], $rows->keys()->all());
    }

    public function test_there_is_nothing_to_check_with_fewer_than_two_placed_subjects(): void
    {
        $session = $this->makeSession(rooms: 2, capacity: 3);
        $a = $this->subject($session, 3, 'AAA');
        $this->place($session, $a, $this->slot($session, '2026-04-20', '09:00'));

        $this->assertTrue((new SlotSharingAdvisor)->analyze($session)->isEmpty());
    }

    public function test_a_same_semester_subject_that_shares_students_blocks_the_move_onto_its_day(): void
    {
        // Day 1 has three slots. B sits in slot 1, W (same semester as A, and sharing a student with A) in
        // slot 2 — one slot apart, not the day's maximum separation (two). So A moving onto slot 1 would
        // put it too close to W on the same day: blocked.
        $session = $this->makeSession(rooms: 3, capacity: 5);
        $a = $this->subject($session, 2, 'AAA');
        $b = $this->subject($session, 2, 'BBB');
        $w = $this->subject($session, 2, 'WWW');
        $this->enroll($session, $w, Student::whereHas('enrollments', fn ($q) => $q->where('subject_id', $a->id))->first());

        $day1Slot1 = $this->slot($session, '2026-04-20', '09:00');
        $day1Slot2 = $this->slot($session, '2026-04-20', '11:00');
        $this->slot($session, '2026-04-20', '13:00');
        $day2Slot1 = $this->slot($session, '2026-04-21', '09:00');

        $this->place($session, $b, $day1Slot1);
        $this->place($session, $w, $day1Slot2);
        $this->place($session, $a, $day2Slot1);

        $this->assertSame('none', $this->analyze($session)['AAA']['status']);
    }

    public function test_a_same_semester_subject_at_the_days_maximum_separation_does_not_block_the_move(): void
    {
        // Same as above but W sits in the day's last slot: first and last is the accepted maximum
        // separation, so A may join B in slot 1.
        $session = $this->makeSession(rooms: 3, capacity: 5);
        $a = $this->subject($session, 2, 'AAA');
        $b = $this->subject($session, 2, 'BBB');
        $w = $this->subject($session, 2, 'WWW');
        $this->enroll($session, $w, Student::whereHas('enrollments', fn ($q) => $q->where('subject_id', $a->id))->first());

        $day1Slot1 = $this->slot($session, '2026-04-20', '09:00');
        $this->slot($session, '2026-04-20', '11:00');
        $day1Slot3 = $this->slot($session, '2026-04-20', '13:00');
        $day2Slot1 = $this->slot($session, '2026-04-21', '09:00');

        $this->place($session, $b, $day1Slot1);
        $this->place($session, $w, $day1Slot3);
        $this->place($session, $a, $day2Slot1);

        $row = $this->analyze($session)['AAA'];
        $this->assertSame('fits', $row['status']);
        $this->assertSame('BBB', $row['with']);
    }

    public function test_the_capacity_check_page_runs_the_check_on_demand(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = $this->makeSession(rooms: 1, capacity: 3);
        $a = $this->subject($session, 3, 'AAA');
        $b = $this->subject($session, 3, 'BBB');
        $this->place($session, $a, $this->slot($session, '2026-04-20', '09:00'));
        $this->place($session, $b, $this->slot($session, '2026-04-21', '09:00'));

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->assertSet('showSharing', false)
            ->assertDontSee('Needs 3 more seats')
            ->call('checkSharing')
            ->assertSet('showSharing', true)
            ->assertSee('Needs 3 more seats')
            ->assertSee('Would share, but need more seats');
    }

    public function test_the_check_is_forbidden_without_the_generate_roster_permission(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'generate_roster', 'granted' => false]);
        $session = $this->makeSession(rooms: 1, capacity: 3);

        Livewire::actingAs($staff)
            ->test(CapacityCheck::class, ['examSession' => $session])
            ->assertForbidden();
    }
}
