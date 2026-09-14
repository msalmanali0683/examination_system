<?php

namespace App\Livewire\Sessions;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Services\Generation\ConflictGraphBuilder;
use App\Services\Generation\SeatAllocationService;
use App\Services\Generation\TimetableGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

class GenerationConstraints extends Component
{
    public ExamSession $examSession;

    public string $seating_strategy = 'strict';

    public int $invigilators_per_room = 2;

    public bool $teacher_subject_exclusion = false;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
        $this->seating_strategy = $examSession->seating_strategy;
        $this->invigilators_per_room = $examSession->invigilators_per_room;
        $this->teacher_subject_exclusion = $examSession->teacher_subject_exclusion;
    }

    public function saveSettings(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'seating_strategy' => ['required', Rule::in(['strict', 'combine_sections', 'mixed'])],
            'invigilators_per_room' => ['required', 'integer', 'min:1', 'max:10'],
            'teacher_subject_exclusion' => ['boolean'],
        ]);

        $this->examSession->update($validated);
        session()->flash('status', 'Settings saved.');
    }

    public function updatePin(int $subjectId, string $value): void
    {
        $this->authorize('manage_sessions');

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

    public function generateTimetable(): void
    {
        $this->authorize('generate_roster');

        $sessionId = $this->examSession->id;

        $enrollments = Enrollment::where('exam_session_id', $sessionId)->get(['student_id', 'subject_id']);
        $subjectIds = $enrollments->pluck('subject_id')->unique()->values()->all();

        if (empty($subjectIds)) {
            session()->flash('error', 'No enrollments yet — import enrollments before generating a timetable.');

            return;
        }

        $timeSlotIds = $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->pluck('id')->all();

        $pinned = SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->where('is_pinned', true)
            ->pluck('time_slot_id', 'subject_id')
            ->all();

        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');
        $labels = $subjects->mapWithKeys(fn (Subject $s) => [$s->id => "{$s->code} - {$s->title}"])->all();

        $graph = (new ConflictGraphBuilder)->build($enrollments);
        $result = (new TimetableGenerator)->generate($subjectIds, $pinned, $timeSlotIds, $graph, $labels);

        DB::transaction(function () use ($result, $sessionId, $pinned) {
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
        });

        session()->flash(
            $result->conflicts->isEmpty() ? 'status' : 'error',
            $result->conflicts->isEmpty()
                ? 'Timetable generated with no clashes.'
                : "Timetable generated with {$result->conflicts->count()} unavoidable clash(es) — see below."
        );
    }

    public function generateSeating(): void
    {
        $this->authorize('generate_roster');

        $hasSlots = $this->examSession->subjectSlotAssignments()->whereNotNull('time_slot_id')->exists();

        if (! $hasSlots) {
            session()->flash('error', 'Generate the timetable first — seating needs subjects assigned to slots.');

            return;
        }

        $result = (new SeatAllocationService)->generate($this->examSession);

        session()->flash(
            $result->warnings->isEmpty() ? 'status' : 'error',
            $result->warnings->isEmpty()
                ? 'Seating generated for every slot.'
                : "Seating generated with {$result->warnings->count()} warning(s) — some students couldn't be seated or adjacency couldn't be avoided."
        );
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

        return view('livewire.sessions.generation-constraints', [
            'subjects' => $subjects,
            'assignments' => $assignments,
            'timeSlots' => $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get(),
            'conflicted' => $assignments->filter(fn ($a) => $a->conflict_note !== null),
        ]);
    }
}
