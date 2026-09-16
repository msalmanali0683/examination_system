<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\ConflictGraphBuilder;
use App\Services\Generation\DutyAllocationService;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SeatAllocationService;
use App\Services\Generation\SemesterExtractor;
use App\Services\Generation\TimetableGenerator;
use App\Services\SubjectMergeService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

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

    public bool $showRequirements = false;

    /**
     * Pending teacher choice for each subject/section that has no teacher
     * on any of its enrollments (e.g. the import row's Teacher column was
     * blank) — keyed by subject_id then section. Assigning fills in only
     * the enrollments still missing a teacher for that pair, so it never
     * overwrites a teacher already recorded on some of them.
     */
    public array $missingTeacherSelection = [];

    /**
     * Teacher chosen for the "assign to all" bulk action on the Missing
     * Teachers card — a single selection applied to every subject/section
     * pair currently listed there, as an alternative to picking one row
     * at a time.
     */
    public string $bulkMissingTeacherId = '';

    /**
     * Subject IDs checked in the Pin Subjects to Slots table for merging
     * (e.g. two different codes that turn out to be the same real
     * course, like "EE07205|11" and "EES07104|11") — bound directly to
     * each row's checkbox via wire:model.
     *
     * @var int[]
     */
    public array $mergeSelected = [];

    public bool $showSubjectMergeModal = false;

    /**
     * Which of the checked subjects survives the merge, chosen in the
     * modal — the rest are merged into it. Kept as a string since it's
     * bound to a radio input.
     */
    public string $mergeSurvivorId = '';

    /**
     * The subject+day currently open in the clash-detail modal, as a
     * plain array (subject label, day, and one entry per clashing
     * subject with the actual list of shared students) — null when the
     * modal is closed. Computed fresh on click rather than stored, so it
     * always reflects the session's current enrollments/assignments.
     */
    public ?array $clashDetails = null;

    /**
     * The teacher currently open in the duty-list modal, as a plain array
     * (teacher name and one entry per duty with its day/time/room/lock
     * state) — null when the modal is closed. Computed fresh on click,
     * same as $clashDetails.
     */
    public ?array $teacherDutyDetails = null;

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

    public function updatePin(int $subjectId, string $value): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        if ($value === '') {
            SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
                ->where('subject_id', $subjectId)
                ->update(['is_pinned' => false]);

            return;
        }

        $slotId = (int) $value;

        SubjectSlotAssignment::updateOrCreate(
            ['exam_session_id' => $this->examSession->id, 'subject_id' => $subjectId],
            ['time_slot_id' => $slotId, 'is_pinned' => true, 'conflict_note' => null]
        );

        $this->recordClashNoteForSameDay($subjectId, $slotId);
    }

    /**
     * Unassigns a single subject's slot outright — unlike switching its
     * Pin dropdown back to "Auto" (which only unpins and leaves the last
     * slot in place until the next Generate Timetable run), this clears
     * the slot immediately so the row goes back to "Not yet generated".
     */
    public function removeSlot(int $subjectId): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->update(['time_slot_id' => null, 'is_pinned' => false, 'conflict_note' => null]);
    }

    /**
     * Clears every subject's slot assignment for the whole session in one
     * go — a fresh start for manual pinning, or to back out of a
     * Generate Timetable run without regenerating. Exclusions are left
     * untouched; this only clears slot/pin/conflict data.
     */
    public function removeAllSlots(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
            ->update(['time_slot_id' => null, 'is_pinned' => false, 'conflict_note' => null]);

        session()->flash('status', 'Removed every subject\'s slot assignment.');
    }

    /**
     * A manual pin doesn't go through TimetableGenerator, so it never gets
     * the same-day/same-slot clash check that gives — this runs it
     * immediately instead of leaving the admin to find out only after the
     * next full "Generate Timetable" run. Mirrors the generator's own
     * rule: a same-semester subject sharing this day is flagged (that's
     * the whole cohort, never share a day) — UNLESS the two land at that
     * day's maximum possible separation (e.g. its first and last slot),
     * which is the accepted way to handle a semester with more subjects
     * than days and isn't treated as a clash. A different-semester
     * subject (a repeater) sharing this day is fine regardless — only the
     * exact same slot is flagged for those. Only the subject just pinned
     * is annotated (matches how the generator itself only notes a
     * conflict on whichever subject it placed last), so this never
     * overwrites an unrelated note already sitting on another subject.
     */
    private function recordClashNoteForSameDay(int $subjectId, int $slotId): void
    {
        $slot = TimeSlot::find($slotId);

        if (! $slot) {
            return;
        }

        $sessionId = $this->examSession->id;

        $sameDaySlotIds = TimeSlot::where('exam_session_id', $sessionId)
            ->whereDate('date', $slot->date)
            ->orderBy('start_time')
            ->pluck('id');
        $positionInDay = $sameDaySlotIds->values()->flip();
        $dayMaxGap = $positionInDay->count() - 1;

        $sameDayAssignments = SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->whereIn('time_slot_id', $sameDaySlotIds)
            ->get(['subject_id', 'time_slot_id']);

        $subjectIdsSameDay = $sameDayAssignments->pluck('subject_id')->all();

        if (count($subjectIdsSameDay) < 2) {
            return;
        }

        $enrollments = Enrollment::where('exam_session_id', $sessionId)
            ->whereIn('subject_id', $subjectIdsSameDay)
            ->get(['student_id', 'subject_id']);

        $graph = (new ConflictGraphBuilder)->build($enrollments);
        $semesters = $this->semestersForSubjects($sessionId, $subjectIdsSameDay);
        $mySemesters = $semesters[$subjectId] ?? [];
        $slotOfSubject = $sameDayAssignments->pluck('time_slot_id', 'subject_id');

        $others = collect($subjectIdsSameDay)
            ->reject(fn ($id) => $id === $subjectId)
            ->filter(fn ($id) => ($graph[$subjectId][$id] ?? 0) > 0);

        $sameSemesterClash = $others->filter(function ($id) use ($semesters, $mySemesters, $slotOfSubject, $positionInDay, $dayMaxGap, $slotId) {
            $otherSemesters = $semesters[$id] ?? [];
            $sameSemester = empty($mySemesters) || empty($otherSemesters)
                ? true
                : count(array_intersect($mySemesters, $otherSemesters)) > 0;

            if (! $sameSemester) {
                return false;
            }

            $otherSlot = $slotOfSubject[$id] ?? null;

            if ($dayMaxGap > 0 && $otherSlot !== null && isset($positionInDay[$slotId], $positionInDay[$otherSlot])) {
                $gap = abs($positionInDay[$slotId] - $positionInDay[$otherSlot]);

                if ($gap === $dayMaxGap) {
                    return false;
                }
            }

            return true;
        });

        $exactSlotClash = $others->filter(fn ($id) => ($slotOfSubject[$id] ?? null) === $slotId);

        $messages = [];

        if ($sameSemesterClash->isNotEmpty()) {
            $names = Subject::whereIn('id', $sameSemesterClash)->pluck('code')->implode(', ');
            $messages[] = "Shares students with {$names} on the same day — placed anyway.";
        }

        if ($exactSlotClash->isNotEmpty()) {
            $names = Subject::whereIn('id', $exactSlotClash)->pluck('code')->implode(', ');
            $messages[] = "Shares students with {$names} in the exact same time slot — placed anyway.";
        }

        if (empty($messages)) {
            return;
        }

        SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->where('subject_id', $subjectId)
            ->update(['conflict_note' => implode(' ', $messages)]);
    }

    /**
     * @param  int[]  $subjectIds
     * @return array<int, int[]> subject_id => semester number(s)
     */
    /**
     * @return array<int, int[]> subject_id => [dominant semester], or []
     *                           when unparseable. Each subject gets its
     *                           single dominant semester (see
     *                           SemesterExtractor::dominant()), wrapped in
     *                           an array — the shape TimetableGenerator's
     *                           $semesterBySubject expects — not the full
     *                           set of every semester it touches, so a
     *                           subject with a couple of repeaters from
     *                           another semester isn't misclassified as
     *                           belonging to that semester too.
     */
    private function semestersForSubjects(int $sessionId, array $subjectIds): array
    {
        return Enrollment::where('exam_session_id', $sessionId)
            ->whereIn('subject_id', $subjectIds)
            ->select('subject_id', 'section')
            ->selectRaw('count(*) as c')
            ->groupBy('subject_id', 'section')
            ->get()
            ->groupBy('subject_id')
            ->map(function ($rows) {
                $dominant = SemesterExtractor::dominant($rows->pluck('c', 'section'));

                return $dominant === null ? [] : [$dominant];
            })
            ->all();
    }

    /**
     * Opens the clash-detail modal for a subject's ⚠ warning — recomputed
     * fresh from current enrollments/assignments (same same-day logic as
     * recordClashNoteForSameDay()) rather than trusting the stored
     * conflict_note text, which only ever named the OTHER subject, never
     * which students. Every clashing subject on the same day is listed,
     * each with the actual students shared with the subject clicked.
     */
    public function showClashDetails(int $subjectId): void
    {
        $this->authorize('manage_sessions');

        $subject = Subject::find($subjectId);
        $assignment = SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->first();

        if (! $subject || ! $assignment?->time_slot_id) {
            return;
        }

        $slot = TimeSlot::find($assignment->time_slot_id);
        $sessionId = $this->examSession->id;

        $sameDaySlotIds = TimeSlot::where('exam_session_id', $sessionId)
            ->whereDate('date', $slot->date)
            ->pluck('id');

        $otherSubjectIds = SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->whereIn('time_slot_id', $sameDaySlotIds)
            ->where('subject_id', '!=', $subjectId)
            ->pluck('subject_id');

        $otherSubjects = Subject::whereIn('id', $otherSubjectIds)->get()->keyBy('id');

        $mySubjectIds = Enrollment::where('exam_session_id', $sessionId)
            ->where('subject_id', $subjectId)
            ->pluck('student_id', 'id');

        $pairs = [];

        foreach ($otherSubjectIds as $otherId) {
            $sharedStudentIds = Enrollment::where('exam_session_id', $sessionId)
                ->where('subject_id', $otherId)
                ->whereIn('student_id', $mySubjectIds->values())
                ->pluck('student_id');

            if ($sharedStudentIds->isEmpty()) {
                continue;
            }

            $students = Student::whereIn('id', $sharedStudentIds)
                ->orderBy('roll_no')
                ->get(['roll_no', 'name']);

            $other = $otherSubjects->get($otherId);

            $pairs[] = [
                'subjectLabel' => "{$other->code} — {$other->title}",
                'students' => $students->map(fn ($s) => ['rollNo' => $s->roll_no, 'name' => $s->name])->all(),
            ];
        }

        if (empty($pairs)) {
            return;
        }

        $this->clashDetails = [
            'subjectLabel' => "{$subject->code} — {$subject->title}",
            'day' => $slot->date->format('d M Y'),
            'pairs' => $pairs,
        ];

        $this->dispatch('open-modal', 'clash-details');
    }

    /**
     * Opens the duty-list modal for a teacher's row in the Duty Fairness
     * table — every duty this teacher has in this session, in order,
     * so the admin can see exactly where a low/high count comes from
     * without leaving the generation page.
     */
    public function showTeacherDuties(int $teacherId): void
    {
        $this->authorize('manage_sessions');

        $teacher = Teacher::find($teacherId);

        if (! $teacher) {
            return;
        }

        $duties = DutyAssignment::where('duty_assignments.exam_session_id', $this->examSession->id)
            ->where('duty_assignments.teacher_id', $teacherId)
            ->with('room')
            ->join('time_slots', 'time_slots.id', '=', 'duty_assignments.time_slot_id')
            ->orderBy('time_slots.date')
            ->orderBy('time_slots.start_time')
            ->select('duty_assignments.*', 'time_slots.date as slot_date', 'time_slots.start_time as slot_start', 'time_slots.end_time as slot_end')
            ->get();

        $this->teacherDutyDetails = [
            'teacherName' => $teacher->name,
            'duties' => $duties->map(fn ($duty) => [
                'date' => Carbon::parse($duty->slot_date)->format('d M Y'),
                'time' => substr($duty->slot_start, 0, 5).' – '.substr($duty->slot_end, 0, 5),
                'room' => $duty->room->name,
                'locked' => $duty->is_locked,
            ])->all(),
        ];

        $this->dispatch('open-modal', 'teacher-duty-details');
    }

    /**
     * When checked for a subject, any teacher who teaches one or more of
     * that subject's sections (per the enrollment file's Teacher column)
     * gets their session duty count forced to exactly that many sections —
     * see DutyAllocationService::sectionBasedDutyOverrides().
     */
    public function toggleDutyMatchesSections(int $subjectId): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $existing = SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->first();

        if ($existing?->duty_matches_sections) {
            $existing->update(['duty_matches_sections' => false]);

            return;
        }

        SubjectSlotAssignment::updateOrCreate(
            ['exam_session_id' => $this->examSession->id, 'subject_id' => $subjectId],
            ['duty_matches_sections' => true]
        );
    }

    /**
     * An excluded subject is left out of timetable/seating/duty generation
     * entirely — no time slot, no seats, no invigilation. Excluding also
     * clears any existing pin/slot for it immediately, rather than waiting
     * for the next "Generate Timetable" run.
     */
    public function toggleSubjectExcluded(int $subjectId): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $existing = SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->first();

        if ($existing?->is_excluded) {
            $existing->update(['is_excluded' => false]);

            return;
        }

        SubjectSlotAssignment::updateOrCreate(
            ['exam_session_id' => $this->examSession->id, 'subject_id' => $subjectId],
            ['is_excluded' => true, 'is_pinned' => false, 'time_slot_id' => null, 'conflict_note' => null]
        );
    }

    /**
     * Assigns the chosen teacher to every enrollment for this subject+
     * section that currently has no teacher, so features that depend on
     * the enrollment Teacher column (invigilator-subject exclusion,
     * duty-matches-sections) can account for them.
     */
    public function assignMissingTeacher(int $subjectId, string $section): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $teacherId = $this->missingTeacherSelection[$subjectId][$section] ?? null;

        if (! $teacherId || ! Teacher::whereKey($teacherId)->exists()) {
            $this->flashError('Pick a teacher before assigning.');

            return;
        }

        Enrollment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->where('section', $section)
            ->whereNull('teacher_id')
            ->update(['teacher_id' => $teacherId]);

        unset($this->missingTeacherSelection[$subjectId][$section]);

        session()->flash('status', "Teacher assigned to {$section}.");
    }

    /**
     * Assigns one chosen teacher to every subject/section pair currently
     * listed on the Missing Teachers card (skipping any already ignored),
     * for when the real answer for all of them is the same person rather
     * than picking row by row.
     */
    public function assignMissingTeacherToAll(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $teacherId = $this->bulkMissingTeacherId;

        if (! $teacherId || ! Teacher::whereKey($teacherId)->exists()) {
            $this->flashError('Pick a teacher before assigning to all.');

            return;
        }

        $pairs = $this->missingTeacherSections();

        if ($pairs->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($pairs, $teacherId) {
            foreach ($pairs as $pair) {
                Enrollment::where('exam_session_id', $this->examSession->id)
                    ->where('subject_id', $pair->subject_id)
                    ->where('section', $pair->section)
                    ->whereNull('teacher_id')
                    ->update(['teacher_id' => $teacherId]);
            }
        });

        $this->missingTeacherSelection = [];
        $this->bulkMissingTeacherId = '';

        session()->flash('status', "Assigned a teacher to all {$pairs->count()} pending subject/section pair(s).");
    }

    /**
     * Dismisses every subject/section pair currently listed on the
     * Missing Teachers card without assigning anyone — for cases where
     * that's the real answer (e.g. an online/self-invigilated paper).
     * Dismissed pairs stay without a teacher and won't be listed again,
     * even after a later import, until unignored.
     */
    public function ignoreAllMissingTeachers(): void
    {
        $this->authorize('manage_sessions');

        $pairs = $this->missingTeacherSections();

        if ($pairs->isEmpty()) {
            return;
        }

        $ignored = $this->examSession->ignored_missing_teacher_sections ?? [];

        foreach ($pairs as $pair) {
            $ignored[] = $this->missingTeacherKey($pair->subject_id, $pair->section);
        }

        $this->examSession->update(['ignored_missing_teacher_sections' => array_values(array_unique($ignored))]);
        $this->missingTeacherSelection = [];

        session()->flash('status', "Dismissed {$pairs->count()} pending subject/section pair(s) — they'll stay without a teacher.");
    }

    /**
     * Brings back every subject/section pair dismissed via "Ignore All"
     * so they show up on the Missing Teachers card again if still
     * missing a teacher.
     */
    public function unignoreMissingTeachers(): void
    {
        $this->authorize('manage_sessions');

        $this->examSession->update(['ignored_missing_teacher_sections' => []]);

        session()->flash('status', 'Dismissed subject/section pairs are visible again.');
    }

    public function openSubjectMergeModal(): void
    {
        $this->authorize('manage_subjects');

        if (count($this->mergeSelected) < 2) {
            $this->flashError('Select at least two subjects to merge.');

            return;
        }

        $this->mergeSurvivorId = (string) $this->mergeSelected[0];
        $this->showSubjectMergeModal = true;
        // Alpine's own "show" state, once initialized, isn't re-read from
        // :show="$showSubjectMergeModal" on a later Livewire morph — every
        // other modal on this page opens via this same explicit event
        // rather than relying on the prop alone (see showClashDetails()).
        $this->dispatch('open-modal', 'merge-subjects');
    }

    public function closeSubjectMergeModal(): void
    {
        $this->showSubjectMergeModal = false;
        $this->mergeSurvivorId = '';
    }

    /**
     * Merges every other checked subject into the chosen survivor —
     * this is a catalog-wide change (see SubjectMergeService), not just
     * for this session: enrollments and pinned slots move onto the
     * survivor in every non-finalized session that has them, and future
     * enrollment imports under a merged-away code resolve to the
     * survivor too. Finalized sessions keep their original record.
     */
    public function confirmSubjectMerge(): void
    {
        $this->authorize('manage_subjects');

        // Checkbox values arrive as strings (e.g. "1"), so mergeSelected
        // is an array of strings — normalize before comparing against the
        // (int)-cast survivor id, or a strict in_array() check here would
        // wrongly reject a genuinely selected survivor every time.
        $selectedIds = array_map('intval', $this->mergeSelected);
        $survivorId = (int) $this->mergeSurvivorId;

        if (! in_array($survivorId, $selectedIds, true)) {
            $this->flashError('Pick which subject should survive the merge.');

            return;
        }

        $keep = Subject::find($survivorId);
        $mergeAwayIds = array_values(array_diff($selectedIds, [$survivorId]));

        if (! $keep || empty($mergeAwayIds)) {
            $this->flashError('Select at least two subjects to merge.');

            return;
        }

        $service = new SubjectMergeService;
        $totals = ['enrollmentsMoved' => 0, 'enrollmentsDropped' => 0, 'slotAssignmentsMoved' => 0, 'slotAssignmentsDropped' => 0, 'sessionsSkipped' => 0];
        $merged = 0;

        foreach ($mergeAwayIds as $mergeAwayId) {
            $mergeAway = Subject::find($mergeAwayId);

            if (! $mergeAway || $mergeAway->isMerged()) {
                continue;
            }

            $stats = $service->merge($keep, $mergeAway);

            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }

            $merged++;
        }

        $this->mergeSelected = [];
        $this->closeSubjectMergeModal();
        // wire:confirm on the Merge button means the modal can't rely on
        // a same-click x-on:click to close itself — that would fire
        // immediately regardless of whether the confirm dialog was
        // accepted, closing the modal even when the merge never ran (or
        // before it finished). Dispatching close-modal here only happens
        // once the merge has actually completed.
        $this->dispatch('close-modal', 'merge-subjects');

        session()->flash(
            'status',
            "Merged {$merged} subject(s) into {$keep->code} — {$totals['enrollmentsMoved']} enrollment(s) moved"
                .($totals['enrollmentsDropped'] ? ", {$totals['enrollmentsDropped']} duplicate enrollment(s) dropped" : '')
                .($totals['sessionsSkipped'] ? ", {$totals['sessionsSkipped']} finalized session(s) left untouched" : '')
                .'. Regenerate the timetable if the merged students need reshuffling into one slot.'
        );
    }

    private function missingTeacherKey(int $subjectId, string $section): string
    {
        return "{$subjectId}|{$section}";
    }

    /**
     * Every subject/section pair with at least one un-taught enrollment,
     * excluding pairs explicitly dismissed via "Ignore All" — the single
     * source of truth behind the Missing Teachers card and both of its
     * bulk actions, so they always agree on exactly what's pending.
     */
    private function missingTeacherSections(): Collection
    {
        $ignored = $this->examSession->ignored_missing_teacher_sections ?? [];

        return Enrollment::where('enrollments.exam_session_id', $this->examSession->id)
            ->whereNull('enrollments.teacher_id')
            ->join('subjects', 'subjects.id', '=', 'enrollments.subject_id')
            ->selectRaw('enrollments.subject_id, enrollments.section, subjects.code, subjects.title, count(*) as missing_count')
            ->groupBy('enrollments.subject_id', 'enrollments.section', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->orderBy('enrollments.section')
            ->get()
            ->reject(fn ($row) => in_array($this->missingTeacherKey($row->subject_id, $row->section), $ignored, true))
            ->values();
    }

    public function generateTimetable(): void
    {
        $this->authorize('generate_roster');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $sessionId = $this->examSession->id;

        $excludedIds = SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->where('is_excluded', true)
            ->pluck('subject_id')
            ->all();

        $enrollments = Enrollment::where('exam_session_id', $sessionId)
            ->whereNotIn('subject_id', $excludedIds)
            ->get(['student_id', 'subject_id']);
        $subjectIds = $enrollments->pluck('subject_id')->unique()->values()->all();

        if (empty($subjectIds)) {
            $this->flashError('No enrollments yet — import enrollments before generating a timetable.');

            return;
        }

        $timeSlots = $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get(['id', 'date']);
        $timeSlotIds = $timeSlots->pluck('id')->all();
        $slotDays = $timeSlots->mapWithKeys(fn (TimeSlot $t) => [$t->id => $t->date->format('Y-m-d')])->all();

        $pinned = SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->where('is_pinned', true)
            ->pluck('time_slot_id', 'subject_id')
            ->all();

        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');
        $labels = $subjects->mapWithKeys(fn (Subject $s) => [$s->id => "{$s->code} - {$s->title}"])->all();

        // Which semester(s) each subject belongs to — lets the generator
        // tell a whole-cohort same-semester clash (never share a day)
        // apart from a repeater sharing a paper across semesters (fine on
        // the same day, just never the same exact slot).
        $semesterBySubject = $this->semestersForSubjects($sessionId, $subjectIds);

        $graph = (new ConflictGraphBuilder)->build($enrollments);
        $roomsFit = $this->examSession->respect_room_capacity ? $this->roomsFitChecker($sessionId, $excludedIds) : null;
        $result = (new TimetableGenerator)->generate($subjectIds, $pinned, $timeSlotIds, $graph, $labels, $slotDays, $roomsFit, $semesterBySubject);

        DB::transaction(function () use ($result, $sessionId, $pinned, $excludedIds) {
            foreach ($result->assignments as $subjectId => $slotId) {
                if (array_key_exists($subjectId, $pinned)) {
                    continue;
                }

                $note = $result->conflicts
                    ->filter(fn ($c) => $c->subjectId === $subjectId)
                    ->pluck('message')
                    ->unique()
                    ->implode(' ');

                SubjectSlotAssignment::updateOrCreate(
                    ['exam_session_id' => $sessionId, 'subject_id' => $subjectId],
                    ['time_slot_id' => $slotId, 'is_pinned' => false, 'conflict_note' => $note ?: null]
                );
            }

            if (! empty($excludedIds)) {
                SubjectSlotAssignment::where('exam_session_id', $sessionId)
                    ->whereIn('subject_id', $excludedIds)
                    ->update(['time_slot_id' => null, 'is_pinned' => false, 'conflict_note' => null]);
            }
        });

        // The requirement numbers depend on which subjects landed in which
        // slot, so a stale check would be misleading after regenerating.
        $this->showRequirements = false;

        ActivityLog::record($this->examSession, 'timetable.generated', $result->conflicts->isEmpty()
            ? 'Generated timetable with no clashes.'
            : "Generated timetable with {$result->conflicts->count()} unavoidable clash(es).");

        if ($result->conflicts->isEmpty()) {
            session()->flash('status', 'Timetable generated with no clashes.');
        } else {
            $this->flashError("Timetable generated with {$result->conflicts->count()} unavoidable clash(es) — see below.");
        }
    }

    /**
     * Builds the closure TimetableGenerator uses to decide whether the
     * active rooms can seat a candidate group of subjects together in one
     * slot — simulated with the session's own saved seating strategy, not
     * a plain one-room-per-subject assumption. This matters a lot when an
     * overflow strategy is selected (e.g. "fill leftover seats with a
     * different subject"): it can seat several small subjects in one room
     * that Strict alone would insist on spreading across separate rooms,
     * so checking fit with the real strategy avoids readjusting subjects
     * away from a slot they'd actually have fit in once seated for real.
     *
     * @param  int[]  $excludedIds
     */
    private function roomsFitChecker(int $sessionId, array $excludedIds): callable
    {
        $enrollmentsBySubject = Enrollment::where('exam_session_id', $sessionId)
            ->whereNotIn('subject_id', $excludedIds)
            ->select('id', 'subject_id', 'section')
            ->get()
            ->groupBy('subject_id');

        $roomTemplate = $this->examSession->sessionRooms()->where('is_active', true)->with('room')->get()
            ->map(fn ($sr) => [
                'room_id' => $sr->room_id,
                'rows' => $sr->room->rows,
                'columns' => $sr->room->columns,
                'capacity' => $sr->effectiveCapacity(),
                'occupied' => [],
            ])
            ->sortByDesc('capacity')
            ->values()
            ->all();

        $strategy = (new SeatAllocationService)->strategyFor($this->examSession->seating_strategy, $this->examSession->mixed_subjects_per_room);

        return function (array $subjectIdsInSlot) use ($enrollmentsBySubject, $roomTemplate, $strategy): bool {
            $subset = collect($subjectIdsInSlot)->flatMap(fn ($id) => $enrollmentsBySubject->get($id) ?? collect());

            return $strategy->allocate($subset, $roomTemplate)->warnings->where('type', 'unseated')->isEmpty();
        };
    }

    public function checkRequirements(): void
    {
        $this->authorize('generate_roster');
        $this->showRequirements = true;
    }

    public function generateSeating(): void
    {
        $this->authorize('generate_roster');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $hasSlots = $this->examSession->subjectSlotAssignments()->whereNotNull('time_slot_id')->exists();

        if (! $hasSlots) {
            $this->flashError('Generate the timetable first — seating needs subjects assigned to slots.');

            return;
        }

        if (! (new RequirementCalculator)->isFullyMet($this->examSession)) {
            $this->flashError('Not enough active rooms or available teachers for one or more slots — see the Capacity Check below before generating.');

            return;
        }

        $result = (new SeatAllocationService)->generate($this->examSession);

        ActivityLog::record($this->examSession, 'seating.generated', $result->warnings->isEmpty()
            ? 'Generated seating for every slot.'
            : "Generated seating with {$result->warnings->count()} warning(s).");

        if ($result->warnings->isEmpty()) {
            session()->flash('status', 'Seating generated for every slot.');
        } else {
            $this->flashError("Seating generated with {$result->warnings->count()} warning(s) — some students couldn't be seated or adjacency couldn't be avoided.");
        }
    }

    public function generateDuties(): void
    {
        $this->authorize('generate_roster');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        if (! $this->examSession->seatAssignments()->exists()) {
            $this->flashError('Generate seating first — duties are assigned to the rooms actually in use each slot.');

            return;
        }

        $result = (new DutyAllocationService)->generate($this->examSession);

        // Duties are the last stage of the pipeline (timetable -> seating ->
        // duties); once they're generated the roster as a whole is ready for
        // review, even if some warnings remain.
        $this->examSession->update(['status' => 'generated']);

        ActivityLog::record($this->examSession, 'duties.generated', $result->warnings->isEmpty()
            ? 'Generated duties for every slot.'
            : "Generated duties with {$result->warnings->count()} warning(s).");

        if ($result->warnings->isEmpty()) {
            session()->flash('status', 'Duties generated for every slot.');
        } else {
            $this->flashError("Duties generated with {$result->warnings->count()} warning(s) — see below.");
        }
    }

    /**
     * Flashes a session error and pops it open as a dialog immediately —
     * a plain flash banner is easy to miss above the fold on this long
     * page, especially after a wire:confirm click already drew focus away.
     */
    private function flashError(string $message): void
    {
        session()->flash('error', $message);
        $this->dispatch('open-modal', 'generation-error');
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $sessionId = $this->examSession->id;

        $subjectIds = Enrollment::where('exam_session_id', $sessionId)->distinct()->pluck('subject_id');

        $subjects = Subject::whereIn('id', $subjectIds)
            ->withCount(['enrollments' => fn ($q) => $q->where('exam_session_id', $sessionId)])
            ->orderBy('code')
            ->get();

        $assignments = $this->examSession->subjectSlotAssignments()->get()->keyBy('subject_id');

        $sectionBreakdown = Enrollment::where('exam_session_id', $sessionId)
            ->select('subject_id', 'section')
            ->selectRaw('count(*) as c')
            ->groupBy('subject_id', 'section')
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->sortBy('section')->pluck('c', 'section'));

        // Subjects in the same semester share the same cohort of students
        // almost entirely, so grouping the table this way makes
        // same-semester subjects visually adjacent — exactly the ones
        // that must never land on the same day.
        $semesterBySubject = $sectionBreakdown->map(fn ($sections) => SemesterExtractor::fromSections($sections->keys()));

        // The single semester most of a subject's students are actually
        // in — unlike $semesterBySubject above (every semester it touches
        // at all, shown on the badge), this is what clash-checking below
        // uses, so a subject with a couple of repeaters from another
        // semester isn't misclassified as belonging to that semester too.
        $dominantSemesterBySubject = $sectionBreakdown->map(
            fn ($sections) => ($d = SemesterExtractor::dominant($sections)) === null ? collect() : collect([$d])
        );

        $subjects = $subjects->sortBy(fn (Subject $s) => (int) ($semesterBySubject->get($s->id, collect())->first() ?? 999))->values();

        // Every active room is available in every slot (rooms aren't
        // restricted per slot in this app), so total seat capacity is one
        // constant number; what varies per slot is how much of it other
        // subjects already assigned there are using — that's what the Pin
        // dropdown needs to show so the admin can see, before picking a
        // slot, whether it still has room for this subject's students.
        $seatsAvailableTotal = $this->examSession->sessionRooms()->where('is_active', true)->with('room')->get()
            ->sum(fn ($sr) => $sr->effectiveCapacity());

        $seatsUsedPerSlot = $subjects
            ->filter(fn (Subject $s) => $assignments->get($s->id)?->time_slot_id !== null && ! $assignments->get($s->id)?->is_excluded)
            ->groupBy(fn (Subject $s) => $assignments->get($s->id)->time_slot_id)
            ->map(fn ($rows) => $rows->sum('enrollments_count'));

        $timeSlots = $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get();
        $timeSlotsById = $timeSlots->keyBy('id');

        // Same-day clash preview for the Pin dropdown: for every subject
        // and every day this session has slots on, work out whether
        // placing that subject there would clash with something already
        // assigned that day — either an explicit shared student, or
        // another subject of the same semester (same cohort in practice,
        // even on the rare row where no single enrollment happens to
        // overlap). This only flags the day — every day stays pickable,
        // since two papers on the same day is sometimes unavoidable and
        // the admin may still need to choose it; it mirrors
        // recordClashNoteForSameDay()'s own logic, so what the dropdown
        // previews before a pick matches what actually gets flagged
        // after it, instead of the admin finding out only afterwards.
        $conflictGraph = (new ConflictGraphBuilder)->build(
            Enrollment::where('exam_session_id', $sessionId)->get(['student_id', 'subject_id'])
        );

        $subjectIdsByDay = $assignments
            ->filter(fn ($a) => $a->time_slot_id !== null && ! $a->is_excluded)
            ->groupBy(fn ($a) => $timeSlotsById->get($a->time_slot_id)?->date->format('Y-m-d'))
            ->map(fn ($rows) => $rows->pluck('subject_id')->all());

        // Position of each slot within its own day (0 = first), and how
        // many slots each day has — used below to tell a genuine
        // same-semester day-share apart from two papers placed at that
        // day's maximum possible separation (e.g. its first and last
        // slot), which is the accepted way to handle a semester with more
        // subjects than days and isn't treated as a clash.
        $positionInDay = [];
        $daySlotCounts = [];
        foreach ($timeSlots->groupBy(fn ($t) => $t->date->format('Y-m-d')) as $day => $slotsForDay) {
            $daySlotCounts[$day] = $slotsForDay->count();
            foreach ($slotsForDay->values() as $i => $slotModel) {
                $positionInDay[$slotModel->id] = $i;
            }
        }

        // Same-semester subjects share almost their whole cohort, so a day
        // another subject of the same semester already occupies is
        // flagged even without an explicit shared-enrollment record —
        // unless the two land at that day's maximum separation, which
        // isn't a clash (see above). A different (known) semester is
        // never flagged at the day level — that's fine now (a repeater
        // sitting two papers on one day) — see $clashingSlotsBySubject
        // below for the one thing that still matters for them: the exact
        // same slot.
        $clashingDaysBySubject = $subjects->mapWithKeys(function (Subject $subject) use ($timeSlots, $subjectIdsByDay, $conflictGraph, $dominantSemesterBySubject, $assignments, $positionInDay, $daySlotCounts) {
            $mySemesters = $dominantSemesterBySubject->get($subject->id, collect());

            $slotIds = $timeSlots->filter(function ($candidateSlot) use ($subject, $subjectIdsByDay, $conflictGraph, $dominantSemesterBySubject, $mySemesters, $assignments, $positionInDay, $daySlotCounts) {
                $day = $candidateSlot->date->format('Y-m-d');
                $othersThatDay = collect($subjectIdsByDay->get($day, []))->reject(fn ($id) => $id === $subject->id);
                $dayMaxGap = ($daySlotCounts[$day] ?? 1) - 1;

                return $othersThatDay->contains(function ($occupantId) use ($conflictGraph, $subject, $dominantSemesterBySubject, $mySemesters, $candidateSlot, $assignments, $positionInDay, $dayMaxGap) {
                    $otherSemesters = $dominantSemesterBySubject->get($occupantId, collect());

                    $sameSemester = $mySemesters->isNotEmpty() && $otherSemesters->isNotEmpty()
                        ? $otherSemesters->intersect($mySemesters)->isNotEmpty()
                        // Semester unknown on one side or both: conservative
                        // fallback, same as the generator — only flag if
                        // they demonstrably share a student.
                        : ($conflictGraph[$subject->id][$occupantId] ?? 0) > 0;

                    if (! $sameSemester) {
                        return false;
                    }

                    $occupantSlotId = $assignments->get($occupantId)?->time_slot_id;

                    if ($occupantSlotId === null || $occupantSlotId === $candidateSlot->id) {
                        return false; // exact-slot case is handled separately below
                    }

                    if ($dayMaxGap > 0 && isset($positionInDay[$candidateSlot->id], $positionInDay[$occupantSlotId])) {
                        $gap = abs($positionInDay[$candidateSlot->id] - $positionInDay[$occupantSlotId]);

                        if ($gap === $dayMaxGap) {
                            return false;
                        }
                    }

                    return true;
                });
            })->pluck('id');

            return [$subject->id => $slotIds];
        });

        // Exact-slot clash preview: whatever the semester situation, two
        // subjects that share a student can never both sit the exact same
        // time slot — the one thing that's never allowed regardless of
        // the day-level rule above.
        $subjectIdsBySlot = $assignments
            ->filter(fn ($a) => $a->time_slot_id !== null && ! $a->is_excluded)
            ->groupBy('time_slot_id')
            ->map(fn ($rows) => $rows->pluck('subject_id')->all());

        $clashingSlotsBySubject = $subjects->mapWithKeys(function (Subject $subject) use ($timeSlots, $subjectIdsBySlot, $conflictGraph) {
            $slotIds = $timeSlots->filter(function ($slot) use ($subject, $subjectIdsBySlot, $conflictGraph) {
                $othersInSlot = collect($subjectIdsBySlot->get($slot->id, []))->reject(fn ($id) => $id === $subject->id);

                return $othersInSlot->contains(fn ($id) => ($conflictGraph[$subject->id][$id] ?? 0) > 0);
            })->pluck('id');

            return [$subject->id => $slotIds];
        });

        $missingTeacherSections = $this->missingTeacherSections();

        // Computing this runs a full seating simulation across every slot,
        // so it's only done when the admin asks for it (Check Capacity),
        // not on every render — otherwise every unrelated click (pinning a
        // subject, saving settings) would pay that cost too.
        $requirements = $this->showRequirements
            ? (new RequirementCalculator)->calculate($this->examSession)
            : collect();

        return view('livewire.sessions.generation-constraints', [
            'subjects' => $subjects,
            'assignments' => $assignments,
            'sectionBreakdown' => $sectionBreakdown,
            'semesterBySubject' => $semesterBySubject,
            'seatsAvailableTotal' => $seatsAvailableTotal,
            'seatsUsedPerSlot' => $seatsUsedPerSlot,
            'clashingDaysBySubject' => $clashingDaysBySubject,
            'clashingSlotsBySubject' => $clashingSlotsBySubject,
            'missingTeacherSections' => $missingTeacherSections,
            'ignoredMissingTeacherCount' => count($this->examSession->ignored_missing_teacher_sections ?? []),
            'activeTeachers' => Teacher::where('is_active', true)->orderBy('name')->get(),
            'timeSlots' => $timeSlots,
            'conflicted' => $assignments->filter(fn ($a) => $a->conflict_note !== null),
            'requirements' => $requirements,
            'dutyFairness' => $this->dutyFairness($sessionId),
        ]);
    }

    /**
     * A cheap aggregate (no simulation), safe to compute on every render —
     * unlike the requirement check, this doesn't re-run seating.
     */
    private function dutyFairness(int $sessionId): Collection
    {
        $counts = DutyAssignment::where('exam_session_id', $sessionId)
            ->selectRaw('teacher_id, count(*) as c')
            ->groupBy('teacher_id')
            ->pluck('c', 'teacher_id');

        $constraints = SessionTeacherConstraint::where('exam_session_id', $sessionId)->get()->keyBy('teacher_id');

        return Teacher::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Teacher $teacher) use ($counts, $constraints) {
                $constraint = $constraints->get($teacher->id);
                $excluded = $constraint?->is_excluded ?? false;
                $min = $constraint?->effectiveMinDuties() ?? config('exam.default_min_duties');
                $max = $constraint?->effectiveMaxDuties() ?? config('exam.default_max_duties');
                $count = $counts->get($teacher->id, 0);

                return (object) [
                    'teacher' => $teacher,
                    'count' => $count,
                    'min' => $min,
                    'max' => $max,
                    'excluded' => $excluded,
                    'met' => $excluded || $count >= $min,
                ];
            });
    }
}
