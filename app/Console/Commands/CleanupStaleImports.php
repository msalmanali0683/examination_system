<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupStaleImports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-stale-imports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete abandoned import uploads (e.g. left when a user never completes or cancels an import wizard)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay();
        $deleted = 0;

        foreach ($disk->files('imports') as $path) {
            if ($disk->lastModified($path) < $cutoff->timestamp) {
                $disk->delete($path);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} stale import file(s).");
    }
}
