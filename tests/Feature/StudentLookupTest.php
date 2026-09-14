<?php

namespace Tests\Feature;

use App\Livewire\Sessions\StudentLookup;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_searching_by_roll_no_finds_the_student_and_their_seat(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create(['name' => 'ITC-310']);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create(['code' => 'CS101']);
        $teacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Ms Huria']);
        $student = Student::factory()->create(['roll_no' => '70180938', 'name' => 'Moeez Arif']);

        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1B',
        ]);

        SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => 2,
            'column_number' => 3,
        ]);

        \App\Models\DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        $component = Livewire::actingAs($staff)
            ->test(StudentLookup::class, ['examSession' => $session])
            ->set('query', '70180938');

        $component->assertSee('Moeez Arif')
            ->assertSee('ITC-310')
            ->assertSee('Ms Huria')
            ->assertSee('CS101');
    }

    public function test_a_short_query_shows_no_results(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(StudentLookup::class, ['examSession' => $session])
            ->set('query', 'a')
            ->assertSee('at least 2 characters');
    }

    public function test_an_unmatched_query_shows_an_empty_state(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(StudentLookup::class, ['examSession' => $session])
            ->set('query', 'nonexistent-roll-no')
            ->assertSee('No matching students');
    }
}
