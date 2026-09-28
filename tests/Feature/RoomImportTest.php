<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Import;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RoomImportTest extends TestCase
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
        Room Name,Room Type,Rows,Columns,Capacity
        ITC-401,Regular,10,5,50
        ITC-402,Lab,10,4,35
        ,Regular,10,4,40
        ITC-401,Regular,10,5,999
        CSV;

        return UploadedFile::fake()->createWithContent('rooms.csv', $content);
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
            ->assertSet('mapping.room_type', 1)
            ->assertSet('mapping.rows', 2)
            ->assertSet('mapping.columns', 3)
            ->assertSet('mapping.capacity', 4);
    }

    public function test_confirm_mapping_requires_name_rows_and_columns(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->set('mapping.rows', '')
            ->call('confirmMapping')
            ->assertHasErrors(['mapping.rows'])
            ->assertSet('step', 'map');
    }

    public function test_review_flags_missing_required_fields_duplicate_names_and_oversized_capacity(): void
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
        $this->assertSame(1, $report['missingRequired']);
        $this->assertSame(1, $report['duplicateNames']);
        $this->assertSame(1, $report['capacityAdjusted']);
    }

    public function test_commit_creates_and_updates_rooms(): void
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

        $this->assertDatabaseHas('rooms', [
            'name' => 'ITC-401',
            'rows' => 10,
            'columns' => 5,
            'capacity' => 50,
            'room_type' => 'regular',
        ]);
        $this->assertDatabaseHas('rooms', [
            'name' => 'ITC-402',
            'rows' => 10,
            'columns' => 4,
            'capacity' => 35,
            'room_type' => 'lab',
        ]);
        $this->assertDatabaseMissing('rooms', ['rows' => 10, 'columns' => 4, 'capacity' => 40, 'name' => '']);
    }

    public function test_capacity_is_capped_at_the_rooms_grid_size(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        // Row 4 (ITC-401 again, capacity 999) overwrote row 1's room but the
        // capacity must never exceed the 10x5 = 50 seat grid.
        $this->assertDatabaseHas('rooms', ['name' => 'ITC-401', 'capacity' => 50]);
    }

    public function test_missing_capacity_column_defaults_to_the_full_grid(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $content = <<<'CSV'
        Room Name,Rows,Columns
        ITC-999,10,4
        CSV;

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', UploadedFile::fake()->createWithContent('rooms-no-capacity.csv', $content))
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertDatabaseHas('rooms', ['name' => 'ITC-999', 'capacity' => 40]);
    }

    public function test_user_without_manage_rooms_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $staff->permissionOverrides()->create(['permission' => 'manage_rooms', 'granted' => false]);

        $this->actingAs($staff)
            ->get(route('sessions.rooms.import', $session))
            ->assertForbidden();
    }

    public function test_import_only_touches_its_own_sessions_rooms(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        $untouched = Room::factory()->for($other)->create(['name' => 'ITC-401', 'rows' => 3, 'columns' => 3, 'capacity' => 9]);

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $this->csv())
            ->call('confirmMapping')
            ->call('commitImport');

        $this->assertSame(2, $session->rooms()->count());
        $this->assertSame(9, $untouched->fresh()->capacity);
        $this->assertSame(1, $other->rooms()->count());
    }

    /**
     * PHP's content sniffing reports a CSV with only a header and one data row as text/plain (verified on the
     * live server), and a mime-type based rule then rejected it as "not a csv". The extension is what tells the
     * spreadsheet reader how to parse the file, so that is what is validated.
     */
    public function test_a_csv_that_sniffs_as_plain_text_is_still_accepted(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        // Livewire's test upload needs a fake file; this one is declared text/plain, as the sniffer reports it.
        $plainText = UploadedFile::fake()->create('rooms.csv', 1, 'text/plain');

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', $plainText)
            ->assertHasNoErrors('file')
            ->assertSet('step', 'map');
    }

    public function test_a_file_with_a_non_spreadsheet_extension_is_rejected(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Import::class, ['examSession' => $session])
            ->set('file', UploadedFile::fake()->createWithContent('rooms.txt', "Room Name,Rows,Columns\nITC-1,5,5\n"))
            ->assertHasErrors('file')
            ->assertSet('step', 'upload');
    }
}
