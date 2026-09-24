<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Teacher;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Every subject/section pair in the session with its currently assigned
 * teacher (if any), and a way to change it — unlike the Missing Teachers
 * card and its Excel import, which only ever fill in a pair that has no
 * teacher yet, this reassigns a pair that already has one too.
 */
class SectionTeachers extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public string $search = '';

    /**
     * subject_id => section => teacher_id, bound per-row exactly like
     * GenerationConstraints::$missingTeacherSelection.
     *
     * @var array<int, array<string, string>>
     */
    public array $selection = [];

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    /**
     * Sets every enrollment for this subject+section (in this session) to
     * the chosen teacher — regardless of what they were set to before, so
     * this both fills a missing one and reassigns an already-taught one.
     * A pair with a mix of teachers (or missing-for-some-students) is
     * normalized onto the one chosen teacher too.
     */
    public function changeTeacher(int $subjectId, string $section): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $teacherId = $this->selection[$subjectId][$section] ?? null;

        if (! $teacherId || ! $this->examSession->teachers()->whereKey($teacherId)->exists()) {
            session()->flash('error', 'Pick a teacher before changing.');

            return;
        }

        Enrollment::where('exam_session_id', $this->examSession->id)
            ->where('subject_id', $subjectId)
            ->where('section', $section)
            ->update(['teacher_id' => $teacherId]);

        unset($this->selection[$subjectId][$section]);

        session()->flash('status', "Teacher changed for {$section}.");
    }

    /**
     * @return Collection<int, object{subject_id: int, section: string, code: string, title: string, student_count: int, teacher: Teacher|null|string}>
     */
    private function sectionRows(): Collection
    {
        $query = Enrollment::where('enrollments.exam_session_id', $this->examSession->id)
            ->join('subjects', 'subjects.id', '=', 'enrollments.subject_id')
            ->selectRaw('enrollments.subject_id, enrollments.section, subjects.code, subjects.title, count(*) as student_count,
                count(distinct enrollments.teacher_id) as distinct_teacher_count,
                min(enrollments.teacher_id) as sole_teacher_id,
                sum(case when enrollments.teacher_id is null then 1 else 0 end) as untaught_count')
            ->groupBy('enrollments.subject_id', 'enrollments.section', 'subjects.code', 'subjects.title')
            ->orderBy('subjects.code')
            ->orderBy('enrollments.section');

        if ($this->search !== '') {
            $query->where(fn ($q) => $q
                ->where('subjects.code', 'like', "%{$this->search}%")
                ->orWhere('subjects.title', 'like', "%{$this->search}%"));
        }

        $rows = $query->get();

        $teachers = Teacher::whereIn('id', $rows->pluck('sole_teacher_id')->filter()->unique())->get()->keyBy('id');

        return $rows->map(function ($row) use ($teachers) {
            if ((int) $row->untaught_count === (int) $row->student_count) {
                $row->teacher = null;
            } elseif ((int) $row->untaught_count === 0 && (int) $row->distinct_teacher_count === 1) {
                $row->teacher = $teachers->get($row->sole_teacher_id);
            } else {
                $row->teacher = 'mixed';
            }

            return $row;
        });
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $rows = $this->sectionRows();

        return view('livewire.sessions.section-teachers', [
            'rows' => $rows,
            'activeTeachers' => $this->examSession->teachers()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
