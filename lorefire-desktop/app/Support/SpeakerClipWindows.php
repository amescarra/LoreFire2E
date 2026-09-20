<?php

namespace App\Support;

/**
 * Pick a short identification montage for one SPEAKER_N label.
 *
 * Uses the first few speech segments attributed to that label, capped so
 * Identify Speakers plays a representative sample rather than the session.
 */
class SpeakerClipWindows
{
    public const MAX_SEGMENTS = 3;

    public const MAX_SECONDS = 25.0;

    public const MAX_SEGMENT_SECONDS = 12.0;

    public const MIN_SEGMENT_SECONDS = 0.35;

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array{start: float, end: float}>
     */
    public static function forLabel(array $segments, string $label): array
    {
        $windows = [];
        $total = 0.0;

        foreach ($segments as $seg) {
            if (! self::segmentHasLabel($seg, $label)) {
                continue;
            }

            $start = self::numeric($seg['start'] ?? 0);
            $end = self::numeric($seg['end'] ?? $start);
            $duration = $end - $start;
            if ($duration < self::MIN_SEGMENT_SECONDS) {
                continue;
            }
            if (count($windows) >= self::MAX_SEGMENTS) {
                break;
            }

            $remaining = self::MAX_SECONDS - $total;
            if ($remaining < self::MIN_SEGMENT_SECONDS) {
                break;
            }

            $take = min($duration, $remaining, self::MAX_SEGMENT_SECONDS);
            $windows[] = [
                'start' => $start,
                'end' => $start + $take,
            ];
            $total += $take;
        }

        return $windows;
    }

    /**
     * @param  array<string, mixed>  $seg
     */
    public static function segmentHasLabel(array $seg, string $label): bool
    {
        foreach (['speaker_label', 'speaker'] as $key) {
            $value = $seg[$key] ?? null;
            if (is_string($value) && $value === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{start: float, end: float}>
     */
    public static function fromTranscriptJson(string $json, string $label): array
    {
        $decoded = json_decode($json, true);
        $segments = is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [];

        return self::forLabel($segments, $label);
    }

    public static function duration(array $windows): float
    {
        $total = 0.0;
        foreach ($windows as $window) {
            $total += max(0.0, self::numeric($window['end'] ?? 0) - self::numeric($window['start'] ?? 0));
        }

        return $total;
    }

    public static function isValidLabel(string $label): bool
    {
        return (bool) preg_match('/^SPEAKER_\d+$/', $label);
    }

    private static function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
