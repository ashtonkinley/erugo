<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\cleanExpiredShares;
use App\Jobs\SyncBestOfBackgrounds;
use App\Jobs\maintainDb;
use App\Jobs\sendExpiryWarningEmails;
use App\Jobs\sendExpiredWarningEmails;
use App\Jobs\sendDeletionWarningEmails;
use App\Jobs\pruneLogs;
use App\Jobs\updateLegacySharePaths;
use App\Jobs\backUpDatabase;
use App\Services\SettingsService;

//daily jobs
Schedule::job(cleanExpiredShares::class)->daily();
Schedule::job(sendExpiryWarningEmails::class)->daily();
Schedule::job(sendDeletionWarningEmails::class)->daily();
Schedule::job(maintainDb::class)->daily();
Schedule::job(pruneLogs::class)->daily();
Schedule::job(backUpDatabase::class)->daily();
Schedule::job(SyncBestOfBackgrounds::class)->daily();
//hourly jobs
Schedule::job(sendExpiredWarningEmails::class)->hourly();
//manually run jobs
Artisan::command('clean-expired-shares', function () {
    cleanExpiredShares::dispatch();
})->purpose('Clean expired shares');

Artisan::command('send-expiry-warning', function () {
    sendExpiryWarningEmails::dispatch();
})->purpose('Send expiry warning emails');

Artisan::command('send-expired-warning', function () {
    sendExpiredWarningEmails::dispatch();
})->purpose('Send expired warning emails');

Artisan::command('send-deletion-warning', function () {
    sendDeletionWarningEmails::dispatch();
})->purpose('Send deletion warning emails');

Artisan::command('maintain-db', function () {
    maintainDb::dispatch();
})->purpose('Maintain the database');

Artisan::command('prune-logs', function () {
    pruneLogs::dispatch();
})->purpose('Prune logs');

Artisan::command('update-legacy-share-paths', function () {
    updateLegacySharePaths::dispatch();
})->purpose('Update legacy share paths');

Artisan::command('back-up-database', function () {
    backUpDatabase::dispatch();
})->purpose('Back up the database');

Artisan::command('backgrounds:sync-bestof', function () {
    SyncBestOfBackgrounds::dispatch();
})->purpose('Sync monthly 10-best photos into rotating backgrounds');

Artisan::command('backgrounds:blocked', function () {
    $blocked = SyncBestOfBackgrounds::blockedFilenames();
    if (empty($blocked)) {
        $this->info('No backgrounds are blocked.');
        return;
    }
    $this->info(count($blocked) . ' blocked background(s):');
    foreach ($blocked as $file) {
        $this->line('  ' . $file);
    }
})->purpose('List auto-synced backgrounds the user removed');

Artisan::command('backgrounds:unblock {filename?} {--all}', function () {
    if ($this->option('all')) {
        $count = SyncBestOfBackgrounds::unblockFilenames(null, true);
        $this->info("Unblocked all {$count} background(s). Run backgrounds:sync-bestof to re-sync them.");
        return;
    }
    $filename = $this->argument('filename');
    if (!$filename) {
        $this->error('Provide a filename or use --all.');
        return;
    }
    $count = SyncBestOfBackgrounds::unblockFilenames($filename);
    if ($count) {
        $this->info("Unblocked {$filename}. Run backgrounds:sync-bestof to re-sync it.");
    } else {
        $this->info("{$filename} was not blocked.");
    }
})->purpose('Allow a removed background to sync again');

Artisan::command('clear-settings-cache', function () {
    app(SettingsService::class)->clearCache();
    $this->info('Settings cache cleared.');
})->purpose('Clear the settings cache');
