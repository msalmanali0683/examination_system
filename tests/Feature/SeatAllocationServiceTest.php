<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SessionRoom;
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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
        $roomA = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $roomB = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $roomA->id, 'is_active' => true]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $roomB->id, 'is_active' => true]);
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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

    public function test_insufficient_capacity_produces_warnings(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        $room = Room::factory()->create(['rows' => 1, 'columns' => 1, 'capacity' => 1]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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
        $activeRoom = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $inactiveRoom = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $activeRoom->id, 'is_active' => true]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $inactiveRoom->id, 'is_active' => false]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 2);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);

        (new SeatAllocationService)->generate($session->fresh());

        $this->assertDatabaseMissing('seat_assignments', ['room_id' => $inactiveRoom->id]);
        $this->assertDatabaseHas('seat_assignments', ['room_id' => $activeRoom->id]);
    }
}
