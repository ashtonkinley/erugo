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
 */
class SubjectDetectionService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Detect and store the focal point for a background file.
     * Safe to call for any file: videos and undetectable images simply
     * store NULL (frontend falls back to centered crop).
     */
    public function detectFor(string $filename): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return; // videos etc: nothing to detect
        }

        $absolutePath = Storage::disk('backgrounds')->path($filename);
        if (!is_file($absolutePath)) {
            return;
        }

        $script = base_path('scripts/detect_focal_point.py');
        $result = $this->runDetection($script, $absolutePath);

        BackgroundFocalPoint::updateOrCreate(
            ['filename' => $filename],
            [
                'focal_x' => $result['x'] ?? null,
                'focal_y' => $result['y'] ?? null,
                'detected_at' => now(),
            ]
        );

        Log::info('Background subject detection complete', [
            'file' => $filename,
            'focal' => [$result['x'] ?? null, $result['y'] ?? null],
        ]);
    }

    public function forgetFor(string $filename): void
    {
        BackgroundFocalPoint::where('filename', $filename)->delete();
    }

    /**
     * Map of rawurlencoded basename => ['x' => float, 'y' => float] for
     * backgrounds that have a detected subject. Matches the keys the
     * /api/backgrounds endpoint already returns.
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

    private function runDetection(string $script, string $imagePath): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['python3', $script, $imagePath],
            $descriptors,
            $pipes,
            null,
            null,
            ['timeout' => 120]
        );
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
