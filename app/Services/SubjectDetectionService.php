<?php

namespace App\Services;

use App\Models\BackgroundFocalPoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Detects the subject focal point of background images (YuNet face
 * detection via scripts/detect_focal_point.py) and persists it so the
 * frontend can smart-crop with `background-position` instead of always
 * center-cropping.
 *
 * The detector also finds solid black film borders on lab scans. When found,
 * a border-cropped display copy is written to cleaned/ on the backgrounds
 * disk and the slideshow shows that copy instead; the original file is never
 * modified. Focal coordinates are always relative to the displayed image.
 */
class SubjectDetectionService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Extensions the detector can write cropped copies of (cv2.imwrite has
     * no GIF encoder; GIF backgrounds simply skip border removal).
     */
    private const CROPPABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const CLEANED_DIR = 'cleaned';

    /**
     * Detect and store the focal point (and any black borders) for a
     * background file. Safe to call for any file: videos and undetectable
     * images simply store NULL (frontend falls back to centered crop).
     */
    public function detectFor(string $filename): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return; // videos etc: nothing to detect
        }

        $disk = Storage::disk('backgrounds');
        $absolutePath = $disk->path($filename);
        if (!is_file($absolutePath)) {
            return;
        }

        // Ask the detector to write a border-cropped copy when it finds
        // black film borders. The copy lives in cleaned/ (a subdirectory,
        // so the non-recursive backgrounds listing never picks it up).
        $cleanedRelative = self::CLEANED_DIR . '/' . $filename;
        $cropOutput = null;
        if (in_array($ext, self::CROPPABLE_EXTENSIONS, true)) {
            $cropOutput = $disk->path($cleanedRelative);
            $cropDir = dirname($cropOutput);
            if (!is_dir($cropDir)) {
                mkdir($cropDir, 0755, true);
            }
        }

        $script = base_path('scripts/detect_focal_point.py');
        $result = $this->runDetection($script, $absolutePath, $cropOutput);

        $borders = $result['borders'] ?? null;
        if ($cropOutput !== null && is_file($cropOutput)) {
            $cleanedFilename = $cleanedRelative;
        } else {
            // No borders (or crop failed): drop any stale cleaned copy from
            // a previous detection, e.g. after a re-upload without borders.
            if ($disk->exists($cleanedRelative)) {
                $disk->delete($cleanedRelative);
            }
            $cleanedFilename = null;
            $borders = null;
        }

        BackgroundFocalPoint::updateOrCreate(
            ['filename' => $filename],
            [
                'focal_x' => $result['x'] ?? null,
                'focal_y' => $result['y'] ?? null,
                'border_top' => $borders['top'] ?? null,
                'border_right' => $borders['right'] ?? null,
                'border_bottom' => $borders['bottom'] ?? null,
                'border_left' => $borders['left'] ?? null,
                'cleaned_filename' => $cleanedFilename,
                'detected_at' => now(),
            ]
        );

        Log::info('Background subject detection complete', [
            'file' => $filename,
            'focal' => [$result['x'] ?? null, $result['y'] ?? null],
            'borders' => $borders,
            'cleaned' => $cleanedFilename,
        ]);
    }

    public function forgetFor(string $filename): void
    {
        Storage::disk('backgrounds')->delete(self::CLEANED_DIR . '/' . $filename);
        BackgroundFocalPoint::where('filename', $filename)->delete();
    }

    /**
     * Map of rawurlencoded basename => ['x' => float, 'y' => float] for
     * backgrounds that have a detected subject. Matches the keys the
     * /api/backgrounds endpoint already returns. Coordinates are relative
     * to the displayed image (border-cropped copy when one exists).
     */
    public function focalPointsFor(array $encodedBasenames): array
    {
        // The API lists rawurlencoded basenames; rows are stored raw, so
        // decode for the lookup and re-key by the encoded form.
        $decoded = array_map('rawurldecode', $encodedBasenames);
        $rows = BackgroundFocalPoint::whereIn('filename', $decoded)
            ->whereNotNull('focal_x')
            ->whereNotNull('focal_y')
            ->get(['filename', 'focal_x', 'focal_y']);

        $map = [];
        foreach ($rows as $row) {
            $map[rawurlencode($row->filename)] = [
                'x' => round($row->focal_x * 100, 2),
                'y' => round($row->focal_y * 100, 2),
            ];
        }
        return $map;
    }

    /**
     * Map of rawurlencoded original basename => rawurlencoded display path.
     * The display path is the border-cropped copy (cleaned/...) when black
     * film borders were detected, otherwise the original file itself. The
     * slideshow should render the display path.
     */
    public function displayFilesFor(array $encodedBasenames): array
    {
        $decoded = array_map('rawurldecode', $encodedBasenames);
        $rows = BackgroundFocalPoint::whereIn('filename', $decoded)
            ->get(['filename', 'cleaned_filename'])
            ->keyBy('filename');

        $map = [];
        foreach ($encodedBasenames as $index => $encoded) {
            $row = $rows->get($decoded[$index]);
            $display = ($row && $row->cleaned_filename)
                ? $row->cleaned_filename
                : $decoded[$index];
            // Encode per path segment so the cleaned/ separator survives.
            $map[$encoded] = implode(
                '/',
                array_map('rawurlencode', explode('/', $display))
            );
        }
        return $map;
    }

    private function runDetection(string $script, string $imagePath, ?string $cropOutput): array
    {
        $command = ['python3', $script, $imagePath];
        if ($cropOutput !== null) {
            $command[] = '--crop-output';
            $command[] = $cropOutput;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            Log::warning('Subject detection: could not start python3', ['script' => $script]);
            return ['x' => null, 'y' => null];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            Log::warning('Subject detection: unreadable output', ['file' => $imagePath]);
            return ['x' => null, 'y' => null];
        }
        return $decoded;
    }
}
