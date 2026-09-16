<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Services\SubjectMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SubjectMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollments_move_onto_the_surviving_subject(): void
    {
        $keep = Subject::factory()->create(['code' => 'EE07205|11']);
        $mergeAway = Subject::factory()->create(['code' => 'EES07104|11']);
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $mergeAway->id,
        ]);

        $stats = (new SubjectMergeService)->merge($keep, $mergeAway);

        $this->assertSame($keep->id, $enrollment->fresh()->subject_id);
        $this->assertSame(1, $stats['enrollmentsMoved']);
        $this->assertSame(0, $stats['enrollmentsDropped']);
        $this->assertSame(0, $stats['sessionsSkipped']);
        $this->assertSame($keep->id, $mergeAway->fresh()->merged_into_id);
        $this->assertTrue($mergeAway->fresh()->isMerged());
    }

    public function test_a_student_already_enrolled_in_both_subjects_drops_the_duplicate(): void
    {
        $keep = Subject::factory()->create();
        $mergeAway = Subject::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $student = Student::factory()->create();

        Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $keep->id,
        ]);
        $duplicate = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $mergeAway->id,
        ]);

        $stats = (new SubjectMergeService)->merge($keep, $mergeAway);

        $this->assertDatabaseMissing('enrollments', ['id' => $duplicate->id]);
        $this->assertSame(0, $stats['enrollmentsMoved']);
        $this->assertSame(1, $stats['enrollmentsDropped']);
        // The surviving enrollment is untouched, so there's still exactly
        // one row for this student under the survivor.
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_finalized_sessions_keep_their_original_subject_untouched(): void
    {
        $keep = Subject::factory()->create();
        $mergeAway = Subject::factory()->create();
        $finalized = ExamSession::factory()->create(['status' => 'finalized']);
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $finalized->id, 'student_id' => $student->id, 'subject_id' => $mergeAway->id,
        ]);

        $stats = (new SubjectMergeService)->merge($keep, $mergeAway);

        $this->assertSame($mergeAway->id, $enrollment->fresh()->subject_id);
        $this->assertSame(0, $stats['enrollmentsMoved']);
        $this->assertSame(1, $stats['sessionsSkipped']);
        // The merge is still recorded — future imports resolve to the
        // survivor even though this historical session was left alone.
        $this->assertSame($keep->id, $mergeAway->fresh()->merged_into_id);
    }

    public function test_pinned_slot_assignments_move_and_duplicates_are_dropped(): void
    {
        $keep = Subject::factory()->create();
        $mergeAway = Subject::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $mergeAwayAssignment = SubjectSlotAssignment::create([
            'exam_session_id' => $session->id, 'subject_id' => $mergeAway->id, 'time_slot_id' => $slot->id, 'is_pinned' => true,
        ]);

        $stats = (new SubjectMergeService)->merge($keep, $mergeAway);

        $this->assertSame($keep->id, $mergeAwayAssignment->fresh()->subject_id);
        $this->assertSame(1, $stats['slotAssignmentsMoved']);
        $this->assertSame(0, $stats['slotAssignmentsDropped']);
    }

    public function test_a_slot_assignment_is_dropped_when_the_survivor_already_has_one_that_session(): void
    {
        $keep = Subject::factory()->create();
        $mergeAway = Subject::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'draft']);
        $slotA = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $slotB = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '13:00']);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id, 'subject_id' => $keep->id, 'time_slot_id' => $slotA->id,
        ]);
        $mergeAwayAssignment = SubjectSlotAssignment::create([
            'exam_session_id' => $session->id, 'subject_id' => $mergeAway->id, 'time_slot_id' => $slotB->id,
        ]);

        $stats = (new SubjectMergeService)->merge($keep, $mergeAway);

        $this->assertDatabaseMissing('subject_slot_assignments', ['id' => $mergeAwayAssignment->id]);
        $this->assertSame(0, $stats['slotAssignmentsMoved']);
        $this->assertSame(1, $stats['slotAssignmentsDropped']);
    }

    public function test_a_subject_cannot_be_merged_into_itself(): void
    {
        $subject = Subject::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new SubjectMergeService)->merge($subject, $subject);
    }

    public function test_an_already_merged_subject_cannot_be_merged_again(): void
    {
        $original = Subject::factory()->create();
        $alreadyMerged = Subject::factory()->create(['merged_into_id' => $original->id]);
        $another = Subject::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new SubjectMergeService)->merge($another, $alreadyMerged);
    }
}
