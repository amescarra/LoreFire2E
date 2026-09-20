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
        if (is_callable(self::$extractOverride)) {
            $decoded = (self::$extractOverride)($audioPath, $transcriptJson);
            return is_array($decoded) ? $this->speakersFromPayload($decoded) : [];
        }

        if (! $this->isSupported()) {
            return [];
        }

        if (! is_file($audioPath)) {
            return [];
        }

        $python = app(PythonSetupService::class)->venvPythonPath();
        $script = base_path(implode(DIRECTORY_SEPARATOR, ['resources', 'python', 'extract_speaker_embeddings.py']));
        if (! is_file($python) || ! is_file($script)) {
            return [];
        }

        $hfToken = (string) AppSetting::get('huggingface_token', '');
        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lorefire-voiceprint-'.uniqid('', true).'.json';

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
        $process->run();

        if (! $process->isSuccessful()) {
            Log::info('[VoiceprintEmbeddingExtractor] extract failed', [
                'exit' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);
        }

        if (! is_file($output)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($output), true);
        @unlink($output);

        return is_array($decoded) ? $this->speakersFromPayload($decoded) : [];
    }

    /**
     * @return array{speakers: array<string, list<float>>, model: string|null, clips: array<string, string>}
     */
    public function extractDetailed(string $audioPath, ?string $transcriptJson = null, ?string $clipsDir = null): array
    {
        $empty = ['speakers' => [], 'model' => null, 'clips' => []];

        if (is_callable(self::$extractOverride)) {
            $decoded = (self::$extractOverride)($audioPath, $transcriptJson);
            if (! is_array($decoded)) {
                return $empty;
            }

            return [
                'speakers' => $this->speakersFromPayload($decoded),
                'model' => is_string($decoded['model'] ?? null) ? $decoded['model'] : 'test-embedding',
                'clips' => is_array($decoded['clips'] ?? null) ? $decoded['clips'] : [],
            ];
        }

        $speakers = $this->extract($audioPath, $transcriptJson, $clipsDir);

        return [
            'speakers' => $speakers,
            'model' => $speakers === [] ? null : 'pyannote/embedding',
            'clips' => [],
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
            $vector = is_array($value) && isset($value['embedding']) && is_array($value['embedding'])
                ? $value['embedding']
                : $value;
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            $out[(string) $label] = array_values(array_map('floatval', $vector));
        }

        return $out;
    }
}
