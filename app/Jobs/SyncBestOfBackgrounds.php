<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Setting;

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

  /**
   * Settings key holding the JSON list of auto-synced background filenames
   * the user has manually deleted. The sync never re-adds these.
   */
  private const BLOCKED_SETTING_KEY = 'bestof_backgrounds_blocked';

  /**
   * Was this background filename produced by this sync? Synced files look
   * like "11_BestOfMay_ClientName_Film_1.jpg".
   */
  public static function isSyncedFilename(string $filename): bool
  {
    return (bool) preg_match('/^\d+_BestOf[A-Za-z]+_.+\.(jpg|jpeg|png|gif|webp)$/i', $filename);
  }

  /**
   * Filenames the user has manually deleted and doesn't want re-synced.
   */
  public static function blockedFilenames(): array
  {
    $value = Setting::where('key', self::BLOCKED_SETTING_KEY)->value('value');
    if (!$value) {
      return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values($decoded) : [];
  }

  /**
   * Remember a manually deleted background so the sync won't bring it back.
   */
  public static function blockFilename(string $filename): void
  {
    $blocked = self::blockedFilenames();
    if (!in_array($filename, $blocked, true)) {
      $blocked[] = $filename;
      Setting::updateOrCreate(
        ['key' => self::BLOCKED_SETTING_KEY],
        ['value' => json_encode(array_values($blocked))]
      );
      Log::info('BestOf background blocked by user', ['file' => $filename]);
    }
  }

  /**
   * Forget one blocked filename, or all of them with $all. Returns how many
   * were unblocked.
   */
  public static function unblockFilenames(?string $filename = null, bool $all = false): int
  {
    $blocked = self::blockedFilenames();
    if ($all) {
      $count = count($blocked);
      $blocked = [];
    } else {
      $before = count($blocked);
      $blocked = array_values(array_filter($blocked, fn($f) => $f !== $filename));
      $count = $before - count($blocked);
    }
    Setting::updateOrCreate(
      ['key' => self::BLOCKED_SETTING_KEY],
      ['value' => json_encode(array_values($blocked))]
    );
    return $count;
  }

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
    $blocked = self::blockedFilenames();
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
        if (in_array($target, $blocked, true)) {
          $skipped++;
          continue; // user manually deleted this one; don't bring it back
        }
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
