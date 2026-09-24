<?php

namespace Tests\Feature;

use App\Livewire\Teachers\Import;
use App\Models\ExamSession;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(): UploadedFile
    {
        $content = <<<'CSV'
        Teacher Name,Designation,Department,Email Address
        Huria Ali,Lecturer,Software Engineering,huria.ali@example.com
        Ahmed Iftikhar,Lecturer,Physics,ahmed.iftikhar@example.com
        ,Lecturer,Software Engineering,blank.name@example.com
        Huria Ali (dup),Lecturer,Software Engineering,huria.ali@example.com
        CSV;

        return UploadedFile::fake()->createWithContent('teachers.csv', $content);
    }

    public function test_upload_guesses_column_mapping(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->assertSet('step', 'map')
            ->assertSet('mapping.name', 0)
            ->assertSet('mapping.designation', 1)
            ->assertSet('mapping.department', 2)
            ->assertSet('mapping.email', 3);
    }

    public function test_review_flags_missing_name_and_duplicate_email(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        $component = Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping');

        $report = $component->get('report');

        $this->assertSame(4, $report['total']);
        $this->assertSame(3, $report['valid']);
        $this->assertSame(1, $report['missingName']);
        $this->assertSame(1, $report['duplicateEmails']);
    }

    public function test_commit_creates_and_updates_teachers(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport')
            ->assertSet('step', 'done')
            ->assertSet('createdCount', 2)
            ->assertSet('updatedCount', 1);

        $this->assertDatabaseHas('teachers', [
            'email' => 'huria.ali@example.com',
            'name' => 'Huria Ali (dup)',
        ]);
        $this->assertDatabaseHas('teachers', ['email' => 'ahmed.iftikhar@example.com']);
        $this->assertDatabaseMissing('teachers', ['email' => 'blank.name@example.com']);
    }

    public function test_user_without_manage_teachers_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $staff->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);

        $this->actingAs($staff)
            ->get(route('sessions.teachers.import', $session))
            ->assertForbidden();
    }

    public function test_import_only_touches_its_own_sessions_teachers(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        $untouched = Teacher::factory()->for($other)->create(['email' => 'huria.ali@example.com', 'name' => 'Original Name']);

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertSame('Original Name', $untouched->fresh()->name);
        $this->assertSame(1, $other->teachers()->count());
        $this->assertTrue($session->teachers()->where('email', 'huria.ali@example.com')->exists());
    }
}
