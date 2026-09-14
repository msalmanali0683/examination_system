<?php

namespace Tests\Feature;

use App\Livewire\Sessions\EnrollmentImport;
use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
        $content = <<<CSV
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
        \App\Models\Teacher::factory()->create(['pernr' => '22044', 'name' => 'Huria Ali (old spelling)']);

        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertDatabaseCount('teachers', 2); // Huria Ali (reused) + Ahmed Iftikhar
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
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Sheet2');
        $summarySheet->setCellValue('A2', 'Section');
        $summarySheet->setCellValue('B2', '(All)');

        $dataSheet = $spreadsheet->createSheet();
        $dataSheet->setTitle('Sheet1');
        $dataSheet->fromArray(['SapNo', 'Name', 'Course Code', 'Course Title', 'Section'], null, 'A1');
        $dataSheet->fromArray(['70138441', 'Moeez Arif', 'CS09186|11', 'Applications of ICT', 'BSAI 1B'], null, 'A2');

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tempPath);
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
}
