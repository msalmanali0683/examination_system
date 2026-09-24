<?php

namespace Tests\Feature;

use App\Livewire\Sessions\MissingTeachersImport;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\MissingTeacherSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MissingTeachersImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('missing-teachers.csv', $content);
    }

    private function enroll(ExamSession $session, Subject $subject, string $section, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => Student::factory(),
                'subject_id' => $subject->id,
                'section' => $section,
            ]);
        }
    }

    public function test_upload_guesses_column_mapping(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 1A\n"))
            ->assertSet('step', 'map')
            ->assertSet('mapping.teacher', 0)
            ->assertSet('mapping.course', 1)
            ->assertSet('mapping.section', 2);
    }

    public function test_exact_matches_skip_the_resolve_step_and_assign_directly(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $teacher = Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);
        $this->enroll($session, $subject, 'BSAI 1A', 3);

        $component = Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 1A\n"))
            ->call('confirmMapping');

        $component->assertSet('step', 'review');
        $report = $component->get('report');
        $this->assertSame(1, $report['total']);
        $this->assertSame(1, $report['valid']);

        $component->call('commitImport')
            ->assertSet('step', 'done')
            ->assertSet('pairsResolved', 1)
            ->assertSet('enrollmentsUpdated', 3);

        $this->assertSame(3, Enrollment::where('subject_id', $subject->id)->where('teacher_id', $teacher->id)->count());
    }

    public function test_unmatched_course_or_teacher_lands_on_the_resolve_step(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enroll($session, $subject, 'BSAI 1A');

        $component = Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nSomeone Unknown,CS999-not-real,BSAI 1A\n"))
            ->call('confirmMapping');

        $component->assertSet('step', 'resolve');
        $unresolvedSubjects = $component->get('unresolvedSubjects');
        $unresolvedTeachers = $component->get('unresolvedTeachers');

        $this->assertCount(1, $unresolvedSubjects);
        $this->assertSame('CS999-not-real', $unresolvedSubjects[0]['raw']);
        $this->assertCount(1, $unresolvedTeachers);
        $this->assertSame('Someone Unknown', $unresolvedTeachers[0]['raw']);
    }

    public function test_manually_resolving_an_unmatched_course_and_teacher_then_assigns_correctly(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $teacher = Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);
        $this->enroll($session, $subject, 'BSAI 1A', 2);

        $component = Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nH. Ali,Intro Programming,BSAI 1A\n"))
            ->call('confirmMapping')
            ->assertSet('step', 'resolve')
            ->set('subjectResolutions.0', (string) $subject->id)
            ->set('teacherResolutions.0', (string) $teacher->id)
            ->call('confirmResolutions')
            ->assertSet('step', 'review');

        $report = $component->get('report');
        $this->assertSame(1, $report['valid']);

        $component->call('commitImport')->assertSet('step', 'done');

        $this->assertSame(2, Enrollment::where('subject_id', $subject->id)->where('teacher_id', $teacher->id)->count());
    }

    public function test_import_never_overwrites_an_already_assigned_enrollment(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $existingTeacher = Teacher::factory()->for($session)->create(['name' => 'Original Teacher', 'is_active' => true]);
        $newTeacher = Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);

        $already = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => $existingTeacher->id,
        ]);
        $missing = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 1A\n"))
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertSame($existingTeacher->id, $already->fresh()->teacher_id);
        $this->assertSame($newTeacher->id, $missing->fresh()->teacher_id);
    }

    public function test_a_row_with_no_matching_pending_pair_is_reported_and_skipped(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);
        $this->enroll($session, $subject, 'BSAI 1A');

        $component = Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            // Section BSAI 9Z has no enrollments at all for this subject/session.
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 9Z\n"))
            ->call('confirmMapping');

        $report = $component->get('report');
        $this->assertSame(0, $report['valid']);
        $this->assertSame(1, $report['noSuchPair']);
    }

    public function test_user_without_manage_sessions_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_sessions', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.missing-teachers.import', $session))
            ->assertForbidden();
    }

    public function test_commit_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);
        $missing = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);

        Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 1A\n"))
            ->call('confirmMapping')
            ->call('commitImport')
            ->assertSet('step', 'review');

        $this->assertNull($missing->fresh()->teacher_id);
    }

    public function test_a_row_matching_an_ignored_pair_is_not_touched_by_the_general_import(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $subject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);
        $session->update(['ignored_missing_teacher_sections' => [
            MissingTeacherSections::key($subject->id, 'BSAI 1A'),
        ]]);

        $component = Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 1A\n"))
            ->call('confirmMapping');

        $report = $component->get('report');
        $this->assertSame(0, $report['valid']);
        $this->assertSame(1, $report['noSuchPair']);

        $component->call('commitImport');
        $this->assertNull($enrollment->fresh()->teacher_id);
    }

    public function test_scoped_to_ignored_only_resolves_ignored_pairs_not_pending_ones(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $ignoredSubject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $pendingSubject = Subject::factory()->for($session)->create(['code' => 'CS202', 'title' => 'Data Structures']);
        $teacher = Teacher::factory()->for($session)->create(['name' => 'Huria Ali', 'is_active' => true]);

        $ignoredEnrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $ignoredSubject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);
        $pendingEnrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id, 'subject_id' => $pendingSubject->id, 'section' => 'BSAI 1A', 'teacher_id' => null,
        ]);
        $session->update(['ignored_missing_teacher_sections' => [
            MissingTeacherSections::key($ignoredSubject->id, 'BSAI 1A'),
        ]]);

        $component = Livewire::actingAs($staff)
            ->test(MissingTeachersImport::class, ['examSession' => $session])
            ->set('scopeIgnored', true)
            ->set('file', $this->csv("Teacher Name,Course Code,Section\nHuria Ali,CS101,BSAI 1A\nHuria Ali,CS202,BSAI 1A\n"))
            ->call('confirmMapping');

        $report = $component->get('report');
        $this->assertSame(1, $report['valid']);
        $this->assertSame(1, $report['noSuchPair']);

        $component->call('commitImport');

        $this->assertSame($teacher->id, $ignoredEnrollment->fresh()->teacher_id);
        $this->assertNull($pendingEnrollment->fresh()->teacher_id);
    }

    public function test_the_ignored_query_string_switches_the_wizard_into_ignored_scope(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.missing-teachers.import', ['examSession' => $session, 'ignored' => 1]))
            ->assertOk()
            ->assertSee('Import Ignored Missing Teachers');

        $this->actingAs($staff)
            ->get(route('sessions.missing-teachers.import', $session))
            ->assertOk()
            ->assertSee('Import Missing Teachers')
            ->assertDontSee('Import Ignored Missing Teachers');
    }

    public function test_template_download_lists_pending_pairs_with_blank_teacher_column(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enroll($session, $subject, 'BSAI 1A');

        $response = $this->actingAs($staff)->get(route('sessions.missing-teachers.template', $session));

        $response->assertOk();
        $content = $response->streamedContent();
        $rows = array_map('str_getcsv', explode("\n", trim($content)));
        $this->assertSame(['Teacher Name', 'Course Code', 'Course Title', 'Section'], $rows[0]);
        $this->assertSame(['', 'CS101', 'Intro to Programming', 'BSAI 1A'], $rows[1]);
    }

    public function test_template_download_with_ignored_flag_lists_only_ignored_pairs(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $ignoredSubject = Subject::factory()->for($session)->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $pendingSubject = Subject::factory()->for($session)->create(['code' => 'CS202', 'title' => 'Data Structures']);
        $this->enroll($session, $ignoredSubject, 'BSAI 1A');
        $this->enroll($session, $pendingSubject, 'BSAI 1A');
        $session->update(['ignored_missing_teacher_sections' => [
            MissingTeacherSections::key($ignoredSubject->id, 'BSAI 1A'),
        ]]);

        $response = $this->actingAs($staff)->get(route('sessions.missing-teachers.template', ['examSession' => $session, 'ignored' => 1]));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('CS101', $content);
        $this->assertStringNotContainsString('CS202', $content);
    }
}
