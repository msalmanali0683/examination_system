<?php

namespace Tests\Feature;

use App\Livewire\Sessions\GenerationConstraints;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SessionRoom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GenerationConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_save_generation_settings(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'mixed')
            ->set('mixed_subjects_per_room', 3)
            ->set('invigilators_per_room', 3)
            ->set('teacher_subject_exclusion', true)
            ->call('saveSettings');

        $this->assertSame('mixed', $session->fresh()->seating_strategy);
        $this->assertSame(3, $session->fresh()->mixed_subjects_per_room);
        $this->assertSame(3, $session->fresh()->invigilators_per_room);
        $this->assertTrue($session->fresh()->teacher_subject_exclusion);
    }

    public function test_staff_can_save_respect_room_capacity(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['respect_room_capacity' => false]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('respect_room_capacity', true)
            ->call('saveSettings');

        $this->assertTrue($session->fresh()->respect_room_capacity);
    }

    public function test_staff_can_save_one_of_the_new_overflow_seating_strategies(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict_overflow_subject')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('strict_overflow_subject', $session->fresh()->seating_strategy);
    }

    public function test_staff_can_save_the_combined_section_then_subject_overflow_strategy(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict_overflow_section_then_subject')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('strict_overflow_section_then_subject', $session->fresh()->seating_strategy);
    }

    public function test_mixed_subjects_per_room_resets_to_default_outside_mixed_mode(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('seating_strategy', 'strict')
            ->set('mixed_subjects_per_room', 7)
            ->call('saveSettings');

        $this->assertSame(2, $session->fresh()->mixed_subjects_per_room);
    }

    public function test_pin_subjects_table_shows_a_per_section_breakdown(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1B']);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $breakdown = $component->viewData('sectionBreakdown')->get($subject->id);
        $this->assertSame(['BSAI 1A' => 2, 'BSAI 1B' => 1], $breakdown->all());
    }

    public function test_missing_teacher_sections_are_listed_and_can_be_assigned_a_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);

        $withTeacher = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1A',
            'teacher_id' => Teacher::factory()->create(['is_active' => true])->id,
        ]);
        $missing1 = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1B',
            'teacher_id' => null,
        ]);
        $missing2 = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1B',
            'teacher_id' => null,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $missingSections = $component->viewData('missingTeacherSections');
        $this->assertCount(1, $missingSections);
        $this->assertSame('BSAI 1B', $missingSections->first()->section);
        $this->assertSame(2, $missingSections->first()->missing_count);

        $component->set("missingTeacherSelection.{$subject->id}.BSAI 1B", (string) $teacher->id)
            ->call('assignMissingTeacher', $subject->id, 'BSAI 1B');

        $this->assertSame($teacher->id, $missing1->fresh()->teacher_id);
        $this->assertSame($teacher->id, $missing2->fresh()->teacher_id);
        // The row that already had a teacher is left untouched.
        $this->assertNotEquals($teacher->id, $withTeacher->fresh()->teacher_id);
    }

    /**
     * The Missing Teachers card suggests whoever already teaches the
     * subject (any enrollment for that subject_id with a teacher set,
     * across every session) instead of leaving staff to search a full
     * alphabetical teacher list for a name they might not know. The more
     * frequently used teacher should be suggested first.
     */
    public function test_missing_teacher_sections_suggest_teachers_already_linked_to_that_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $subject = Subject::factory()->create();
        $frequentTeacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Dr Frequent']);
        $rareTeacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Dr Rare']);
        $unrelatedTeacher = Teacher::factory()->create(['is_active' => true, 'name' => 'Dr Unrelated']);
        $inactiveTeacher = Teacher::factory()->create(['is_active' => false, 'name' => 'Dr Inactive']);

        // Two sections this same session already taught by $frequentTeacher...
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'A', 'teacher_id' => $frequentTeacher->id]);
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'B', 'teacher_id' => $frequentTeacher->id]);
        // ...one from a past session taught by $rareTeacher...
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'C', 'teacher_id' => $rareTeacher->id]);
        // ...one from a past session, now taught by someone no longer active...
        Enrollment::factory()->create(['subject_id' => $subject->id, 'section' => 'D', 'teacher_id' => $inactiveTeacher->id]);
        // ...and an unrelated subject taught by $unrelatedTeacher, which
        // must never show up as a suggestion for THIS subject.
        Enrollment::factory()->create(['subject_id' => Subject::factory()->create()->id, 'teacher_id' => $unrelatedTeacher->id]);

        $session = ExamSession::factory()->create();
        $missing = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'E', 'teacher_id' => null,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $suggested = $component->viewData('suggestedTeachersBySubject')->get($subject->id);

        $this->assertNotNull($suggested);
        $this->assertSame(['Dr Frequent', 'Dr Rare'], $suggested->pluck('name')->all());
    }

    public function test_assigning_a_missing_teacher_without_a_selection_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'section' => 'BSAI 1A',
            'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('assignMissingTeacher', $subject->id, 'BSAI 1A');

        // No teacher was picked, so the still-missing enrollment is untouched.
        $this->assertDatabaseHas('enrollments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'teacher_id' => null,
        ]);
    }

    public function test_assign_to_all_gives_every_pending_pair_the_same_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);

        $missingA = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 1A', 'teacher_id' => null]);
        $missingB = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 2A', 'teacher_id' => null]);
        $alreadyTaught = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 1B', 'teacher_id' => Teacher::factory()->create()->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('bulkMissingTeacherId', (string) $teacher->id)
            ->call('assignMissingTeacherToAll');

        $this->assertSame($teacher->id, $missingA->fresh()->teacher_id);
        $this->assertSame($teacher->id, $missingB->fresh()->teacher_id);
        $this->assertNotEquals($teacher->id, $alreadyTaught->fresh()->teacher_id);
    }

    public function test_assign_to_all_without_a_selection_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $missing = Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('assignMissingTeacherToAll');

        $this->assertNull($missing->fresh()->teacher_id);
    }

    public function test_ignore_all_dismisses_pending_pairs_until_shown_again(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $this->assertCount(1, $component->viewData('missingTeacherSections'));

        $component->call('ignoreAllMissingTeachers');

        // Dismissed — the card's data source is now empty even though the
        // enrollment itself is still untaught.
        $this->assertCount(0, $component->viewData('missingTeacherSections'));
        $this->assertSame(1, $component->viewData('ignoredMissingTeacherCount'));
        $this->assertDatabaseHas('enrollments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'teacher_id' => null,
        ]);

        $component->call('unignoreMissingTeachers');

        $this->assertCount(1, $component->viewData('missingTeacherSections'));
        $this->assertSame(0, $component->viewData('ignoredMissingTeacherCount'));
    }

    public function test_toggling_duty_matches_sections_persists_and_reverting_clears_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $component->call('toggleDutyMatchesSections', $subject->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'duty_matches_sections' => true,
        ]);

        $component->call('toggleDutyMatchesSections', $subject->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'duty_matches_sections' => false,
        ]);
    }

    public function test_toggling_subject_excluded_persists_and_reverting_clears_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        // Already pinned to a slot before being excluded — exclusion should
        // clear that immediately, not just on the next generate run.
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'is_pinned' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $component->call('toggleSubjectExcluded', $subject->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'is_excluded' => true,
            'is_pinned' => false,
            'time_slot_id' => null,
        ]);

        $component->call('toggleSubjectExcluded', $subject->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'is_excluded' => false,
        ]);
    }

    public function test_excluded_subjects_are_left_out_of_timetable_generation(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $excludedSubject = Subject::factory()->create();
        $includedSubject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $excludedSubject->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $includedSubject->id]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('toggleSubjectExcluded', $excludedSubject->id);
        $component->call('generateTimetable');

        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $excludedSubject->id,
            'is_excluded' => true,
            'time_slot_id' => null,
        ]);
        $this->assertDatabaseMissing('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $includedSubject->id,
            'time_slot_id' => null,
        ]);
    }

    public function test_generate_spreads_clash_free_subjects_across_all_available_days(): void
    {
        // 3 days, 2 slots each, 6 mutually clash-free subjects (no shared
        // students at all). A purely clash-avoidance algorithm would
        // happily cram all 6 into day one's two slots, leaving the other
        // two days empty — this proves it spreads them out instead.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        foreach (['2026-09-20', '2026-09-21', '2026-09-22'] as $date) {
            TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => $date, 'start_time' => '09:00']);
            TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => $date, 'start_time' => '11:00']);
        }

        foreach (range(1, 6) as $i) {
            $subject = Subject::factory()->create();
            Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id]);
        }

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $dayCounts = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('time_slot_id')
            ->with('timeSlot')
            ->get()
            ->groupBy(fn ($a) => $a->timeSlot->date->format('Y-m-d'))
            ->map->count();

        $this->assertCount(3, $dayCounts);
        $this->assertTrue($dayCounts->every(fn ($count) => $count === 2));
    }

    public function test_generate_never_places_subjects_sharing_a_student_on_the_same_day(): void
    {
        // Two slots on one day. Two subjects share a student. Placing them
        // in the day's two different slots would avoid a slot-level clash
        // but still double-book that student's day — must not happen.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-09-20', 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-09-20', 'start_time' => '11:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-09-21', 'start_time' => '09:00']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subjectB->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $assignments = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereIn('subject_id', [$subjectA->id, $subjectB->id])
            ->with('timeSlot')
            ->get()
            ->keyBy('subject_id');

        $this->assertNotSame(
            $assignments[$subjectA->id]->timeSlot->date->format('Y-m-d'),
            $assignments[$subjectB->id]->timeSlot->date->format('Y-m-d'),
        );
    }

    public function test_generate_does_not_misclassify_a_subject_as_same_semester_just_because_of_a_repeater(): void
    {
        // Regression: subject A is overwhelmingly semester 2 (29 students)
        // with a single semester-4 repeater also sitting it. Subject B is
        // purely semester 4. Before dominant-semester classification,
        // subject A's semester set was {2, 4} — so it looked "same
        // semester" as subject B and got forced onto a different day for
        // no real reason, even though they share no student at all.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        // Only one day, two slots — the old bug would have made this
        // unsolvable (both subjects "same semester" with only one day
        // available), forcing an unavoidable-clash fallback despite
        // sharing zero students.
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-09-20', 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-09-20', 'start_time' => '11:00']);

        $subjectA = Subject::factory()->create(['code' => 'CS-A']);
        $subjectB = Subject::factory()->create(['code' => 'CS-B']);

        // Subject A: mostly semester 2, one repeater from semester 4.
        for ($i = 0; $i < 5; $i++) {
            Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 2A']);
        }
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 4A']);

        // Subject B: purely semester 4, no student in common with A.
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 4A']);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $notes = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereIn('subject_id', [$subjectA->id, $subjectB->id])
            ->pluck('conflict_note')
            ->filter();

        $this->assertTrue($notes->isEmpty());
    }

    public function test_generate_reports_a_shortfall_when_respect_room_capacity_is_enabled_and_a_slot_cannot_seat_everyone(): void
    {
        // Only one small room and only one time slot — two clash-free
        // subjects both need a room of their own (Strict), which the
        // single active room can't provide for both at once. With no
        // other slot to move to, the second must still be placed (never
        // dropped), but the shortfall should be reported since it was
        // asked to respect real room capacity.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['respect_room_capacity' => true]);
        $room = Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        foreach ([$subjectA, $subjectB] as $subject) {
            for ($i = 0; $i < 3; $i++) {
                $student = Student::factory()->create();
                Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
            }
        }

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertDatabaseCount('subject_slot_assignments', 2);
        $notes = SubjectSlotAssignment::where('exam_session_id', $session->id)->pluck('conflict_note')->filter();
        $this->assertTrue($notes->contains(fn ($note) => str_contains($note, 'room capacity')));
    }

    public function test_generate_ignores_room_capacity_when_the_setting_is_off(): void
    {
        // Same tight-room setup as above, but respect_room_capacity is
        // left at its default (off) — subjects can still share the slot
        // even though it exceeds room capacity, matching prior behavior,
        // and no capacity shortfall gets reported.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['respect_room_capacity' => false]);
        $room = Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        foreach ([$subjectA, $subjectB] as $subject) {
            for ($i = 0; $i < 3; $i++) {
                $student = Student::factory()->create();
                Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
            }
        }

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $notes = SubjectSlotAssignment::where('exam_session_id', $session->id)->pluck('conflict_note')->filter();
        $this->assertFalse($notes->contains(fn ($note) => str_contains($note, 'room capacity')));
    }

    public function test_room_capacity_check_uses_the_sessions_real_seating_strategy_not_plain_strict(): void
    {
        // One room, capacity 6, two 3-student clash-free subjects sharing
        // the only slot. Under plain Strict (one room per subject) that's
        // a real shortfall — but this session's actual strategy is the
        // overflow one, which happily seats both together in the same
        // room (3 + 3 = 6). The capacity check must simulate with that
        // real strategy, not assume Strict, or it would report a
        // shortfall that seating would never actually produce.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create([
            'respect_room_capacity' => true,
            'seating_strategy' => 'strict_overflow_subject',
        ]);
        $room = Room::factory()->create(['rows' => 6, 'columns' => 1, 'capacity' => 6]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        foreach ([$subjectA, $subjectB] as $subject) {
            for ($i = 0; $i < 3; $i++) {
                $student = Student::factory()->create();
                Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
            }
        }

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $notes = SubjectSlotAssignment::where('exam_session_id', $session->id)->pluck('conflict_note')->filter();
        $this->assertFalse($notes->contains(fn ($note) => str_contains($note, 'room capacity')));
    }

    public function test_pin_dropdown_previews_an_exact_slot_clash_before_it_is_picked(): void
    {
        // The admin should see which slots would clash *before* picking
        // one, not only after committing to it. Subject A and B are in
        // different semesters (a repeater scenario) so sharing a day is
        // fine — only B's option for A's *exact* slot must be marked; a
        // different slot the same day, and a different day entirely, must
        // both be clean.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $clashSlot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        $sameDayOtherSlot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '13:00']);
        $freeDay = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '09:00']);

        $subjectA = Subject::factory()->create(['code' => 'CS999']);
        $subjectB = Subject::factory()->create(['code' => 'MAT888']);
        // Different semesters (2 and 4) — a repeater/backlog student sitting
        // both, not two subjects of the same cohort.
        $sharedStudent = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 2A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 4A']);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $clashSlot->id,
            'is_pinned' => true,
        ]);

        $html = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session])->html();

        $optionsForB = $this->pinDropdownOptionTexts($html, $subjectB->id);

        $this->assertStringContainsString('clash (same slot)', $optionsForB['20 Apr 09:00'] ?? '');
        $this->assertStringNotContainsString('clash', $optionsForB['20 Apr 13:00'] ?? '');
        $this->assertStringNotContainsString('clash', $optionsForB['21 Apr 09:00'] ?? '');
    }

    /**
     * Parses a subject's own Pin dropdown (identified by its updatePin(id,
     * ...) wire:change attribute) out of the rendered page and returns its
     * option labels keyed by the leading "d M H:i" date/time text — lets
     * tests assert on exactly that one dropdown's content instead of
     * guessing at HTML boundaries with regex.
     *
     * @return array<string, string>
     */
    private function pinDropdownOptionTexts(string $html, int $subjectId): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $select = $xpath->query("//select[@*[name()='wire:change']=\"updatePin({$subjectId}, \$event.target.value)\"]")->item(0);

        if (! $select) {
            return [];
        }

        $options = [];
        foreach ($xpath->query('.//option', $select) as $option) {
            $text = trim(preg_replace('/\s+/', ' ', $option->textContent));
            if (preg_match('/^(\d{1,2} \w{3} \d{2}:\d{2})/', $text, $m)) {
                $options[$m[1]] = $text;
            }
        }

        return $options;
    }

    public function test_pin_dropdown_flags_a_day_another_same_semester_subject_already_occupies(): void
    {
        // Same-semester subjects share almost their whole cohort even on
        // the rare row where no single enrollment happens to overlap
        // explicitly, so a day another subject of the same semester
        // already occupies is flagged too — but every day stays pickable
        // (two papers on the same day is sometimes unavoidable), so this
        // only marks the option, it never removes it. The day's own last
        // slot (max separation from subject A's first) is the one
        // exception — see the max-separation test below.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $occupiedDay = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '11:30']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '14:00']);
        $freeDay = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '09:00']);

        $subjectA = Subject::factory()->create(['code' => 'CS111']);
        $subjectB = Subject::factory()->create(['code' => 'MAT111']);
        // Both semester 2 (BSAI 2A / BSAI 2B) — no shared student required,
        // the semester itself is enough to flag the day.
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 2A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 2B']);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $occupiedDay->id,
            'is_pinned' => true,
        ]);

        $html = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session])->html();

        $optionsForB = $this->pinDropdownOptionTexts($html, $subjectB->id);

        // Subject B's dropdown still offers 20 Apr 11:30 (adjacent to
        // subject A's 09:00, not the day's max separation), marked as a
        // non-blocking alert, and still offers the clash-free 21 Apr
        // unmarked.
        $this->assertStringContainsString('alert (same day)', $optionsForB['20 Apr 11:30'] ?? '');
        $this->assertStringNotContainsString('clash', $optionsForB['21 Apr 09:00'] ?? '');
        $this->assertStringNotContainsString('alert', $optionsForB['21 Apr 09:00'] ?? '');
    }

    public function test_pin_dropdown_does_not_flag_the_days_max_separation_slot(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $occupiedDay = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '11:30']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '14:00']);

        $subjectA = Subject::factory()->create(['code' => 'CS111']);
        $subjectB = Subject::factory()->create(['code' => 'MAT111']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 2A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 2B']);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $occupiedDay->id,
            'is_pinned' => true,
        ]);

        $html = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session])->html();

        $optionsForB = $this->pinDropdownOptionTexts($html, $subjectB->id);

        // 14:00 is the day's last slot — max separation from A's 09:00 —
        // not flagged as a clash.
        $this->assertStringNotContainsString('clash', $optionsForB['20 Apr 14:00'] ?? '');
    }

    public function test_pinning_and_unpinning_a_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        $component->call('updatePin', $subject->id, (string) $slot->id);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'is_pinned' => true,
        ]);

        $component->call('updatePin', $subject->id, '');
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'is_pinned' => false,
        ]);
    }

    public function test_remove_slot_clears_a_single_subjects_assignment(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'time_slot_id' => $slot->id, 'is_pinned' => true, 'conflict_note' => 'x']);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'time_slot_id' => $slot->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('removeSlot', $subjectA->id);

        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => null,
            'is_pinned' => false,
            'conflict_note' => null,
        ]);
        // Subject B is untouched.
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subjectB->id,
            'time_slot_id' => $slot->id,
        ]);
    }

    public function test_remove_all_slots_clears_every_subjects_assignment(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'time_slot_id' => $slot->id, 'is_pinned' => true]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'time_slot_id' => $slot->id, 'conflict_note' => 'clash']);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('removeAllSlots');

        $this->assertDatabaseHas('subject_slot_assignments', ['subject_id' => $subjectA->id, 'time_slot_id' => null, 'is_pinned' => false]);
        $this->assertDatabaseHas('subject_slot_assignments', ['subject_id' => $subjectB->id, 'time_slot_id' => null, 'conflict_note' => null]);
    }

    public function test_show_clash_details_lists_the_actual_shared_students(): void
    {
        // The conflict_note text only ever names the other subject, never
        // which students — clicking it must recompute and show the real
        // list so the admin can see exactly who's affected.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $slotA = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        $slotB = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '13:00']);

        $subjectA = Subject::factory()->create(['code' => 'CS111', 'title' => 'Repeater Subject']);
        $subjectB = Subject::factory()->create(['code' => 'MAT222', 'title' => 'Fresh Subject']);

        $shared = Student::factory()->create(['roll_no' => '00000001', 'name' => 'Shared Student']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $shared->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $shared->id, 'subject_id' => $subjectB->id]);

        // A third subject on the same day with no shared student — must
        // not show up in the clash detail list at all.
        $subjectC = Subject::factory()->create(['code' => 'ENG333']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectC->id]);

        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id, 'time_slot_id' => $slotA->id, 'is_pinned' => true, 'conflict_note' => 'x']);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id, 'time_slot_id' => $slotB->id, 'is_pinned' => true]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subjectC->id, 'time_slot_id' => $slotA->id, 'is_pinned' => true]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('showClashDetails', $subjectA->id);

        $details = $component->get('clashDetails');
        $this->assertSame('CS111 — Repeater Subject', $details['subjectLabel']);
        $this->assertCount(1, $details['pairs']);
        $this->assertSame('MAT222 — Fresh Subject', $details['pairs'][0]['subjectLabel']);
        $this->assertSame([['rollNo' => '00000001', 'name' => 'Shared Student']], $details['pairs'][0]['students']);
    }

    public function test_show_teacher_duties_lists_every_duty_in_order_with_lock_state(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create(['name' => 'Dr Naveed']);
        $roomA = Room::factory()->create(['name' => 'Room A']);
        $roomB = Room::factory()->create(['name' => 'Room B']);
        $morning = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00', 'end_time' => '11:00']);
        $afternoon = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '13:00', 'end_time' => '15:00']);

        // Deliberately created out of chronological order — the modal
        // must still list them earliest first.
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $afternoon->id, 'room_id' => $roomB->id, 'is_locked' => true]);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $morning->id, 'room_id' => $roomA->id, 'is_locked' => false]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('showTeacherDuties', $teacher->id);

        $details = $component->get('teacherDutyDetails');
        $this->assertSame('Dr Naveed', $details['teacherName']);
        $this->assertCount(2, $details['duties']);
        $this->assertSame('20 Apr 2026', $details['duties'][0]['date']);
        $this->assertSame('Room A', $details['duties'][0]['room']);
        $this->assertFalse($details['duties'][0]['locked']);
        $this->assertSame('21 Apr 2026', $details['duties'][1]['date']);
        $this->assertSame('Room B', $details['duties'][1]['room']);
        $this->assertTrue($details['duties'][1]['locked']);
    }

    public function test_merging_subjects_from_the_pin_table_moves_enrollments_and_flags_the_merged_one(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $keep = Subject::factory()->create(['code' => 'EE07205|11', 'title' => 'Digital Logic and Design']);
        $mergeAway = Subject::factory()->create(['code' => 'EES07104|11', 'title' => 'Digital Logic Design']);
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $mergeAway->id,
        ]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            // Checkbox values arrive as strings in real usage (unlike a
            // plain PHP array of int ids) — regression coverage for a bug
            // where a strict in_array() comparison against an (int)-cast
            // survivor id wrongly rejected a genuinely selected subject.
            ->set('mergeSelected', [(string) $keep->id, (string) $mergeAway->id])
            ->call('openSubjectMergeModal')
            ->assertSet('showSubjectMergeModal', true)
            ->assertDispatched('open-modal', 'merge-subjects')
            ->set('mergeSurvivorId', (string) $keep->id)
            ->call('confirmSubjectMerge')
            ->assertSet('showSubjectMergeModal', false)
            ->assertSet('mergeSelected', [])
            // The modal must close via this server-dispatched event, not
            // a same-click x-on:click on the Merge button — that would
            // fire immediately regardless of whether wire:confirm's
            // dialog was accepted, closing the modal even when the
            // merge never actually ran.
            ->assertDispatched('close-modal', 'merge-subjects');

        $this->assertSame($keep->id, $enrollment->fresh()->subject_id);
        $this->assertSame($keep->id, $mergeAway->fresh()->merged_into_id);
    }

    public function test_opening_the_subject_merge_modal_with_fewer_than_two_selected_shows_an_error(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('mergeSelected', [$subject->id])
            ->call('openSubjectMergeModal')
            ->assertSet('showSubjectMergeModal', false);
    }

    public function test_manually_pinning_a_subject_flags_a_same_day_clash_with_another_subject(): void
    {
        // Regression: Capacity Check showed "Ready" while Pin Subjects to
        // Slots showed no warning either, because a manual pin never ran
        // the clash check the real timetable generator runs — only a full
        // "Generate Timetable" rerun surfaced the clash. Pinning must
        // check same-day clashes immediately. Three slots that day so the
        // two subjects land adjacent (not the day's first-and-last), which
        // still counts as a clash — see the max-separation test below for
        // the one case that doesn't.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $morning = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        $midday = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '11:30']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '14:00']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $sharedStudent = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectB->id]);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $morning->id,
            'is_pinned' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);

        // Adjacent slot (11:30), same day as subject A's 09:00 — not the
        // day's max separation (which would be 09:00/14:00) — must be flagged.
        $component->call('updatePin', $subjectB->id, (string) $midday->id);

        $noteB = SubjectSlotAssignment::where('exam_session_id', $session->id)->where('subject_id', $subjectB->id)->value('conflict_note');
        $this->assertNotNull($noteB);
        $this->assertStringContainsString($subjectA->code, $noteB);
    }

    public function test_manually_pinning_a_same_semester_subject_at_the_days_max_separation_is_not_a_clash(): void
    {
        // Same setup as above, but subject B goes into the day's LAST
        // slot instead of the adjacent one — 09:00 and 14:00 is the
        // maximum possible separation for a 3-slot day, which is the
        // accepted way to handle a semester with more subjects than days.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $morning = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '11:30']);
        $lastSlot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '14:00']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $sharedStudent = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectB->id]);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $morning->id,
            'is_pinned' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('updatePin', $subjectB->id, (string) $lastSlot->id);

        $noteB = SubjectSlotAssignment::where('exam_session_id', $session->id)->where('subject_id', $subjectB->id)->value('conflict_note');
        $this->assertNull($noteB);
    }

    public function test_manually_pinning_a_subject_to_a_clash_free_day_leaves_no_note(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $day1 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $day2 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $sharedStudent = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectB->id]);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $day1->id,
            'is_pinned' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('updatePin', $subjectB->id, (string) $day2->id);

        $noteB = SubjectSlotAssignment::where('exam_session_id', $session->id)->where('subject_id', $subjectB->id)->value('conflict_note');
        $this->assertNull($noteB);
    }

    public function test_manually_pinning_a_different_semester_subject_to_the_same_day_leaves_no_note(): void
    {
        // A repeater sharing a day (different slots) with their current
        // subject is fine — only the exact same slot should ever be
        // flagged for a cross-semester pair.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $morning = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        $afternoon = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '13:00']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $sharedStudent = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 2A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 4A']);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $morning->id,
            'is_pinned' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('updatePin', $subjectB->id, (string) $afternoon->id);

        $noteB = SubjectSlotAssignment::where('exam_session_id', $session->id)->where('subject_id', $subjectB->id)->value('conflict_note');
        $this->assertNull($noteB);
    }

    public function test_manually_pinning_a_different_semester_subject_to_the_exact_same_slot_flags_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);

        $subjectA = Subject::factory()->create(['code' => 'CS111']);
        $subjectB = Subject::factory()->create(['code' => 'MAT222']);
        $sharedStudent = Student::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectA->id, 'section' => 'BSAI 2A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $sharedStudent->id, 'subject_id' => $subjectB->id, 'section' => 'BSAI 4A']);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'time_slot_id' => $slot->id,
            'is_pinned' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('updatePin', $subjectB->id, (string) $slot->id);

        $noteB = SubjectSlotAssignment::where('exam_session_id', $session->id)->where('subject_id', $subjectB->id)->value('conflict_note');
        $this->assertNotNull($noteB);
        $this->assertStringContainsString('exact same time slot', $noteB);
        $this->assertStringContainsString('CS111', $noteB);
    }

    public function test_generate_places_non_conflicting_subjects_without_notes(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $slot1 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        $slot2 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();

        // Different students in each subject — no shared enrollment, no conflict.
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectA->id]);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subjectB->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subjectA->id,
            'conflict_note' => null,
        ]);
        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subjectB->id,
            'conflict_note' => null,
        ]);
    }

    public function test_generate_respects_a_pinned_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $slot1 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        $slot2 = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id]);

        $component = Livewire::actingAs($staff)->test(GenerationConstraints::class, ['examSession' => $session]);
        $component->call('updatePin', $subject->id, (string) $slot2->id);
        $component->call('generateTimetable');

        $this->assertDatabaseHas('subject_slot_assignments', [
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot2->id,
            'is_pinned' => true,
        ]);
    }

    public function test_generate_records_conflict_notes_when_a_clash_is_unavoidable(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '09:00']);
        TimeSlot::factory()->create(['exam_session_id' => $session->id, 'start_time' => '11:00']);

        $subjects = Subject::factory()->count(3)->create();
        $student = Student::factory()->create();

        // One student takes all 3 subjects -> every pair conflicts, only 2 slots exist.
        foreach ($subjects as $subject) {
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
            ]);
        }

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertGreaterThan(0, SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('conflict_note')
            ->count());
    }

    public function test_generate_with_no_enrollments_shows_an_error_without_crashing(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertDatabaseCount('subject_slot_assignments', 0);
    }

    public function test_user_without_generate_roster_permission_cannot_run_generation(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'generate_roster', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateTimetable')
            ->assertForbidden();

        $this->assertDatabaseCount('subject_slot_assignments', 0);
    }

    public function test_generate_seating_is_blocked_when_capacity_requirement_is_not_met(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        // Only a tiny room, no active rooms in session at all — guarantees a shortfall.
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateSeating');

        $this->assertDatabaseCount('seat_assignments', 0);
    }

    /**
     * A teacher shortfall alone must never block Generate Seating — only
     * rooms/seats matter at that stage. Duty assignment (which does need
     * teachers) runs afterwards and already copes with a shortfall via
     * warnings instead of refusing to run.
     */
    public function test_generate_seating_proceeds_despite_a_teacher_shortfall(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 2]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        // Only one teacher exists at all, but the slot needs
        // invigilators_per_room (2) — a genuine, unfixable-here shortfall.
        Teacher::factory()->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateSeating');

        $this->assertDatabaseCount('seat_assignments', 1);
    }

    /**
     * A same-day (not same-slot) alert means two papers from the same
     * semester share a calendar day — seating runs per-slot, so it has no
     * effect on whether this slot can be seated. It must never block
     * Generate Seating, same as a teacher shortfall above.
     */
    public function test_generate_seating_proceeds_despite_a_same_day_alert(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'conflict_note' => 'CS101 and CS202 share 5 student(s) but were placed on the same day — no clash-free day remained. Consider adding a day/slot or pinning one of them elsewhere.',
        ]);

        Teacher::factory()->count(3)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateSeating');

        $this->assertDatabaseCount('seat_assignments', 1);
    }

    public function test_generate_duties_is_blocked_until_seating_exists(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateDuties');

        $this->assertDatabaseCount('duty_assignments', 0);
        $this->assertSame('draft', $session->fresh()->status);
    }

    public function test_generate_duties_creates_assignments_and_marks_the_session_generated(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'draft', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => 1,
            'column_number' => 1,
        ]);
        Teacher::factory()->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->call('generateDuties');

        $this->assertDatabaseCount('duty_assignments', 1);
        $this->assertSame('generated', $session->fresh()->status);
    }
}
