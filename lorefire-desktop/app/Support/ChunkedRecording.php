<?php

namespace App\Support;

use App\Models\GameSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Chunked live-session audio on disk.
 *
 * init() used to mint a new UUID folder and leave prior takes orphaned under
 * sessions/{id}/chunks/. This store can list, assemble, archive, and recover
 * those folders after a restart without shell work.
 */
class ChunkedRecording
{
    public static function chunkRoot(GameSession $session): string
    {
        return "sessions/{$session->id}/chunks";
    }

    public static function recoveredDir(GameSession $session): string
    {
        return "sessions/{$session->id}/recovered";
    }

    public static function chunkDir(GameSession $session, string $uploadId): string
    {
        return self::chunkRoot($session).'/'.$uploadId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listTakes(GameSession $session): array
    {
        $takes = [];

        $chunkRoot = self::chunkRoot($session);
        if (Storage::disk('local')->exists($chunkRoot)) {
            foreach (Storage::disk('local')->directories($chunkRoot) as $dir) {
                $uploadId = basename($dir);
                $files = self::partFiles($session, $uploadId);
                if ($files === []) {
                    continue;
                }
                $bytes = 0;
                $mtime = 0;
                foreach ($files as $rel) {
                    $abs = Storage::disk('local')->path($rel);
                    $bytes += is_file($abs) ? (int) filesize($abs) : 0;
                    $mtime = max($mtime, is_file($abs) ? (int) filemtime($abs) : 0);
                }
                $takes[] = [
                    'kind' => 'chunks',
                    'upload_id' => $uploadId,
                    'path' => $dir,
                    'parts' => count($files),
                    'bytes' => $bytes,
                    'estimated_seconds' => count($files) * 10,
                    'modified_at' => $mtime > 0 ? date('c', $mtime) : null,
                    'label' => 'Recoverable take',
                ];
            }
        }

        $recovered = self::recoveredDir($session);
        if (Storage::disk('local')->exists($recovered)) {
            foreach (Storage::disk('local')->files($recovered) as $rel) {
                $abs = Storage::disk('local')->path($rel);
                if (! is_file($abs)) {
                    continue;
                }
                $takes[] = [
                    'kind' => 'file',
                    'upload_id' => null,
                    'path' => $rel,
                    'parts' => null,
                    'bytes' => (int) filesize($abs),
                    'estimated_seconds' => null,
                    'modified_at' => date('c', (int) filemtime($abs)),
                    'label' => 'Recovered take',
                ];
            }
        }

        usort($takes, function (array $a, array $b) {
            return strcmp((string) ($b['modified_at'] ?? ''), (string) ($a['modified_at'] ?? ''));
        });

        return $takes;
    }

    /**
     * @return list<string>
     */
    public static function partFiles(GameSession $session, string $uploadId): array
    {
        $dir = self::chunkDir($session, $uploadId);
        if (! Storage::disk('local')->exists($dir)) {
            return [];
        }
        $files = array_values(array_filter(
            Storage::disk('local')->files($dir),
            fn (string $path) => str_ends_with($path, '.part')
        ));
        sort($files);

        return $files;
    }

    /**
     * @return array{ok: bool, path?: string, error?: string}
     */
    public static function storeUploadedChunk(GameSession $session, string $uploadId, int $index, UploadedFile $file): array
    {
        AppTemp::sweep();

        $padded = str_pad((string) $index, 6, '0', STR_PAD_LEFT);
        $dir = self::chunkDir($session, $uploadId);
        $name = $padded.'.part';
        $rel = $dir.'/'.$name;

        try {
            Storage::disk('local')->makeDirectory($dir);
            $stored = $file->storeAs($dir, $name, 'local');
        } catch (\Throwable $e) {
            Log::warning('[ChunkedRecording] chunk write failed', [
                'session' => $session->id,
                'upload_id' => $uploadId,
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => 'Could not write audio chunk to disk (disk/tmp/quota). '.$e->getMessage(),
            ];
        }

        if ($stored === false || ! Storage::disk('local')->exists($rel)) {
            return [
                'ok' => false,
                'error' => 'Could not write audio chunk to disk. Check free space (including /tmp).',
            ];
        }

        return ['ok' => true, 'path' => $rel];
    }

    /**
     * Concatenate .part files. Does not require total_chunks to match —
     * leftover UUID folders after a restart are still recoverable.
     *
     * @return array{ok: bool, path?: string, parts?: int, error?: string}
     */
    public static function assemble(
        GameSession $session,
        string $uploadId,
        string $extension = 'webm',
        ?string $destRel = null
    ): array {
        $files = self::partFiles($session, $uploadId);
        if ($files === []) {
            return ['ok' => false, 'error' => 'No chunks found for this take.'];
        }

        $destRel ??= "sessions/{$session->id}/audio.{$extension}";
        $absPath = str_replace('/', DIRECTORY_SEPARATOR, Storage::disk('local')->path($destRel));
        if (! is_dir(dirname($absPath))) {
            mkdir(dirname($absPath), 0755, true);
        }

        $out = fopen($absPath, 'wb');
        if ($out === false) {
            return ['ok' => false, 'error' => 'Could not open output file.'];
        }

        foreach ($files as $relPath) {
            $absChunk = str_replace('/', DIRECTORY_SEPARATOR, Storage::disk('local')->path($relPath));
            $in = @fopen($absChunk, 'rb');
            if ($in) {
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        }
        fclose($out);

        if (! is_file($absPath) || filesize($absPath) === 0) {
            return ['ok' => false, 'error' => 'Assembled audio file is empty.'];
        }

        return [
            'ok' => true,
            'path' => $destRel,
            'parts' => count($files),
        ];
    }

    /**
     * When a new upload_id starts, archive any prior non-empty chunk folders
     * into dated recovered files instead of silently orphaning them.
     *
     * @return list<array<string, mixed>>
     */
    public static function archivePriorTakes(GameSession $session, ?string $exceptUploadId = null): array
    {
        $archived = [];
        $chunkRoot = self::chunkRoot($session);
        if (! Storage::disk('local')->exists($chunkRoot)) {
            return $archived;
        }

        foreach (Storage::disk('local')->directories($chunkRoot) as $dir) {
            $uploadId = basename($dir);
            if ($exceptUploadId !== null && $uploadId === $exceptUploadId) {
                continue;
            }
            $files = self::partFiles($session, $uploadId);
            if ($files === []) {
                Storage::disk('local')->deleteDirectory($dir);

                continue;
            }

            $stamp = date('Ymd-His');
            $dest = self::recoveredDir($session)."/{$stamp}-{$uploadId}.webm";
            $assembled = self::assemble($session, $uploadId, 'webm', $dest);
            if ($assembled['ok'] ?? false) {
                Storage::disk('local')->deleteDirectory($dir);
                $archived[] = [
                    'kind' => 'file',
                    'upload_id' => $uploadId,
                    'path' => $assembled['path'],
                    'parts' => $assembled['parts'] ?? count($files),
                    'label' => 'Recovered take',
                ];
            } else {
                $archived[] = [
                    'kind' => 'chunks',
                    'upload_id' => $uploadId,
                    'path' => $dir,
                    'parts' => count($files),
                    'label' => 'Recoverable take',
                    'error' => $assembled['error'] ?? 'Archive failed; chunk folder kept.',
                ];
            }
        }

        return $archived;
    }

    public static function extensionFromMime(?string $mimeType): string
    {
        $mimeType = $mimeType ?? 'audio/webm';

        return match (true) {
            str_contains($mimeType, 'ogg') => 'ogg',
            str_contains($mimeType, 'wav') => 'wav',
            str_contains($mimeType, 'mp4') => 'mp4',
            default => 'webm',
        };
    }

    public static function isSessionRelative(GameSession $session, string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($normalized, "sessions/{$session->id}/");
    }
}
