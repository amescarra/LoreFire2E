<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Ubuntu x86_64 + NVIDIA CUDA profile (RTX 5090 / 32GB VRAM).
 *
 * Windows ARM / Surface Snapdragon is handled by WindowsArm64Php and must
 * never take this path. Detection is Linux + x86_64 + a working nvidia-smi.
 */
class Linux5090
{
    public const WHISPER_MODEL = 'large-v3';

    public const WHISPER_COMPUTE = 'float16';

    public const OLLAMA_MODEL = 'qwen2.5:32b';

    public const OLLAMA_URL = 'http://localhost:11434';

    public const OLLAMA_NUM_CTX = 8192;

    public const OLLAMA_NUM_BATCH = 512;

    /** @var callable|null Override for tests: fn(): bool */
    public static $cudaProbe = null;

    /** @var callable|null Override for tests: fn(): ?int VRAM in MiB */
    public static $vramProbe = null;

    /** @var callable|null Override for tests: fn(): bool python3.12 / bundled 3.12 present */
    public static $python312Probe = null;

    public const DEADSNAKES_INSTALL = 'sudo add-apt-repository -y ppa:deadsnakes/ppa && sudo apt update && sudo apt install -y python3.12 python3.12-venv python3.12-dev';

    public static function isLinuxX86(?string $osFamily = null, ?string $machine = null): bool
    {
        $osFamily ??= PHP_OS_FAMILY;
        $machine = strtolower($machine ?? php_uname('m'));

        return $osFamily === 'Linux' && in_array($machine, ['x86_64', 'amd64'], true);
    }

    /**
     * Never true on Windows ARM. Kept explicit so the two platform profiles
     * cannot be confused in tests or conditionals.
     */
    public static function isWindowsArmPath(
        ?string $osFamily = null,
        ?string $processorArchitecture = null,
        ?string $processorArchitectureW6432 = null
    ): bool {
        return WindowsArm64Php::osIsWindowsArm64(
            $processorArchitecture,
            $processorArchitectureW6432,
            $osFamily
        );
    }

    public static function nvidiaSmiAvailable(): bool
    {
        $override = getenv('LOREFIRE_LINUX5090_CUDA');
        if ($override === '1' || $override === 'true') {
            return true;
        }
        if ($override === '0' || $override === 'false') {
            return false;
        }
        if (is_callable(self::$cudaProbe)) {
            return (bool) (self::$cudaProbe)();
        }
        if (! self::isLinuxX86() || self::isWindowsArmPath()) {
            return false;
        }

        $smi = trim((string) shell_exec('command -v nvidia-smi 2>/dev/null'));
        if ($smi === '') {
            return false;
        }

        $out = [];
        $code = 1;
        exec(escapeshellarg($smi).' -L 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [];
    }

    /**
     * CUDA-first stack: Linux x86_64 with a live NVIDIA driver.
     * CPU fallback when the driver / toolkit is missing.
     */
    public static function shouldUseCudaStack(): bool
    {
        return self::isLinuxX86() && ! self::isWindowsArmPath() && self::nvidiaSmiAvailable();
    }

    public static function vramMiB(): ?int
    {
        $override = getenv('LOREFIRE_LINUX5090_VRAM_MB');
        if (is_string($override) && $override !== '' && is_numeric($override)) {
            return (int) $override;
        }
        if (is_callable(self::$vramProbe)) {
            $value = (self::$vramProbe)();

            return is_numeric($value) ? (int) $value : null;
        }
        if (! self::shouldUseCudaStack()) {
            return null;
        }

        $out = [];
        $code = 1;
        exec('nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits 2>/dev/null', $out, $code);
        if ($code !== 0 || $out === []) {
            return null;
        }

        return (int) trim((string) $out[0]);
    }

    /**
     * 32GB Blackwell (5090) uses batch 32. Smaller cards stay conservative.
     */
    public static function whisperBatchSize(): int
    {
        $mb = self::vramMiB();
        if ($mb !== null && $mb >= 28000) {
            return 32;
        }
        if ($mb !== null && $mb >= 16000) {
            return 24;
        }

        return 16;
    }

    public static function whisperModelDefault(): string
    {
        $mb = self::vramMiB();
        if ($mb !== null && $mb >= 16000) {
            return self::WHISPER_MODEL;
        }

        return 'base';
    }

    public static function ollamaModelDefault(): string
    {
        $mb = self::vramMiB();
        if ($mb !== null && $mb >= 28000) {
            return self::OLLAMA_MODEL;
        }
        if ($mb !== null && $mb >= 16000) {
            return 'qwen2.5:14b';
        }

        return 'llama3.1:8b';
    }

    /**
     * Extra run_whisperx.py flags. Empty on ARM / Windows / CPU Linux.
     *
     * @return list<string>
     */
    public static function whisperxCliArgs(): array
    {
        if (! self::shouldUseCudaStack()) {
            return [];
        }

        return [
            '--device', 'cuda',
            '--compute-type', self::WHISPER_COMPUTE,
            '--batch-size', (string) self::whisperBatchSize(),
        ];
    }

    /**
     * Ollama /api/chat and /api/generate options for 32GB VRAM.
     * Empty on other platforms so ARM/Windows payloads stay unchanged.
     *
     * @return array<string, int>
     */
    public static function ollamaOptions(): array
    {
        if (! self::shouldUseCudaStack()) {
            return [];
        }

        $mb = self::vramMiB();
        $ctx = ($mb !== null && $mb >= 16000) ? self::OLLAMA_NUM_CTX : 4096;

        return [
            'num_ctx' => $ctx,
            'num_batch' => self::OLLAMA_NUM_BATCH,
            'num_gpu' => 99,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withOllamaOptions(array $payload): array
    {
        $opts = self::ollamaOptions();
        if ($opts === []) {
            return $payload;
        }

        $payload['options'] = array_merge($payload['options'] ?? [], $opts);

        return $payload;
    }

    /**
     * Seed 5090 defaults only when the keys have never been stored.
     * Does not overwrite Settings the user already chose.
     */
    public static function applyRuntimeDefaults(): void
    {
        if (! self::shouldUseCudaStack()) {
            return;
        }

        if (self::missing('whisperx_model')) {
            AppSetting::set('whisperx_model', self::whisperModelDefault());
        }
        if (self::missing('llm_provider')) {
            AppSetting::set('llm_provider', 'ollama');
        }
        if (self::missing('ollama_model')) {
            AppSetting::set('ollama_model', self::ollamaModelDefault());
        }
        if (self::missing('ollama_base_url')) {
            AppSetting::set('ollama_base_url', self::OLLAMA_URL);
        }
    }

    /**
     * whisperx 3.2 pins ctranslate2==4.4.0, which has no Python 3.14 wheel.
     * Linux 3.13+ hosts must use python3.12 (deadsnakes on Resolute).
     */
    public static function whisperxNeedsCpython312(): bool
    {
        return self::isLinuxX86() && ! self::isWindowsArmPath();
    }

    public static function hasWhisperxCpython312(): bool
    {
        if (is_callable(self::$python312Probe)) {
            return (bool) (self::$python312Probe)();
        }

        $bundled = base_path(implode(DIRECTORY_SEPARATOR, ['resources', 'python', 'runtime', 'bin', 'python3']));
        if (is_file($bundled) && is_executable($bundled)) {
            $out = [];
            $code = 1;
            exec(escapeshellarg($bundled).' -c "import sys; print(sys.version_info[:2])" 2>/dev/null', $out, $code);
            if ($code === 0 && isset($out[0]) && preg_match('/\(3,\s*12\)/', $out[0])) {
                return true;
            }
        }

        $smi = trim((string) shell_exec('command -v python3.12 2>/dev/null'));

        return $smi !== '';
    }

    public static function missingPython312Message(): string
    {
        return 'WhisperX on Linux needs Python 3.12. System python3.14 cannot install ctranslate2==4.4.0 (whisperx 3.2). Ubuntu Resolute archives have no python3.12 — install via deadsnakes: '.self::DEADSNAKES_INSTALL.'. Then: rm -rf resources/python/venv && php artisan python:setup --gpu';
    }

    public static function resetProbes(): void
    {
        self::$cudaProbe = null;
        self::$vramProbe = null;
        self::$python312Probe = null;
    }

    private static function missing(string $key): bool
    {
        return AppSetting::where('key', $key)->doesntExist();
    }
}
