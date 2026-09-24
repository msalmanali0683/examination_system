<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Services\Generation\SeatAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $rollNoSequence = 0;

    private function enrollStudents(ExamSession $session, Subject $subject, string $section, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'section' => $section,
            ]);
        }
    }

    public function test_strict_strategy_seats_a_subject_section_in_one_room_column_by_column(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 3);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);

        (new SeatAllocationService)->generate($session->fresh());

        $seats = SeatAssignment::where('seat_assignments.exam_session_id', $session->id)
            ->join('enrollments', 'enrollments.id', '=', 'seat_assignments.enrollment_id')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->orderBy('students.roll_no')
            ->get(['seat_assignments.row_number', 'seat_assignments.column_number', 'seat_assignments.room_id']);

        $this->assertCount(3, $seats);
        // Column-by-column: rows 1,2,3 of column 1 (room has 5 rows).
        $this->assertSame([1, 1], [$seats[0]->row_number, $seats[0]->column_number]);
        $this->assertSame([2, 1], [$seats[1]->row_number, $seats[1]->column_number]);
        $this->assertSame([3, 1], [$seats[2]->row_number, $seats[2]->column_number]);
        $this->assertTrue($seats->every(fn ($s) => $s->room_id === $room->id));
    }

    public function test_two_subjects_in_the_same_slot_never_share_a_room_under_strict(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $roomA = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $roomB = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $this->enrollStudents($session, $subjectA, 'BSAI 1A', 2);
        $this->enrollStudents($session, $subjectB, 'BSAI 1B', 2);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'time_slot_id' => $slot->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'time_slot_id' => $slot->id]);

        (new SeatAllocationService)->generate($session->fresh());

        $roomsUsedByA = SeatAssignment::whereHas('enrollment', fn ($q) => $q->where('subject_id', $subjectA->id))->pluck('room_id')->unique();
        $roomsUsedByB = SeatAssignment::whereHas('enrollment', fn ($q) => $q->where('subject_id', $subjectB->id))->pluck('room_id')->unique();

        $this->assertCount(1, $roomsUsedByA);
        $this->assertCount(1, $roomsUsedByB);
        $this->assertNotSame($roomsUsedByA->first(), $roomsUsedByB->first());
    }

    public function test_locked_seats_are_preserved_on_regeneration(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 3);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);

        $service = new SeatAllocationService;
        $service->generate($session->fresh());

        // Manually lock the first-seated enrollment to a different seat.
        $firstSeat = SeatAssignment::where('exam_session_id', $session->id)->orderBy('id')->first();
        $firstSeat->update(['row_number' => 5, 'column_number' => 2, 'is_locked' => true]);

        // Regenerate — the locked seat must stay exactly as placed, and no
        // other seat should now occupy (5, 2) in that room/slot.
        $service->generate($session->fresh());

        $this->assertDatabaseHas('seat_assignments', [
            'id' => $firstSeat->id,
            'row_number' => 5,
            'column_number' => 2,
            'is_locked' => true,
        ]);

        $occupantsOfLockedSeat = SeatAssignment::where('room_id', $room->id)
            ->where('time_slot_id', $slot->id)
            ->where('row_number', 5)
            ->where('column_number', 2)
            ->count();

        $this->assertSame(1, $occupantsOfLockedSeat);
    }

    public function test_regenerating_after_a_subject_moves_to_a_different_slot_does_not_crash(): void
    {
        // Regression: seat_assignments.enrollment_id is unique across the
        // whole session, but the old persist() only cleared the *current*
        // slot's rows. If a subject moves to a different slot between two
        // "Generate Seating" runs (e.g. because the timetable was
        // regenerated), the stale row from its old slot collided with the
        // new insert and crashed with a duplicate-key error.
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slotA = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        $slotB = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 3);
        $assignment = SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slotA->id]);

        $service = new SeatAllocationService;
        $service->generate($session->fresh());
        $this->assertDatabaseHas('seat_assignments', ['exam_session_id' => $session->id, 'time_slot_id' => $slotA->id]);

        // The subject's timetable slot changes, as it would after a real
        // "Generate Timetable" rerun moved it — then seating is generated
        // again without ever clearing slot A's now-stale row directly.
        $assignment->update(['time_slot_id' => $slotB->id]);
        $service->generate($session->fresh());

        $seats = SeatAssignment::where('exam_session_id', $session->id)->get();
        $this->assertCount(3, $seats);
        $this->assertTrue($seats->every(fn ($s) => $s->time_slot_id === $slotB->id));
    }

    public function test_insufficient_capacity_produces_warnings(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $room = Room::factory()->for($session)->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 3);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);

        $result = (new SeatAllocationService)->generate($session->fresh());

        $this->assertCount(2, $result->warnings);
        $this->assertDatabaseCount('seat_assignments', 1);
    }

    public function test_inactive_session_rooms_are_not_used(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $activeRoom = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $inactiveRoom = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10, 'is_active' => false]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 2);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);

        (new SeatAllocationService)->generate($session->fresh());

        $this->assertDatabaseMissing('seat_assignments', ['room_id' => $inactiveRoom->id]);
        $this->assertDatabaseHas('seat_assignments', ['room_id' => $activeRoom->id]);
    }

    public function test_strict_overflow_subject_strategy_fills_leftover_seats_with_a_different_subject(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict_overflow_subject']);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $primarySubject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();
        $this->enrollStudents($session, $primarySubject, 'BSAI 1A', 6);
        $this->enrollStudents($session, $otherSubject, 'BSCS 1A', 4);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $primarySubject->id, 'time_slot_id' => $slot->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $otherSubject->id, 'time_slot_id' => $slot->id]);

        $result = (new SeatAllocationService)->generate($session->fresh());

        $this->assertTrue($result->warnings->isEmpty());
        // Both subjects' students land in the one room instead of one of
        // them needing a second room for a leftover handful of seats.
        $seats = SeatAssignment::where('exam_session_id', $session->id)->get();
        $this->assertCount(10, $seats);
        $this->assertTrue($seats->every(fn ($s) => $s->room_id === $room->id));
        $this->assertSame(
            $seats->pluck('row_number')->zip($seats->pluck('column_number'))->map(fn ($pair) => $pair->implode(':'))->unique()->count(),
            $seats->count(),
            'no two students should ever share a seat'
        );
    }

    public function test_strict_overflow_section_then_subject_strategy_prefers_a_section_but_falls_back_to_another_subject(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict_overflow_section_then_subject']);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $primarySubject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();
        // Primary section A (6) leaves 4 leftover seats: section B (2) of
        // the same subject only covers half of them, so the other
        // subject's 2 students must fill the rest — proving the real
        // strategyFor() wiring picks the combined strategy, not just the
        // unit-level class.
        $this->enrollStudents($session, $primarySubject, 'BSAI 1A', 6);
        $this->enrollStudents($session, $primarySubject, 'BSAI 1B', 2);
        $this->enrollStudents($session, $otherSubject, 'BSCS 1A', 2);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $primarySubject->id, 'time_slot_id' => $slot->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $otherSubject->id, 'time_slot_id' => $slot->id]);

        $result = (new SeatAllocationService)->generate($session->fresh());

        $this->assertTrue($result->warnings->isEmpty());
        $seats = SeatAssignment::where('exam_session_id', $session->id)->get();
        $this->assertCount(10, $seats);
        $this->assertTrue($seats->every(fn ($s) => $s->room_id === $room->id));
    }
}
