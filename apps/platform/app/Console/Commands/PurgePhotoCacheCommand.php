<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderAccount;
use App\Services\Photos\CorePhotoCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Clears the kept pictures of remote photo sources: those unshown for
 * CorePhotoCache::KEEP_DAYS, and every folder whose connection is gone —
 * a disconnected service must not leave the family's pictures behind.
 */
final class PurgePhotoCacheCommand extends Command
{
    protected $signature = 'fahoba:purge-photo-cache';

    protected $description = 'Removes kept pictures of photo services that were not shown for a while or whose connection is gone';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $before = Carbon::now()->subDays(CorePhotoCache::KEEP_DAYS)->getTimestamp();
        $accounts = ProviderAccount::withoutHouseholdScope()->pluck('id')->map(static fn ($id): int => (int) $id)->flip();
        $removed = 0;

        foreach ($disk->directories(CorePhotoCache::DIRECTORY) as $household) {
            foreach ($disk->directories($household) as $folder) {
                if (! $accounts->has((int) basename($folder))) {
                    $disk->deleteDirectory($folder);

                    continue;
                }

                foreach ($disk->files($folder) as $file) {
                    if ($disk->lastModified($file) < $before) {
                        $disk->delete($file);
                        $removed++;
                    }
                }
            }
        }

        $this->info("Removed {$removed} kept pictures.");

        return self::SUCCESS;
    }
}
