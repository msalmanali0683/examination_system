<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;
use App\Services\MissingTeacherSections;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The generation workflow's landing page: settings that apply to every
 * later stage, plus a status overview linking out to each stage's own
 * page (Missing Teachers, Timetable, Capacity Check, Seating, Duties).
 * Deliberately slim — this used to be one very long page holding every
 * stage at once, which made "assign a teacher" and "generate seating"
 * feel like the same critical, easy-to-fumble action; splitting each
 * stage onto its own page keeps them independent and lower-stakes.
 */
class GenerationConstraints extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public string $seating_strategy = 'strict';

    public int $mixed_subjects_per_room = 2;

    public int $invigilators_per_room = 2;

    public bool $teacher_subject_exclusion = false;

    /**
     * When on, Generate Timetable keeps every slot within the session's
     * actual active room capacity — a subject is only placed alongside
     * others already in a slot if the active rooms can seat all of them
     * together, readjusting it to a different slot/day otherwise. A
     * subject ending up alone in a slot is completely fine; it's only a
     * problem when nothing anywhere has room for it (reported, not
     * silently dropped). Off by default: without it, slots are chosen
     * purely by clash-avoidance and spread, same as before.
     */
    public bool $respect_room_capacity = false;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
        $this->seating_strategy = $examSession->seating_strategy;
        $this->mixed_subjects_per_room = $examSession->mixed_subjects_per_room;
        $this->invigilators_per_room = $examSession->invigilators_per_room;
        $this->teacher_subject_exclusion = $examSession->teacher_subject_exclusion;
        $this->respect_room_capacity = $examSession->respect_room_capacity;
    }

    public function saveSettings(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $validated = $this->validate([
            'seating_strategy' => ['required', Rule::in(array_keys(ExamSession::SEATING_STRATEGIES))],
            'mixed_subjects_per_room' => ['required_if:seating_strategy,mixed', 'integer', 'min:2', 'max:10'],
            'invigilators_per_room' => ['required', 'integer', 'min:1', 'max:10'],
            'teacher_subject_exclusion' => ['boolean'],
            'respect_room_capacity' => ['boolean'],
        ]);

        // Not relevant outside Mixed mode — keep it a sane default rather
        // than validating/saving whatever was left in the field.
        if ($this->seating_strategy !== 'mixed') {
            $validated['mixed_subjects_per_room'] = 2;
        }

        $this->examSession->update($validated);
        session()->flash('status', 'Settings saved.');
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $sessionId = $this->examSession->id;

        $enrolledSubjectCount = Enrollment::where('exam_session_id', $sessionId)->distinct('subject_id')->count('subject_id');
        $excludedSubjectCount = SubjectSlotAssignment::where('exam_session_id', $sessionId)->where('is_excluded', true)->count();
        $placedSubjectCount = SubjectSlotAssignment::where('exam_session_id', $sessionId)->whereNotNull('time_slot_id')->count();

        return view('livewire.sessions.generation-constraints', [
            'missingTeacherCount' => MissingTeacherSections::find($this->examSession)->count(),
            'ignoredMissingTeacherCount' => count($this->examSession->ignored_missing_teacher_sections ?? []),
            'enrolledSubjectCount' => $enrolledSubjectCount,
            'placedSubjectCount' => $placedSubjectCount,
            'unplacedSubjectCount' => max(0, $enrolledSubjectCount - $excludedSubjectCount - $placedSubjectCount),
            'hasSeating' => SeatAssignment::where('exam_session_id', $sessionId)->exists(),
            'hasDuties' => DutyAssignment::where('exam_session_id', $sessionId)->exists(),
        ]);
    }
}
