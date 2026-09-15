<?php

namespace Tests\Feature;

use App\Livewire\Sessions\GenerationConstraints;
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

    public function test_manually_pinning_a_subject_flags_a_same_day_clash_with_another_subject(): void
    {
        // Regression: Capacity Check showed "Ready" while Pin Subjects to
        // Slots showed no warning either, because a manual pin never ran
        // the clash check the real timetable generator runs — only a full
        // "Generate Timetable" rerun surfaced the clash. Pinning must
        // check same-day clashes immediately.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $morning = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        $afternoon = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '13:00']);

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

        // Different slot, but the SAME day as subject A — must be flagged.
        $component->call('updatePin', $subjectB->id, (string) $afternoon->id);

        $noteB = SubjectSlotAssignment::where('exam_session_id', $session->id)->where('subject_id', $subjectB->id)->value('conflict_note');
        $this->assertNotNull($noteB);
        $this->assertStringContainsString($subjectA->code, $noteB);
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
