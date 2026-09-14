<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\HandlesExcelUpload;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\SubjectCodeNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

class EnrollmentImport extends Component
{
    use HandlesExcelUpload;

    private const TARGET_FIELDS = [
        'roll_no' => 'Roll No / SAP No',
        'student_name' => 'Student Name',
        'program' => 'Program',
        'admission_year' => 'Admission Year',
        'course_code' => 'Course Code',
        'course_title' => 'Course Title',
        'credit_hours' => 'Credit Hours',
        'section' => 'Section',
        'teacher_name' => 'Teacher Name',
        'teacher_pernr' => 'Teacher PERNR',
        'teacher_email' => 'Teacher Email',
    ];

    private const REQUIRED_FIELDS = ['roll_no', 'student_name', 'course_code', 'course_title', 'section'];

    /**
     * Exact (lowercased, trimmed) header text to try first, before
     * falling back to leaving the field unmapped. Kept as exact matches
     * only (no substring fallback) because several real headers collide
     * on substrings otherwise (e.g. "Campus Name"/"Department Name" both
     * contain "name", which would wrongly out-guess the actual "Name"
     * column for student_name).
     */
    private const EXACT_HINTS = [
        'roll_no' => ['sapno', 'roll no', 'roll_no', 'rollno'],
        'student_name' => ['name', 'student name'],
        'program' => ['program title', 'program'],
        'admission_year' => ['admissonyear', 'admission year', 'admissionyear'],
        'course_code' => ['course code'],
        'course_title' => ['course title'],
        'credit_hours' => ['cr.hrs', 'credit hours', 'cr hrs'],
        'section' => ['section'],
        'teacher_name' => ['teacher'],
        'teacher_pernr' => ['pernr'],
        'teacher_email' => ['email'],
    ];

    public ExamSession $examSession;

    /** @var array<string, string|int|null> target field => source column index */
    public array $mapping = [];

    public string $step = 'upload';

    public array $report = [];

    public int $createdEnrollments = 0;

    public int $updatedEnrollments = 0;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_enrollments');
        $this->examSession = $examSession;
    }

    /**
     * Enrollment files run to thousands of rows; don't depend on the
     * host's php.ini defaults (e.g. a stock Apache config's 128M/30s)
     * being generous enough for parsing + several thousand row upserts.
     */
    private function raiseResourceLimits(): void
    {
        ini_set('memory_limit', '512M');
        set_time_limit(180);
    }

    public function targetFields(): array
    {
        return self::TARGET_FIELDS;
    }

    public function requiredFields(): array
    {
        return self::REQUIRED_FIELDS;
    }

    public function updatedFile(): void
    {
        $this->authorize('manage_enrollments');
        $this->raiseResourceLimits();

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480']]);

        $this->storeUploadedFile();
        $this->detectBestSheet();
        $this->loadSourceColumns();

        $this->mapping = $this->guessMapping();
        $this->step = 'map';
    }

    public function selectSheet(int $index): void
    {
        $this->authorize('manage_enrollments');
        $this->selectedSheetIndex = $index;
        $this->loadSourceColumns();
        $this->mapping = $this->guessMapping();
    }

    public function confirmMapping(): void
    {
        $this->authorize('manage_enrollments');
        $this->raiseResourceLimits();

        $missing = false;

        foreach (self::REQUIRED_FIELDS as $field) {
            if ($this->mappedIndex($field) === null) {
                $this->addError("mapping.{$field}", 'This column is required.');
                $missing = true;
            }
        }

        if ($missing) {
            return;
        }

        $this->report = $this->buildReport();
        $this->step = 'review';
    }

    public function commitImport(): void
    {
        $this->authorize('manage_enrollments');
        $this->raiseResourceLimits();

        $existingSubjects = Subject::pluck('id', 'code');
        $existingStudents = Student::pluck('id', 'roll_no');
        $teacherCache = [];
        $created = 0;
        $updated = 0;

        DB::transaction(function () use (&$existingSubjects, &$existingStudents, &$teacherCache, &$created, &$updated) {
            foreach ($this->readDataRows() as $row) {
                if ($this->rowIsBlank($row)) {
                    continue;
                }

                $data = $this->extractRow($row);

                if ($data === null) {
                    continue;
                }

                $student = $existingStudents->has($data['roll_no'])
                    ? Student::find($existingStudents[$data['roll_no']])
                    : null;

                if ($student) {
                    $student->update([
                        'name' => $data['student_name'],
                        'program' => $data['program'] ?? $student->program,
                        'admission_year' => $data['admission_year'] ?? $student->admission_year,
                    ]);
                } else {
                    $student = Student::create([
                        'roll_no' => $data['roll_no'],
                        'name' => $data['student_name'],
                        'program' => $data['program'],
                        'admission_year' => $data['admission_year'],
                    ]);
                    $existingStudents[$data['roll_no']] = $student->id;
                }

                $code = $data['course_code'];

                if ($existingSubjects->has($code)) {
                    $subject = Subject::find($existingSubjects[$code]);
                } else {
                    $subject = Subject::create([
                        'code' => $code,
                        'title' => $data['course_title'],
                        'credit_hours' => $data['credit_hours'],
                    ]);
                    $existingSubjects[$code] = $subject->id;
                }

                $teacherId = null;

                if ($data['teacher_name'] !== null) {
                    $teacherKey = $data['teacher_pernr'] ?? $data['teacher_email'] ?? ('name:'.$data['teacher_name']);
                    $teacherId = ($teacherCache[$teacherKey] ??= $this->resolveTeacher(
                        $data['teacher_pernr'],
                        $data['teacher_email'],
                        $data['teacher_name']
                    ))->id;
                }

                $enrollment = Enrollment::updateOrCreate(
                    [
                        'exam_session_id' => $this->examSession->id,
                        'student_id' => $student->id,
                        'subject_id' => $subject->id,
                    ],
                    [
                        'section' => $data['section'],
                        'teacher_id' => $teacherId,
                    ]
                );

                $enrollment->wasRecentlyCreated ? $created++ : $updated++;
            }
        });

        $this->cleanupUploadedFile();
        $this->createdEnrollments = $created;
        $this->updatedEnrollments = $updated;
        $this->step = 'done';
    }

    public function startOver(): void
    {
        $this->cleanupUploadedFile();
        $this->reset(['file', 'storedPath', 'sourceColumns', 'availableSheets', 'selectedSheetIndex', 'mapping', 'report', 'createdEnrollments', 'updatedEnrollments']);
        $this->step = 'upload';
    }

    private function resolveTeacher(?string $pernr, ?string $email, string $name): Teacher
    {
        if ($pernr && $teacher = Teacher::where('pernr', $pernr)->first()) {
            return $teacher;
        }

        if ($email && $teacher = Teacher::where('email', $email)->first()) {
            if ($pernr && ! $teacher->pernr) {
                $teacher->update(['pernr' => $pernr]);
            }

            return $teacher;
        }

        return Teacher::create(['name' => $name, 'email' => $email, 'pernr' => $pernr]);
    }

    private function buildReport(): array
    {
        $existingSubjectCodes = Subject::pluck('code')->flip();
        $existingRollNos = Student::pluck('roll_no')->flip();

        $errors = [];
        $seenPairs = [];
        $newSubjectCodes = [];
        $newRollNos = [];
        $duplicateInFile = 0;
        $missingRequired = 0;
        $blankTeacher = 0;
        $valid = 0;
        $total = 0;

        foreach ($this->readDataRows() as $index => $row) {
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $total++;
            $excelRow = $index + 1;
            $data = $this->extractRow($row);

            if ($data === null) {
                $missingRequired++;
                $errors[] = ['row' => $excelRow, 'message' => 'Missing a required field — this row will be skipped.'];

                continue;
            }

            $pairKey = $data['roll_no'].'|'.$data['course_code'];

            if (isset($seenPairs[$pairKey])) {
                $duplicateInFile++;
                $errors[] = [
                    'row' => $excelRow,
                    'message' => "Student {$data['roll_no']} already enrolled in {$data['course_code']} on row {$seenPairs[$pairKey]} — this row will overwrite that section/teacher.",
                ];
            }

            $seenPairs[$pairKey] = $excelRow;

            if ($data['teacher_name'] === null) {
                $blankTeacher++;
            }

            if (! $existingSubjectCodes->has($data['course_code'])) {
                $newSubjectCodes[$data['course_code']] = true;
            }

            if (! $existingRollNos->has($data['roll_no'])) {
                $newRollNos[$data['roll_no']] = true;
            }

            $valid++;
        }

        return [
            'total' => $total,
            'valid' => $valid,
            'missingRequired' => $missingRequired,
            'duplicateInFile' => $duplicateInFile,
            'blankTeacher' => $blankTeacher,
            'newSubjects' => array_keys($newSubjectCodes),
            'newStudentsCount' => count($newRollNos),
            'errors' => array_slice($errors, 0, 50),
            'errorsTruncated' => count($errors) > 50,
        ];
    }

    /**
     * @return array<string, mixed>|null null when a required field is missing.
     */
    private function extractRow(Collection $row): ?array
    {
        $rollNo = $this->cell($row, 'roll_no');
        $studentName = $this->cell($row, 'student_name');
        $rawCode = $this->cell($row, 'course_code');
        $courseTitle = $this->cell($row, 'course_title');
        $section = $this->cell($row, 'section');

        if ($rollNo === null || $studentName === null || $rawCode === null || $courseTitle === null || $section === null) {
            return null;
        }

        $creditHours = $this->cell($row, 'credit_hours');

        return [
            'roll_no' => $rollNo,
            'student_name' => $studentName,
            'program' => $this->cell($row, 'program'),
            'admission_year' => $this->cell($row, 'admission_year'),
            'course_code' => SubjectCodeNormalizer::normalize($rawCode),
            'course_title' => $courseTitle,
            'credit_hours' => $creditHours !== null ? (float) $creditHours : null,
            'section' => $section,
            'teacher_name' => $this->cell($row, 'teacher_name'),
            'teacher_pernr' => $this->cell($row, 'teacher_pernr'),
            'teacher_email' => $this->cell($row, 'teacher_email'),
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
        return collect(self::TARGET_FIELDS)->keys()->mapWithKeys(function (string $field) {
            foreach (self::EXACT_HINTS[$field] ?? [] as $hint) {
                $match = collect($this->sourceColumns)->first(
                    fn (array $col) => strtolower(trim($col['label'])) === $hint
                );

                if ($match) {
                    return [$field => $match['index']];
                }
            }

            return [$field => null];
        })->all();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $subjectSummary = $this->step === 'done'
            ? Subject::whereHas('enrollments', fn ($q) => $q->where('exam_session_id', $this->examSession->id))
                ->withCount(['enrollments' => fn ($q) => $q->where('exam_session_id', $this->examSession->id)])
                ->orderBy('code')
                ->get()
            : collect();

        return view('livewire.sessions.enrollment-import', [
            'subjectSummary' => $subjectSummary,
        ]);
    }
}
