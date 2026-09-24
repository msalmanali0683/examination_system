<?php

namespace Tests\Feature;

use App\Livewire\Sessions\EnrollmentImport;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EnrollmentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Headers deliberately include "Campus Name" and "Department Name"
     * before the real "Name" column, and a messy course code with a
     * stray space, to mirror the real SIS export and its pitfalls.
     */
    private function csv(): UploadedFile
    {
        $content = <<<'CSV'
        Campus Name,Department Name,SapNo,Name,Program Title,AdmissonYear,Course Code,Course Title,Cr.Hrs,Section,Teacher,PERNR,EMAIL
        Lahore Campus,Dept of SE,70138441,Moeez Arif,BSAI,2026,CS09186|11,Applications of ICT,3,BSAI 1B,Huria Ali,22044,huria.ali@example.com
        Lahore Campus,Dept of SE,70138441,Moeez Arif,BSAI,2026,PHY01115|11,Applied Physics,3,BSAI 1B,Ahmed Iftikhar,6206,ahmed@example.com
        Lahore Campus,Dept of SE,70172132,Zahra Batool,BSAI,2024,CS06301| 11,Data Structures,4,BSAI 4B,,,
        Lahore Campus,Dept of SE,,Missing Rollno,BSAI,2026,CS09186|11,Applications of ICT,3,BSAI 1B,Huria Ali,22044,huria.ali@example.com
        Lahore Campus,Dept of SE,70138441,Moeez Arif,BSAI,2026,CS09186|11,Applications of ICT,3,BSAI 1B,Huria Ali,22044,huria.ali@example.com
        CSV;

        return UploadedFile::fake()->createWithContent('enrollments.csv', $content);
    }

    public function test_column_guessing_prefers_exact_name_match_over_campus_or_department_name(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->assertSet('step', 'map')
            ->assertSet('mapping.roll_no', 2)
            ->assertSet('mapping.student_name', 3)
            ->assertSet('mapping.program', 4)
            ->assertSet('mapping.admission_year', 5)
            ->assertSet('mapping.course_code', 6)
            ->assertSet('mapping.course_title', 7)
            ->assertSet('mapping.credit_hours', 8)
            ->assertSet('mapping.section', 9)
            ->assertSet('mapping.teacher_name', 10)
            ->assertSet('mapping.teacher_pernr', 11)
            ->assertSet('mapping.teacher_email', 12);
    }

    public function test_review_report_flags_missing_required_and_duplicate_pairs(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $component = Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping');

        $report = $component->get('report');

        $this->assertSame(5, $report['total']);
        $this->assertSame(4, $report['valid']);
        $this->assertSame(1, $report['missingRequired']);
        $this->assertSame(1, $report['duplicateInFile']);
        $this->assertSame(1, $report['blankTeacher']);
        $this->assertSame(2, $report['newStudentsCount']);
        $this->assertEqualsCanonicalizing(['CS09186|11', 'PHY01115|11', 'CS06301|11'], $report['newSubjects']);
    }

    public function test_commit_creates_students_subjects_teachers_and_enrollments(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport')
            ->assertSet('step', 'done')
            ->assertSet('createdEnrollments', 3)
            ->assertSet('updatedEnrollments', 1);

        $this->assertDatabaseHas('students', ['roll_no' => '70138441', 'name' => 'Moeez Arif']);
        $this->assertDatabaseHas('students', ['roll_no' => '70172132', 'name' => 'Zahra Batool']);
        $this->assertDatabaseMissing('students', ['name' => 'Missing Rollno']);

        // The messy "CS06301| 11" code normalizes to "CS06301|11".
        $this->assertDatabaseHas('subjects', ['code' => 'CS06301|11', 'title' => 'Data Structures']);
        $this->assertDatabaseHas('subjects', ['code' => 'CS09186|11']);
        $this->assertDatabaseHas('subjects', ['code' => 'PHY01115|11']);

        $this->assertDatabaseHas('teachers', ['pernr' => '22044', 'name' => 'Huria Ali']);
        $this->assertDatabaseHas('teachers', ['pernr' => '6206', 'name' => 'Ahmed Iftikhar']);

        $this->assertDatabaseCount('enrollments', 3);
        $this->assertDatabaseHas('enrollments', [
            'exam_session_id' => $session->id,
            'section' => 'BSAI 1B',
        ]);
    }

    public function test_done_step_shows_a_subject_student_count_summary(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $component = Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        // 3 distinct subjects were created from the fixture CSV.
        $summary = $component->viewData('subjectSummary');
        $this->assertCount(3, $summary);
        $this->assertSame(1, $summary->firstWhere('code', 'CS09186|11')->enrollments_count);

        $subject = Subject::where('code', 'CS09186|11')->first();
        $breakdown = $component->viewData('sectionBreakdown')->get($subject->id);
        $this->assertSame(['BSAI 1B' => 1], $breakdown->all());
    }

    public function test_reimporting_the_same_pair_updates_rather_than_duplicates(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $import = fn () => Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $import();
        $this->assertDatabaseCount('enrollments', 3);

        $import();
        $this->assertDatabaseCount('enrollments', 3);
    }

    public function test_existing_teacher_matched_by_pernr_is_reused_not_duplicated(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        Teacher::factory()->for($session)->create(['pernr' => '22044', 'name' => 'Huria Ali (old spelling)']);

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertDatabaseCount('teachers', 2); // Huria Ali (reused) + Ahmed Iftikhar
    }

    public function test_importing_a_merged_away_code_resolves_to_the_surviving_subject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $survivor = Subject::factory()->for($session)->create(['code' => 'CS09186-NEW|11', 'title' => 'ICT Applications']);
        $mergedAway = Subject::factory()->for($session)->create([
            'code' => 'CS09186|11',
            'title' => 'Applications of ICT',
            'merged_into_id' => $survivor->id,
        ]);

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertDatabaseHas('enrollments', [
            'exam_session_id' => $session->id,
            'subject_id' => $survivor->id,
        ]);
        $this->assertDatabaseMissing('enrollments', ['subject_id' => $mergedAway->id]);
    }

    public function test_user_without_manage_enrollments_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_enrollments', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get("/sessions/{$session->id}/enrollments/import")
            ->assertForbidden();
    }

    /**
     * Regression test for a real bug: a real department export had a
     * small pivot-summary sheet ("Sheet2") listed BEFORE the actual data
     * sheet ("Sheet1"). Blindly reading the workbook's first sheet
     * silently imported the wrong tab (every header cell blank, so the
     * mapping UI fell back to showing column letters A, B, C...).
     */
    public function test_picks_the_data_sheet_when_a_smaller_summary_sheet_comes_first(): void
    {
        $spreadsheet = new Spreadsheet;

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Sheet2');
        $summarySheet->setCellValue('A2', 'Section');
        $summarySheet->setCellValue('B2', '(All)');

        $dataSheet = $spreadsheet->createSheet();
        $dataSheet->setTitle('Sheet1');
        $dataSheet->fromArray(['SapNo', 'Name', 'Course Code', 'Course Title', 'Section'], null, 'A1');
        $dataSheet->fromArray(['70138441', 'Moeez Arif', 'CS09186|11', 'Applications of ICT', 'BSAI 1B'], null, 'A2');

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);
        $upload = UploadedFile::fake()->createWithContent('multi-sheet.xlsx', file_get_contents($tempPath));
        unlink($tempPath);

        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $upload)
            ->assertSet('step', 'map')
            ->assertSet('selectedSheetIndex', 1)
            ->assertSet('mapping.roll_no', 0)
            ->assertSet('mapping.student_name', 1)
            ->assertSet('mapping.course_code', 2);
    }

    public function test_import_creates_students_subjects_and_teachers_owned_by_the_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertGreaterThan(0, $session->students()->count());
        $this->assertGreaterThan(0, $session->subjects()->count());
        $this->assertGreaterThan(0, $session->teachers()->count());
        $this->assertSame(Student::count(), $session->students()->count());
        $this->assertSame(Subject::count(), $session->subjects()->count());
        $this->assertSame(Teacher::count(), $session->teachers()->count());
    }

    public function test_the_same_file_imported_into_two_sessions_keeps_them_completely_separate(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $sessionA = ExamSession::factory()->create();
        $sessionB = ExamSession::factory()->create();

        foreach ([$sessionA, $sessionB] as $session) {
            Livewire::actingAs($staff)
                ->test(EnrollmentImport::class, ['examSession' => $session])
                ->set('file', $this->csv())
                ->call('confirmMapping')
                ->call('commitImport');
        }

        $this->assertSame($sessionA->students()->count(), $sessionB->students()->count());
        $this->assertSame($sessionA->subjects()->count(), $sessionB->subjects()->count());
        $this->assertSame($sessionA->teachers()->count(), $sessionB->teachers()->count());
        $this->assertSame([], $sessionA->students()->pluck('id')->intersect($sessionB->students()->pluck('id'))->all());
        $this->assertSame([], $sessionA->teachers()->pluck('id')->intersect($sessionB->teachers()->pluck('id'))->all());

        // Every enrollment points at rows owned by its own session.
        foreach ([$sessionA, $sessionB] as $session) {
            foreach ($session->enrollments()->with(['student', 'subject'])->get() as $enrollment) {
                $this->assertSame($session->id, $enrollment->student->exam_session_id);
                $this->assertSame($session->id, $enrollment->subject->exam_session_id);
            }
        }
    }

    public function test_a_teacher_named_in_the_file_is_matched_to_the_sessions_existing_teacher_by_name(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        $mine = Teacher::factory()->for($session)->create(['name' => 'Ahmed Iftikhar', 'email' => null, 'pernr' => null]);
        Teacher::factory()->for($other)->create(['name' => 'Huria Ali', 'email' => null, 'pernr' => null]);

        $content = "SAPNO,Name,Course Code,Course Title,Section,Teacher\n70100001,Ali,CS101,Intro,BSAI 1A,Ahmed Iftikhar\n70100002,Bilal,CS101,Intro,BSAI 1A,Huria Ali\n";

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', UploadedFile::fake()->createWithContent('e.csv', $content))
            ->call('confirmMapping')
            ->call('commitImport');

        // "Ahmed Iftikhar" already exists in this session and is reused;
        // "Huria Ali" only exists in the other session, so this session
        // gets its own new copy instead of borrowing that one.
        $this->assertSame(2, $session->teachers()->count());
        $this->assertSame(1, $session->teachers()->where('name', 'Ahmed Iftikhar')->count());
        $this->assertSame($mine->id, $session->teachers()->where('name', 'Ahmed Iftikhar')->value('id'));
        $this->assertSame(1, $other->teachers()->count());
    }
}
