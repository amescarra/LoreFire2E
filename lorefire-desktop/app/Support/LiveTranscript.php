<?php

namespace App\Support;

use App\Models\GameSession;
use Illuminate\Support\Facades\Storage;

/**
 * Incremental live transcript for a session that is still recording.
 * WhisperX output from each new audio slice is appended here; the extractor
 * only reads segments after game_sessions.sheet_update_cursor.
 */
class LiveTranscript
{
    public static function relativeDir(GameSession $session): string
    {
        return "sessions/{$session->id}/live";
    }

    public static function relativePath(GameSession $session): string
    {
        return self::relativeDir($session).'/transcript.json';
    }

    public static function partialAudioPath(GameSession $session): string
    {
        return self::relativeDir($session).'/partial.audio';
    }

    public static function reset(GameSession $session): void
    {
        Storage::disk('local')->deleteDirectory(self::relativeDir($session));

        $session->update([
            'sheet_update_cursor' => 0,
            'sheet_update_hashes' => [],
            'live_chunk_index' => -1,
            'live_audio_seconds' => 0,
        ]);
    }

    /**
     * @return array{segments: array<int, array<string, mixed>>}
     */
    public static function load(GameSession $session): array
    {
        $path = self::relativePath($session);
        if (! Storage::disk('local')->exists($path)) {
            return ['segments' => []];
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);

        return [
            'segments' => is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    public static function appendSegments(GameSession $session, array $segments): void
    {
        $current = self::load($session);
        $nextId = count($current['segments']);

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $current['segments'][] = [
                'id' => $nextId++,
                'text' => $text,
                'start' => (float) ($segment['start'] ?? 0),
                'end' => (float) ($segment['end'] ?? 0),
                'speaker' => $segment['speaker'] ?? null,
                'speaker_label' => $segment['speaker_label'] ?? $segment['speaker'] ?? null,
                'speaker_is_dm' => $segment['speaker_is_dm'] ?? false,
                'voiceprint_id' => $segment['voiceprint_id'] ?? null,
                'match_confidence' => $segment['match_confidence'] ?? null,
                'chunk_index' => $segment['chunk_index'] ?? null,
            ];
        }

        Storage::disk('local')->makeDirectory(self::relativeDir($session));
        Storage::disk('local')->put(
            self::relativePath($session),
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function unprocessedSegments(GameSession $session): array
    {
        $cursor = (int) ($session->sheet_update_cursor ?? 0);
        $segments = self::load($session)['segments'];

        return array_values(array_slice($segments, $cursor));
    }
}
