<?php

namespace Tests\Feature;

use App\Livewire\Teachers\Import;
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
        $content = <<<CSV
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

        Livewire::actingAs($staff)
            ->test(Import::class)
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

        $component = Livewire::actingAs($staff)
            ->test(Import::class)
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

        Livewire::actingAs($staff)
            ->test(Import::class)
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
        $staff->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/teachers/import')
            ->assertForbidden();
    }
}
