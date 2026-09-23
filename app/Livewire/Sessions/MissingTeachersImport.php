<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Livewire\Concerns\HandlesExcelUpload;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\MissingTeacherSections;
use App\Services\SubjectCodeNormalizer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fills in the Missing Teachers list's un-taught subject/section pairs from
 * an uploaded spreadsheet (teacher name, course, section) instead of
 * assigning them one row at a time. Only ever sets teacher_id where it's
 * currently null — it can never overwrite an already-taught enrollment,
 * same as assignMissingTeacherToAll() in GenerationConstraints.
 *
 * A row only ever resolves against pendingPairs() — the exact same set
 * the Review step's counts describe — so what the review screen promises
 * ("N rows will be assigned", "N skipped: no pending pair") is always
 * exactly what commitImport() actually does, never a superset of it.
 */
class MissingTeachersImport extends Component
{
    use GuardsFinalizedSession;
    use HandlesExcelUpload;

    private const TARGET_FIELDS = [
        'teacher' => 'Teacher Name',
        'course' => 'Course Code / Course Title',
        'section' => 'Section',
    ];

    public ExamSession $examSession;

    /**
     * When true, only subject/section pairs on this session's ignored
     * list are eligible — the Ignored Missing Teachers page's own import
     * link, so uploading there can't silently resolve a pair still
     * pending on the main Missing Teachers list (and vice versa). Set
     * once from the `ignored=1` query string in mount().
     */
    public bool $scopeIgnored = false;

    /** @var array<string, string|int|null> target field => source column index */
    public array $mapping = [];

    public string $step = 'upload';

    /**
     * The Subject id the admin picked for unresolvedSubjects[$i], or ''
     * to skip every row with that text — kept as a plain index-aligned
     * array (not keyed by the raw text itself) because raw teacher/course
     * text can contain a literal "." (e.g. "Dr. Smith"), which Livewire's
     * wire:model would otherwise parse as a nested path separator.
     *
     * @var array<int, string>
     */
    public array $subjectResolutions = [];

    /**
     * Same idea as subjectResolutions, index-aligned with
     * unresolvedTeachers instead.
     *
     * @var array<int, string>
     */
    public array $teacherResolutions = [];

    /** @var array<int, array{raw: string, suggestedId: ?int}> */
    public array $unresolvedSubjects = [];

    /** @var array<int, array{raw: string, suggestedId: ?int}> */
    public array $unresolvedTeachers = [];

    public array $report = [];

    public int $pairsResolved = 0;

    public int $enrollmentsUpdated = 0;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
        $this->scopeIgnored = request()->boolean('ignored');
    }

    /**
     * The subject/section pairs this upload is allowed to touch —
     * everything still pending, or (on the Ignored page) only the ones
     * explicitly dismissed. Shared by buildReport() and commitImport()
     * so they can never disagree about what a row resolves against.
     */
    private function pendingPairs(): Collection
    {
        return $this->scopeIgnored
            ? MissingTeacherSections::findIgnored($this->examSession)
            : MissingTeacherSections::find($this->examSession);
    }

    public function targetFields(): array
    {
        return self::TARGET_FIELDS;
    }

    public function updatedFile(): void
    {
        $this->authorize('manage_sessions');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);

        $this->storeUploadedFile();
        $this->detectBestSheet();
        $this->loadSourceColumns();

        $this->mapping = $this->guessMapping();
        $this->step = 'map';
    }

    public function selectSheet(int $index): void
    {
        $this->authorize('manage_sessions');
        $this->selectedSheetIndex = $index;
        $this->loadSourceColumns();
        $this->mapping = $this->guessMapping();
    }

    public function confirmMapping(): void
    {
        $this->authorize('manage_sessions');

        foreach (['teacher', 'course', 'section'] as $field) {
            if ($this->mappedIndex($field) === null) {
                $this->addError("mapping.{$field}", 'This column is required.');
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $subjects = Subject::all(['id', 'code', 'title']);
        $teachers = Teacher::where('is_active', true)->get(['id', 'name']);

        $this->unresolvedSubjects = $this->findUnresolved(
            $this->distinctCellValues('course'),
            fn (string $raw) => $this->matchSubject($raw, $subjects),
            fn (string $raw) => $this->bestGuess($raw, $subjects, fn ($s) => "{$s->code} {$s->title}"),
        );

        $this->unresolvedTeachers = $this->findUnresolved(
            $this->distinctCellValues('teacher'),
            fn (string $raw) => $this->matchTeacher($raw, $teachers),
            fn (string $raw) => $this->bestGuess($raw, $teachers, fn ($t) => $t->name),
        );

        if (empty($this->unresolvedSubjects) && empty($this->unresolvedTeachers)) {
            $this->report = $this->buildReport();
            $this->step = 'review';

            return;
        }

        $this->subjectResolutions = collect($this->unresolvedSubjects)
            ->map(fn ($row) => (string) ($row['suggestedId'] ?? ''))
            ->all();
        $this->teacherResolutions = collect($this->unresolvedTeachers)
            ->map(fn ($row) => (string) ($row['suggestedId'] ?? ''))
            ->all();

        $this->step = 'resolve';
    }

    public function confirmResolutions(): void
    {
        $this->authorize('manage_sessions');

        $this->report = $this->buildReport();
        $this->step = 'review';
    }

    public function commitImport(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $subjects = Subject::all(['id', 'code', 'title']);
        $teachers = Teacher::where('is_active', true)->get(['id', 'name']);
        $allowedPairs = $this->pendingPairs()
            ->map(fn ($row) => MissingTeacherSections::key($row->subject_id, strtolower(trim($row->section))))
            ->flip();
        $pairsResolved = 0;
        $enrollmentsUpdated = 0;

        foreach ($this->readDataRows() as $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $data = $this->extractRow($row, $subjects, $teachers);

            if ($data === null) {
                continue;
            }

            $section = strtolower(trim($data['section']));

            if (! $allowedPairs->has(MissingTeacherSections::key($data['subjectId'], $section))) {
                continue;
            }

            $updated = Enrollment::where('exam_session_id', $this->examSession->id)
                ->where('subject_id', $data['subjectId'])
                ->whereRaw('lower(trim(section)) = ?', [$section])
                ->whereNull('teacher_id')
                ->update(['teacher_id' => $data['teacherId']]);

            if ($updated > 0) {
                $pairsResolved++;
                $enrollmentsUpdated += $updated;
            }
        }

        $this->cleanupUploadedFile();
        $this->pairsResolved = $pairsResolved;
        $this->enrollmentsUpdated = $enrollmentsUpdated;
        $this->step = 'done';
    }

    public function startOver(): void
    {
        $this->cleanupUploadedFile();
        $this->reset([
            'file', 'storedPath', 'sourceColumns', 'availableSheets', 'selectedSheetIndex',
            'mapping', 'subjectResolutions', 'teacherResolutions', 'unresolvedSubjects', 'unresolvedTeachers',
            'report', 'pairsResolved', 'enrollmentsUpdated',
        ]);
        $this->step = 'upload';
    }

    /**
     * @return array<int, array{raw: string, suggestedId: ?int}>
     */
    private function findUnresolved(Collection $rawValues, callable $matcher, callable $suggest): array
    {
        return $rawValues
            ->reject(fn (string $raw) => $matcher($raw) !== null)
            ->map(fn (string $raw) => ['raw' => $raw, 'suggestedId' => $suggest($raw)])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string> distinct, trimmed raw values from the mapped column, across every data row.
     */
    private function distinctCellValues(string $field): Collection
    {
        return $this->readDataRows()
            ->map(fn ($row) => $this->cell($row, $field))
            ->filter()
            ->unique()
            ->values();
    }

    private function matchSubject(string $raw, Collection $subjects): ?int
    {
        $normalizedCode = SubjectCodeNormalizer::normalize($raw);
        $byCode = $subjects->first(fn (Subject $s) => strcasecmp($s->code, $normalizedCode) === 0);

        if ($byCode) {
            return $byCode->id;
        }

        $byTitle = $subjects->first(fn (Subject $s) => strcasecmp(trim($s->title), trim($raw)) === 0);

        return $byTitle?->id;
    }

    private function matchTeacher(string $raw, Collection $teachers): ?int
    {
        return $teachers->first(fn (Teacher $t) => strcasecmp(trim($t->name), trim($raw)) === 0)?->id;
    }

    /**
     * A rough nearest-match suggestion (similar_text() percentage) so the
     * Resolve step doesn't hand the admin a blank dropdown for every
     * unmatched value — only pre-selected above a threshold confident
     * enough to be worth defaulting to, never silently assumed.
     */
    private function bestGuess(string $raw, Collection $candidates, callable $label): ?int
    {
        $best = null;
        $bestPercent = 0.0;

        foreach ($candidates as $candidate) {
            similar_text(strtolower($raw), strtolower($label($candidate)), $percent);

            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $best = $candidate;
            }
        }

        return $bestPercent >= 60 ? $best->id : null;
    }

    /**
     * @return array{subjectId: int, teacherId: int, section: string}|null null when the row can't be fully resolved.
     */
    private function extractRow(Collection $row, Collection $subjects, Collection $teachers): ?array
    {
        $rawCourse = $this->cell($row, 'course');
        $rawTeacher = $this->cell($row, 'teacher');
        $section = $this->cell($row, 'section');

        if ($rawCourse === null || $rawTeacher === null || $section === null) {
            return null;
        }

        $subjectId = $this->matchSubject($rawCourse, $subjects)
            ?? $this->resolvedSubjectId($rawCourse);
        $teacherId = $this->matchTeacher($rawTeacher, $teachers)
            ?? $this->resolvedTeacherId($rawTeacher);

        if ($subjectId === null || $teacherId === null) {
            return null;
        }

        return ['subjectId' => $subjectId, 'teacherId' => $teacherId, 'section' => $section];
    }

    private function resolvedSubjectId(string $raw): ?int
    {
        return $this->resolvedFromList($this->unresolvedSubjects, $this->subjectResolutions, $raw);
    }

    private function resolvedTeacherId(string $raw): ?int
    {
        return $this->resolvedFromList($this->unresolvedTeachers, $this->teacherResolutions, $raw);
    }

    /**
     * @param  array<int, array{raw: string, suggestedId: ?int}>  $unresolvedList
     * @param  array<int, string>  $resolutions  index-aligned with $unresolvedList
     */
    private function resolvedFromList(array $unresolvedList, array $resolutions, string $raw): ?int
    {
        foreach ($unresolvedList as $index => $row) {
            if ($row['raw'] === $raw) {
                $value = $resolutions[$index] ?? null;

                return ($value === null || $value === '') ? null : (int) $value;
            }
        }

        return null;
    }

    private function buildReport(): array
    {
        $subjects = Subject::all(['id', 'code', 'title']);
        $teachers = Teacher::where('is_active', true)->get(['id', 'name']);
        $missingPairs = $this->pendingPairs()
            ->map(fn ($row) => MissingTeacherSections::key($row->subject_id, strtolower(trim($row->section))))
            ->flip();

        $errors = [];
        $unresolvedCourse = 0;
        $unresolvedTeacher = 0;
        $noSuchPair = 0;
        $valid = 0;
        $total = 0;

        foreach ($this->readDataRows() as $index => $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $total++;
            $excelRow = $index + 1;
            $rawCourse = $this->cell($row, 'course');
            $rawTeacher = $this->cell($row, 'teacher');
            $section = $this->cell($row, 'section');

            if ($rawCourse === null || $rawTeacher === null || $section === null) {
                $errors[] = ['row' => $excelRow, 'message' => 'Missing teacher, course or section — this row will be skipped.'];

                continue;
            }

            $subjectId = $this->matchSubject($rawCourse, $subjects) ?? $this->resolvedSubjectId($rawCourse);

            if ($subjectId === null) {
                $unresolvedCourse++;
                $errors[] = ['row' => $excelRow, 'message' => "Course '{$rawCourse}' wasn't matched to an existing subject — this row will be skipped."];

                continue;
            }

            $teacherId = $this->matchTeacher($rawTeacher, $teachers) ?? $this->resolvedTeacherId($rawTeacher);

            if ($teacherId === null) {
                $unresolvedTeacher++;
                $errors[] = ['row' => $excelRow, 'message' => "Teacher '{$rawTeacher}' wasn't matched to an existing teacher — this row will be skipped."];

                continue;
            }

            if (! $missingPairs->has(MissingTeacherSections::key($subjectId, strtolower(trim($section))))) {
                $noSuchPair++;
                $errors[] = ['row' => $excelRow, 'message' => $this->scopeIgnored
                    ? "That course/section isn't on this session's ignored list — either it already has a teacher, it's still pending on the main Missing Teachers list, or the section text doesn't match."
                    : "No pending (un-taught) enrollment found for that course/section — either it already has a teacher, it's been dismissed via Ignore All, or the section text doesn't match this session's data."];

                continue;
            }

            $valid++;
        }

        return [
            'total' => $total,
            'valid' => $valid,
            'unresolvedCourse' => $unresolvedCourse,
            'unresolvedTeacher' => $unresolvedTeacher,
            'noSuchPair' => $noSuchPair,
            'errors' => array_slice($errors, 0, 50),
            'errorsTruncated' => count($errors) > 50,
        ];
    }

    private function cell(Collection $row, string $field): ?string
    {
        $index = $this->mappedIndex($field);

        if ($index === null) {
            return null;
        }

        $value = trim((string) $row->get($index));

        return $value !== '' ? $value : null;
    }

    private function mappedIndex(string $field): ?int
    {
        $value = $this->mapping[$field] ?? null;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function guessMapping(): array
    {
        $patterns = [
            'teacher' => ['teacher'],
            'course' => ['course', 'subject'],
            'section' => ['section'],
        ];

        return collect(self::TARGET_FIELDS)->keys()->mapWithKeys(function (string $field) use ($patterns) {
            $needles = $patterns[$field];
            $match = collect($this->sourceColumns)->first(function (array $col) use ($needles) {
                $label = strtolower($col['label']);

                foreach ($needles as $needle) {
                    if (str_contains($label, $needle)) {
                        return true;
                    }
                }

                return false;
            });

            return [$field => $match['index'] ?? null];
        })->all();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.sessions.missing-teachers-import');
    }
}
