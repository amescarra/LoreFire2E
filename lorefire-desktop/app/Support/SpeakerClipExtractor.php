<?php

namespace App\Support;

use App\Models\GameSession;
use App\Services\PythonSetupService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Extract a short WAV montage of one SPEAKER_N from the session recording.
 *
 * WebM/Opus from MediaRecorder does not seek reliably in HTMLAudioElement,
 * so Identify Speakers plays this ffmpeg-cut clip instead of the full file.
 */
class SpeakerClipExtractor
{
    /**
     * Test hook: fn(GameSession $session, string $label): array
     *
     * @var callable|null
     */
    public static $extractOverride = null;

    public static ?string $ffmpegOverride = null;

    public static function resetOverride(): void
    {
        self::$extractOverride = null;
        self::$ffmpegOverride = null;
    }

    /**
     * @return array{path: ?string, windows: list<array{start: float, end: float}>, error: ?string, status: int}
     */
    public function extract(GameSession $session, string $label): array
    {
        if (is_callable(self::$extractOverride)) {
            $result = (self::$extractOverride)($session, $label);

            return $this->normalizeResult($result);
        }

        if (! SpeakerClipWindows::isValidLabel($label)) {
            return $this->fail('Invalid speaker label.', 422);
        }

        if (! $session->audio_path || ! Storage::disk('local')->exists($session->audio_path)) {
            return $this->fail('No audio file found for this session.', 404);
        }

        $segments = app(VoiceprintResolver::class)->loadTranscriptSegments($session);
        if ($segments === []) {
            return $this->fail('No transcript segments found for this session.', 404);
        }

        $windows = SpeakerClipWindows::forLabel($segments, $label);
        if ($windows === []) {
            return $this->fail('No timed speech found for this speaker label.', 404);
        }

        $audioAbs = str_replace('/', DIRECTORY_SEPARATOR, Storage::disk('local')->path($session->audio_path));
        if (! is_file($audioAbs)) {
            return $this->fail('No audio file found for this session.', 404);
        }

        $fingerprint = sha1(json_encode([
            'audio' => $session->audio_path,
            'mtime' => @filemtime($audioAbs) ?: 0,
            'size' => @filesize($audioAbs) ?: 0,
            'windows' => $windows,
        ], JSON_THROW_ON_ERROR));

        $relDir = "sessions/{$session->id}/clips";
        $relPath = "{$relDir}/{$label}-{$fingerprint}.wav";
        Storage::disk('local')->makeDirectory($relDir);

        foreach (Storage::disk('local')->files($relDir) as $file) {
            $base = basename($file);
            if (str_starts_with($base, $label.'-') && $file !== $relPath) {
                Storage::disk('local')->delete($file);
            }
        }

        $destAbs = str_replace('/', DIRECTORY_SEPARATOR, Storage::disk('local')->path($relPath));
        if (is_file($destAbs) && filesize($destAbs) > 64) {
            return [
                'path' => $destAbs,
                'windows' => $windows,
                'error' => null,
                'status' => 200,
            ];
        }

        $cut = $this->cutWithFfmpeg($audioAbs, $destAbs, $windows);
        if (! $cut['ok']) {
            Log::warning('[SpeakerClipExtractor] ffmpeg clip failed', [
                'session' => $session->id,
                'label' => $label,
                'error' => $cut['error'],
            ]);

            return $this->fail($cut['error'] ?? 'Could not extract a speaker clip from this recording.', 500, $windows);
        }

        return [
            'path' => $destAbs,
            'windows' => $windows,
            'error' => null,
            'status' => 200,
        ];
    }

    /**
     * @param  list<array{start: float, end: float}>  $windows
     * @return array{ok: bool, error: ?string}
     */
    public function cutWithFfmpeg(string $source, string $destination, array $windows): array
    {
        if ($windows === [] || ! is_file($source)) {
            return ['ok' => false, 'error' => 'Nothing to extract.'];
        }

        $dir = dirname($destination);
        if ($dir && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $python = $this->pythonBinary();
        $code = <<<'PY'
import os, subprocess, sys

src, dst = sys.argv[1], sys.argv[2]
raw = sys.argv[3:]
windows = [(float(raw[i]), float(raw[i + 1])) for i in range(0, len(raw), 2)]
ffmpeg = os.environ.get("LOREFIRE_FFMPEG") or ""
if not ffmpeg:
    try:
        import imageio_ffmpeg
        ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:
        ffmpeg = "ffmpeg"

filters = []
labels = []
for i, (start, end) in enumerate(windows):
    filters.append(
        f"[0:a]atrim=start={start:.3f}:end={end:.3f},asetpts=PTS-STARTPTS[a{i}]"
    )
    labels.append(f"[a{i}]")

if len(windows) == 1:
    graph = filters[0]
    mapped = "[a0]"
else:
    graph = ";".join(filters) + ";" + "".join(labels) + f"concat=n={len(windows)}:v=0:a=1[out]"
    mapped = "[out]"

cmd = [
    ffmpeg, "-y", "-i", src,
    "-filter_complex", graph,
    "-map", mapped,
    "-ac", "1", "-ar", "16000",
    "-f", "wav", dst,
]
result = subprocess.run(cmd, capture_output=True)
if result.returncode != 0 or not os.path.isfile(dst) or os.path.getsize(dst) < 64:
    err = (result.stderr or b"").decode("utf-8", "replace").strip()
    sys.stderr.write(err[-800:] if err else "empty ffmpeg output")
    sys.exit(1)
PY;

        $args = [$python, '-c', $code, $source, $destination];
        foreach ($windows as $window) {
            $args[] = sprintf('%.3f', $window['start']);
            $args[] = sprintf('%.3f', $window['end']);
        }

        $process = new Process($args);
        $process->setTimeout(60);
        AppTemp::applyToProcess($process);
        if (is_string(self::$ffmpegOverride) && self::$ffmpegOverride !== '') {
            $process->setEnv(array_merge(AppTemp::processEnv(), [
                'LOREFIRE_FFMPEG' => self::$ffmpegOverride,
            ]));
        }
        $process->run();

        if (! $process->isSuccessful() || ! is_file($destination) || filesize($destination) < 64) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());

            return [
                'ok' => false,
                'error' => $err !== '' ? $err : 'ffmpeg could not extract this speaker clip.',
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{path: ?string, windows: list<array{start: float, end: float}>, error: ?string, status: int}
     */
    protected function normalizeResult(array $result): array
    {
        return [
            'path' => isset($result['path']) && is_string($result['path']) ? $result['path'] : null,
            'windows' => is_array($result['windows'] ?? null) ? $result['windows'] : [],
            'error' => isset($result['error']) && is_string($result['error']) ? $result['error'] : null,
            'status' => isset($result['status']) && is_int($result['status']) ? $result['status'] : ($result['path'] ?? null ? 200 : 500),
        ];
    }

    /**
     * @param  list<array{start: float, end: float}>  $windows
     * @return array{path: null, windows: list<array{start: float, end: float}>, error: string, status: int}
     */
    protected function fail(string $error, int $status, array $windows = []): array
    {
        return [
            'path' => null,
            'windows' => $windows,
            'error' => $error,
            'status' => $status,
        ];
    }

    protected function pythonBinary(): string
    {
        $venv = app(PythonSetupService::class)->venvPythonPath();
        if (is_file($venv)) {
            return $venv;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }
}
