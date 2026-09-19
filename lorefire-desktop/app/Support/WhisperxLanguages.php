<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * WhisperX language allowlist and multilingual model names.
 * This table speaks English and Spanish; English-only *.en checkpoints are rejected.
 */
class WhisperxLanguages
{
    public const DEFAULT = ['en', 'es'];

    /** @var list<string> */
    public const ALLOWED = ['en', 'es'];

    /** @var list<string> */
    public const MULTILINGUAL_MODELS = ['tiny', 'base', 'small', 'medium', 'large-v2', 'large-v3'];

    /**
     * @return list<string>
     */
    public static function allowlist(): array
    {
        $stored = AppSetting::get('whisperx_languages');
        if (is_string($stored) && trim($stored) !== '') {
            return self::parse($stored);
        }

        $legacy = AppSetting::get('whisperx_language');
        if (is_string($legacy) && trim($legacy) !== '') {
            $legacy = strtolower(trim($legacy));
            if ($legacy === 'es') {
                return ['es'];
            }
            // Old default was a forced "en" (and "auto" was unbounded). Both become en,es.
            return self::DEFAULT;
        }

        return self::DEFAULT;
    }

    /**
     * @return list<string>
     */
    public static function parse(?string $csv): array
    {
        $parts = preg_split('/[,\s]+/', strtolower((string) $csv)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (in_array($part, self::ALLOWED, true) && ! in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out === [] ? self::DEFAULT : $out;
    }

    public static function csv(?array $allowlist = null): string
    {
        return implode(',', $allowlist ?? self::allowlist());
    }

    public static function coerceModel(?string $name): string
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return 'base';
        }
        if (str_ends_with($name, '.en')) {
            $name = substr($name, 0, -3);
        }
        if (in_array($name, ['large', 'large-v1'], true)) {
            $name = 'large-v3';
        }
        if (! in_array($name, self::MULTILINGUAL_MODELS, true)) {
            return 'base';
        }

        return $name;
    }

    public static function isEnglishOnlyModel(?string $name): bool
    {
        return str_ends_with(strtolower(trim((string) $name)), '.en');
    }

    /**
     * CLI args for run_whisperx.py. Never a hardcoded --language en.
     *
     * @return list<string>
     */
    public static function scriptLanguageArgs(?array $allowlist = null): array
    {
        return ['--languages', self::csv($allowlist)];
    }

    /**
     * @return list<string>
     */
    public static function command(
        string $python,
        string $script,
        string $audio,
        string $output,
        bool $diarize = false,
        string $hfToken = '',
        ?string $model = null,
        ?array $allowlist = null,
    ): array {
        $cmd = [
            $python,
            $script,
            '--audio', $audio,
            '--output', $output,
            '--model', $model ?? self::coerceModel((string) AppSetting::get('whisperx_model', 'base')),
            ...self::scriptLanguageArgs($allowlist ?? self::allowlist()),
        ];

        if ($diarize && $hfToken !== '') {
            $cmd[] = '--diarize';
            $cmd[] = '--hf-token';
            $cmd[] = $hfToken;
        }

        foreach (Linux5090::whisperxCliArgs() as $arg) {
            $cmd[] = $arg;
        }

        return $cmd;
    }
}
