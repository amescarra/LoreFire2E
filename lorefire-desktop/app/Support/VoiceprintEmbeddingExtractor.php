<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\GameSession;
use App\Services\PythonSetupService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Extract per-speaker embeddings via the existing WhisperX / pyannote stack.
 *
 * Windows ARM never runs this process — pyannote diarization is a Linux
 * (and optional GPU) path. Callers must tolerate an empty result.
 */
class VoiceprintEmbeddingExtractor
{
    /**
     * Test hook: fn(string $audio, ?string $transcript): array
     *
     * @var callable|null
     */
    public static $extractOverride = null;

    /** @var bool|null */
    public static $supportedOverride = null;

    public static function resetOverride(): void
    {
        self::$extractOverride = null;
        self::$supportedOverride = null;
    }

    public function isSupported(
        ?string $osFamily = null,
        ?string $processorArchitecture = null,
        ?string $processorArchitectureW6432 = null
    ): bool {
        if (self::$supportedOverride !== null) {
            return (bool) self::$supportedOverride;
        }

        if (Linux5090::isWindowsArmPath($osFamily, $processorArchitecture, $processorArchitectureW6432)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, list<float>>
     */
    public function extractForSession(GameSession $session): array
    {
        if (! $session->audio_path || ! $session->transcript_path) {
            return [];
        }

        $audio = Storage::disk('local')->path($session->audio_path);
        $transcript = Storage::disk('local')->path($session->transcript_path);

        return $this->extract($audio, $transcript);
    }

    /**
     * @return array<string, list<float>>
     */
    public function extract(string $audioPath, ?string $transcriptJson = null, ?string $clipsDir = null): array
    {
        return $this->extractDetailed($audioPath, $transcriptJson, $clipsDir)['speakers'];
    }

    /**
     * @return array{speakers: array<string, list<float>>, model: string|null, clips: array<string, string>, error: string|null, skipped: bool}
     */
    public function extractDetailed(string $audioPath, ?string $transcriptJson = null, ?string $clipsDir = null): array
    {
        $empty = ['speakers' => [], 'model' => null, 'clips' => [], 'error' => null, 'skipped' => false];

        if (! $this->isSupported()) {
            return array_merge($empty, [
                'skipped' => true,
                'error' => 'Embedding extraction is skipped on Windows ARM — auto-label needs Linux WhisperX + pyannote.',
            ]);
        }

        if (is_callable(self::$extractOverride)) {
            $decoded = (self::$extractOverride)($audioPath, $transcriptJson);
            if (! is_array($decoded)) {
                return array_merge($empty, ['error' => 'Embedding extract override returned no payload.']);
            }

            $speakers = $this->speakersFromPayload($decoded);
            $error = is_string($decoded['error'] ?? null) && $decoded['error'] !== ''
                ? $decoded['error']
                : null;
            if ($speakers === [] && $error === null) {
                $error = 'No speaker embedding was produced from this recording.';
            }

            return [
                'speakers' => $speakers,
                'model' => is_string($decoded['model'] ?? null) ? $decoded['model'] : ($speakers === [] ? null : 'test-embedding'),
                'clips' => is_array($decoded['clips'] ?? null) ? $decoded['clips'] : [],
                'error' => $error,
                'skipped' => (bool) ($decoded['skipped'] ?? false),
            ];
        }

        if (! is_file($audioPath)) {
            return array_merge($empty, ['error' => 'Enrollment audio was not found on disk after upload.']);
        }

        $python = app(PythonSetupService::class)->venvPythonPath();
        $script = base_path(implode(DIRECTORY_SEPARATOR, ['resources', 'python', 'extract_speaker_embeddings.py']));
        if (! is_file($python)) {
            return array_merge($empty, ['error' => 'WhisperX Python venv is not installed. Finish onboarding, then try Record again.']);
        }
        if (! is_file($script)) {
            return array_merge($empty, ['error' => 'Speaker embedding script is missing.']);
        }

        $hfToken = (string) AppSetting::get('huggingface_token', '');
        $output = AppTemp::root().DIRECTORY_SEPARATOR.'lorefire-voiceprint-'.uniqid('', true).'.json';

        $cmd = [$python, $script, '--audio', $audioPath, '--output', $output];
        if (is_string($transcriptJson) && is_file($transcriptJson)) {
            $cmd[] = '--transcript';
            $cmd[] = $transcriptJson;
        }
        if (is_string($clipsDir) && $clipsDir !== '') {
            if (! is_dir($clipsDir)) {
                mkdir($clipsDir, 0755, true);
            }
            $cmd[] = '--clips-dir';
            $cmd[] = $clipsDir;
        }
        if ($hfToken !== '') {
            $cmd[] = '--hf-token';
            $cmd[] = $hfToken;
        }

        $process = new Process($cmd);
        $process->setTimeout(300);
        AppTemp::applyToProcess($process);
        $stderr = '';
        $decoded = [];
        try {
            $process->run();

            $stderr = trim($process->getErrorOutput());
            if (! $process->isSuccessful()) {
                Log::info('[VoiceprintEmbeddingExtractor] extract failed', [
                    'exit' => $process->getExitCode(),
                    'stderr' => $stderr,
                ]);
            }

            $decoded = [];
            if (is_file($output)) {
                $decoded = json_decode((string) file_get_contents($output), true);
            }
        } finally {
            if (is_file($output)) {
                @unlink($output);
            }
        }

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $speakers = $this->speakersFromPayload($decoded);
        $error = is_string($decoded['error'] ?? null) && $decoded['error'] !== ''
            ? $decoded['error']
            : null;

        if ($speakers === [] && $error === null) {
            if ($hfToken === '') {
                $error = 'No speaker embedding was produced. Add a Hugging Face token in Settings (same token as WhisperX diarization) and try again.';
            } elseif ($stderr !== '') {
                $error = $this->truncateError($stderr);
            } else {
                $error = 'No speaker embedding was produced from this recording. Check the Hugging Face token and try a longer take.';
            }
        } elseif ($speakers === [] && $hfToken === '' && ! str_contains(strtolower((string) $error), 'hugging face')) {
            $error = $this->truncateError((string) $error).' Add a Hugging Face token in Settings (same token as WhisperX diarization).';
        }

        return [
            'speakers' => $speakers,
            'model' => is_string($decoded['model'] ?? null) && $decoded['model'] !== ''
                ? $decoded['model']
                : ($speakers === [] ? null : 'pyannote/embedding'),
            'clips' => is_array($decoded['clips'] ?? null) ? $decoded['clips'] : [],
            'error' => $speakers === [] ? $error : null,
            'skipped' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<float>>
     */
    protected function speakersFromPayload(array $payload): array
    {
        $raw = $payload['speakers'] ?? $payload;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $label => $value) {
            if (in_array($label, ['model', 'error', 'clips', 'skipped'], true) && ! isset($payload['speakers'])) {
                continue;
            }
            $vector = is_array($value) && isset($value['embedding']) && is_array($value['embedding'])
                ? $value['embedding']
                : $value;
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            // Ignore non-numeric payloads such as {"error": "..."}.
            $numeric = array_values(array_filter($vector, 'is_numeric'));
            if ($numeric === [] || count($numeric) !== count($vector)) {
                continue;
            }
            $out[(string) $label] = array_values(array_map('floatval', $numeric));
        }

        return $out;
    }

    protected function truncateError(string $error): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);

        return mb_substr($error, 0, 500);
    }
}
