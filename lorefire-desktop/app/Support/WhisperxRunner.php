<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\PythonSetupService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Runs the existing WhisperX script. Live play reuses this runner on new audio
 * slices; it is not a second recorder.
 */
class WhisperxRunner
{
    /**
     * Live diarization helps campaign voiceprints resolve SPEAKER_N labels.
     * Windows ARM keeps the existing non-diarized live path.
     */
    public function shouldDiarizeLive(
        ?string $osFamily = null,
        ?string $processorArchitecture = null,
        ?string $processorArchitectureW6432 = null
    ): bool {
        if (Linux5090::isWindowsArmPath($osFamily, $processorArchitecture, $processorArchitectureW6432)) {
            return false;
        }

        return (string) AppSetting::get('huggingface_token', '') !== '';
    }

    /**
     * @return array{segments: array<int, array<string, mixed>>, language: string}|null
     */
    public function transcribe(string $audioPath, string $outputJson, bool $diarize = false): ?array
    {
        $python = app(PythonSetupService::class)->venvPythonPath();
        $script = base_path(implode(DIRECTORY_SEPARATOR, ['resources', 'python', 'run_whisperx.py']));

        if (! is_file($audioPath) || ! is_file($script) || ! is_file($python)) {
            Log::warning('[WhisperxRunner] missing audio, script, or python', compact('audioPath', 'script', 'python'));

            return null;
        }

        $dir = dirname($outputJson);
        if ($dir && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $model = \App\Support\WhisperxLanguages::coerceModel(
            (string) AppSetting::get('whisperx_model', 'base')
        );
        $hfToken = AppSetting::get('huggingface_token', '');

        $cmd = \App\Support\WhisperxLanguages::command(
            $python,
            $script,
            $audioPath,
            $outputJson,
            $diarize,
            (string) $hfToken,
            $model,
        );

        AppTemp::sweep();
        $process = new Process($cmd);
        $process->setTimeout(600);
        AppTemp::applyToProcess($process);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('[WhisperxRunner] WhisperX failed', [
                'exit' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            return null;
        }

        if (! is_file($outputJson)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($outputJson), true);
        if (! is_array($decoded)) {
            return null;
        }

        return [
            'segments' => is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [],
            'language' => (string) ($decoded['language'] ?? \App\Support\WhisperxLanguages::csv()),
        ];
    }

    public function sliceAudio(string $source, string $destination, float $startSeconds): bool
    {
        $python = app(PythonSetupService::class)->venvPythonPath();
        if (! is_file($python) || ! is_file($source)) {
            return false;
        }

        $dir = dirname($destination);
        if ($dir && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $code = <<<'PY'
import subprocess, sys
src, dst, start = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    import imageio_ffmpeg
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
except Exception:
    ffmpeg = "ffmpeg"
cmd = [ffmpeg, "-y", "-ss", start, "-i", src, "-ac", "1", "-ar", "16000", dst]
r = subprocess.run(cmd, capture_output=True)
if r.returncode != 0:
    sys.stderr.write(r.stderr.decode("utf-8", "replace"))
sys.exit(r.returncode)
PY;

        $process = new Process([$python, '-c', $code, $source, $destination, sprintf('%.3f', max(0, $startSeconds))]);
        $process->setTimeout(60);
        AppTemp::applyToProcess($process);
        $process->run();

        return $process->isSuccessful() && is_file($destination) && filesize($destination) > 64;
    }

    public function durationSeconds(string $path): ?float
    {
        $python = app(PythonSetupService::class)->venvPythonPath();
        if (! is_file($python) || ! is_file($path)) {
            return null;
        }

        $code = <<<'PY'
import re, subprocess, sys
src = sys.argv[1]
try:
    import imageio_ffmpeg
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
except Exception:
    ffmpeg = "ffmpeg"
r = subprocess.run([ffmpeg, "-i", src], capture_output=True, text=True)
blob = (r.stderr or "") + (r.stdout or "")
m = re.search(r"Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)", blob)
if not m:
    sys.exit(1)
h, mi, s = int(m.group(1)), int(m.group(2)), float(m.group(3))
print(h * 3600 + mi * 60 + s)
PY;

        $process = new Process([$python, '-c', $code, $path]);
        $process->setTimeout(30);
        AppTemp::applyToProcess($process);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }
        $out = trim($process->getOutput());

        return is_numeric($out) ? (float) $out : null;
    }

    public function appendFile(string $source, string $destination): bool
    {
        if (! is_file($source)) {
            return false;
        }
        $dir = dirname($destination);
        if ($dir && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $out = fopen($destination, is_file($destination) ? 'ab' : 'wb');
        if ($out === false) {
            return false;
        }
        $in = fopen($source, 'rb');
        if ($in === false) {
            fclose($out);

            return false;
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return true;
    }
}
