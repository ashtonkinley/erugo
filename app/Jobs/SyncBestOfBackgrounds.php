<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sync the lab's monthly "10 best" photos into the rotating landing-page
 * backgrounds.
 *
 * Source: each month folder under the BestOf archive (config
 * mtlanalogue.bestof_path) may contain a _10Best subfolder. Every image in
 * there is copied once into the backgrounds disk, prefixed with the month
 * folder name so files from different months can never collide. The landing
 * page picks a random background from that disk, so newly synced months join
 * the rotation automatically.
 *
 * The job is idempotent: files that already exist are skipped, months without
 * a _10Best folder are skipped, and a missing BestOf mount logs a warning
 * instead of failing.
 */
class SyncBestOfBackgrounds implements ShouldQueue
{
  use Queueable;

  private const TEN_BEST_DIR = '_10Best';
  private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

  public function handle(): void
  {
    $bestOfPath = config('mtlanalogue.bestof_path');

    if (!$bestOfPath || !is_dir($bestOfPath)) {
      Log::warning('BestOf background sync skipped: source path not available', [
        'path' => $bestOfPath,
      ]);
      return;
    }

    $disk = Storage::disk('backgrounds');
    $added = 0;
    $skipped = 0;

    foreach (scandir($bestOfPath) as $monthFolder) {
      if ($monthFolder === '.' || $monthFolder === '..') {
        continue;
      }

      // $monthFolder is a single path component from scandir, so this cannot
      // traverse outside the BestOf archive.
      $tenBestDir = $bestOfPath . '/' . $monthFolder . '/' . self::TEN_BEST_DIR;
      if (!is_dir($tenBestDir)) {
        continue; // no 10-best published for this month yet
      }

      foreach (scandir($tenBestDir) as $file) {
        if ($file === '.' || $file === '..') {
          continue;
        }
        // Skip the monthly favourites collage/exports and anything that
        // isn't a photo: lab-internal files are underscore-prefixed and the
        // collage name isn't consistent about it, so match it explicitly.
        if (str_starts_with($file, '.') || str_starts_with($file, '_')) {
          continue;
        }
        if (stripos($file, 'monthlyfavourites') !== false) {
          continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
          continue;
        }

        $target = $monthFolder . '_' . $file;
        if ($disk->exists($target)) {
          $skipped++;
          continue;
        }

        $stream = fopen($tenBestDir . '/' . $file, 'r');
        if ($stream === false) {
          Log::warning('BestOf background sync: could not read file', ['file' => $file]);
          continue;
        }
        $disk->put($target, $stream);
        if (is_resource($stream)) {
          fclose($stream);
        }

        $added++;
        Log::info('BestOf background added', ['file' => $target]);
      }
    }

    Log::info('BestOf background sync complete', [
      'added' => $added,
      'already_present' => $skipped,
    ]);
  }
}
