<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class cleanExpiredShares implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param bool $force Run even when the auto-clean setting is disabled
     *                    (used by the manual artisan command).
     */
    public function __construct(public bool $force = false) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $enabled = (new SettingsService())->get('auto_clean_expired_shares') ?? true;

        if (!$enabled && !$this->force) {
            Log::info('Skipping expired share cleanup: auto_clean_expired_shares is disabled');
            return;
        }

        Log::info('Cleaning expired shares');
        $startTime = microtime(true);
        $shares = Share::readyForCleaning()->get();
        Log::info('Found ' . $shares->count() . ' shares to clean');
        foreach ($shares as $share) {
            Log::info('Cleaning share ' . $share->id);
            $share->cleanFiles();
            Log::info('Share ' . $share->id . ' cleaned');
        }
        $endTime = microtime(true);
        $timeTaken = $endTime - $startTime;
        Log::info('Finished cleaning expired shares after ' . $timeTaken . ' seconds');
    }
}
