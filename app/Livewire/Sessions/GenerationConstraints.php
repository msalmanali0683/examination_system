<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\ConflictGraphBuilder;
use App\Services\Generation\DutyAllocationService;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SeatAllocationService;
use App\Services\Generation\TimetableGenerator;
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

    public bool $showRequirements = false;

    /**
     * Pending teacher choice for each subject/section that has no teacher
     * on any of its enrollments (e.g. the import row's Teacher column was
     * blank) — keyed by subject_id then section. Assigning fills in only
     * the enrollments still missing a teacher for that pair, so it never
     * overwrites a teacher already recorded on some of them.
     */
    public array $missingTeacherSelection = [];

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
        $this->seating_strategy = $examSession->seating_strategy;
        $this->mixed_subjects_per_room = $examSession->mixed_subjects_per_room;
        $this->invigilators_per_room = $examSession->invigilators_per_room;
        $this->teacher_subject_exclusion = $examSession->teacher_subject_exclusion;
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

        SubjectSlotAssignment::updateOrCreate(
            ['exam_session_id' => $this->examSession->id, 'subject_id' => $subjectId],
            ['time_slot_id' => (int) $value, 'is_pinned' => true, 'conflict_note' => null]
        );
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

        $graph = (new ConflictGraphBuilder)->build($enrollments);
        $result = (new TimetableGenerator)->generate($subjectIds, $pinned, $timeSlotIds, $graph, $labels, $slotDays);

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

        $missingTeacherSections = Enrollment::where('enrollments.exam_session_id', $sessionId)
            ->whereNull('enrollments.teacher_id')
            ->join('subjects', 'subjects.id', '=', 'enrollments.subject_id')
            ->selectRaw('enrollments.subject_id, enrollments.section, subjects.code, subjects.title, count(*) as missing_count')
            ->groupBy('enrollments.subject_id', 'enrollments.section', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->orderBy('enrollments.section')
            ->get();

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
            'missingTeacherSections' => $missingTeacherSections,
            'activeTeachers' => Teacher::where('is_active', true)->orderBy('name')->get(),
            'timeSlots' => $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get(),
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
