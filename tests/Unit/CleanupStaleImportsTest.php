<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupStaleImportsTest extends TestCase
{
    public function test_it_deletes_only_files_older_than_a_day(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $disk->put('imports/old.csv', 'stale');
        $disk->put('imports/fresh.csv', 'recent');

        touch($disk->path('imports/old.csv'), now()->subDays(2)->timestamp);
        touch($disk->path('imports/fresh.csv'), now()->timestamp);

        $this->artisan('app:cleanup-stale-imports')
            ->expectsOutputToContain('Deleted 1 stale import file(s).')
            ->assertExitCode(0);

        $disk->assertMissing('imports/old.csv');
        $disk->assertExists('imports/fresh.csv');
    }
}
