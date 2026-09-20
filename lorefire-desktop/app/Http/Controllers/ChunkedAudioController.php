<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeLiveAudio;
use App\Models\GameSession;
use App\Support\AppTemp;
use App\Support\ChunkedRecording;
use App\Support\LiveTranscript;
use App\Support\WhisperxRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChunkedAudioController extends Controller
{
    /**
     * Accept a pre-recorded audio file upload and treat it the same as a
     * finalised chunked recording — persist, then dispatch transcription.
     */
    public function importAudio(Request $request, GameSession $session): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|max:512000',
        ]);

        $file      = $request->file('audio');
        $extension = $file->getClientOriginalExtension() ?: 'audio';
        $finalPath = "sessions/{$session->id}/audio.{$extension}";

        // If there is an existing audio file with a different extension, remove it
        // so we don't leave orphaned files on disk.
        if ($session->audio_path && $session->audio_path !== $finalPath) {
            Storage::disk('local')->delete($session->audio_path);
        }

        $absPath = str_replace('/', DIRECTORY_SEPARATOR, Storage::disk('local')->path($finalPath));

        if (!is_dir(dirname($absPath))) {
            mkdir(dirname($absPath), 0755, true);
        }

        // Move the uploaded file directly into place
        $file->move(dirname($absPath), basename($absPath));

        $session->update([
            'audio_path'           => $finalPath,
            'transcription_status' => 'pending',
        ]);

        TranscribeAudio::dispatch($session);

        return response()->json(['audio_path' => $finalPath]);
    }

    /**
     * Stream the session's stored audio file as a download.
     */
    public function downloadAudio(GameSession $session): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        if (! $session->audio_path || ! Storage::disk('local')->exists($session->audio_path)) {
            return response()->json(['error' => 'No audio file found for this session.'], 404);
        }

        $absPath   = str_replace('/', DIRECTORY_SEPARATOR, Storage::disk('local')->path($session->audio_path));
        $extension = pathinfo($session->audio_path, PATHINFO_EXTENSION);
        $filename  = Str::slug($session->title) . '-session-' . ($session->session_number ?? $session->id) . '.' . $extension;

        return response()->streamDownload(function () use ($absPath) {
            $handle = fopen($absPath, 'rb');
            if ($handle === false) {
                return;
            }
            while (! feof($handle)) {
                echo fread($handle, 8192);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type'   => mime_content_type($absPath) ?: 'application/octet-stream',
            'Content-Length' => filesize($absPath),
        ]);
    }

    /**
     * Initialise a new chunked upload session.
     * Returns an upload_id the client uses for all subsequent chunk POSTs.
     */
    public function init(Request $request, GameSession $session): JsonResponse
    {
        AppTemp::sweep();

        $recovered = ChunkedRecording::archivePriorTakes($session);

        $uploadId = Str::uuid()->toString();

        Storage::disk('local')->makeDirectory(ChunkedRecording::chunkDir($session, $uploadId));

        LiveTranscript::reset($session);

        return response()->json([
            'upload_id' => $uploadId,
            'recovered' => $recovered,
        ]);
    }

    /**
     * Accept one chunk of audio data.
     *
     * Expected fields:
     *   upload_id   — uuid returned by init()
     *   chunk_index — 0-based integer, determines reassembly order
     *   chunk       — the audio file blob (webm/ogg)
     */
    public function chunk(Request $request, GameSession $session): JsonResponse
    {
        $request->validate([
            'upload_id'   => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'chunk'       => 'required|file',
        ]);

        $uploadId = $request->input('upload_id');
        $index = (int) $request->input('chunk_index');

        $stored = ChunkedRecording::storeUploadedChunk(
            $session,
            $uploadId,
            $index,
            $request->file('chunk')
        );

        if (! ($stored['ok'] ?? false)) {
            return response()->json([
                'stored' => false,
                'error' => $stored['error'] ?? 'Could not write audio chunk to disk.',
            ], 507);
        }

        $chunkPath = $stored['path'];
        $chunkAbs = Storage::disk('local')->path($chunkPath);
        $partialAbs = Storage::disk('local')->path(LiveTranscript::partialAudioPath($session));
        app(WhisperxRunner::class)->appendFile($chunkAbs, $partialAbs);

        TranscribeLiveAudio::dispatch($session, $index);

        return response()->json(['stored' => $chunkPath]);
    }

    /**
     * Concatenate all chunks into a single audio file, clean up, and
     * update the session record before dispatching transcription.
     *
     * Expected fields:
     *   upload_id     — uuid returned by init()
     *   total_chunks  — expected chunk count (for validation)
     *   mime_type     — e.g. "audio/webm"
     */
    public function finalize(Request $request, GameSession $session): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string',
            'total_chunks' => 'nullable|integer|min:0',
            'mime_type' => 'nullable|string',
        ]);

        $uploadId = $request->input('upload_id');
        $files = ChunkedRecording::partFiles($session, $uploadId);
        if ($files === []) {
            return response()->json(['error' => 'No chunks found for this take.'], 422);
        }

        $found = count($files);
        $mismatch = null;
        if ($request->filled('total_chunks')) {
            $expected = (int) $request->input('total_chunks');
            if ($expected > 0 && $expected !== $found) {
                $mismatch = ['expected' => $expected, 'found' => $found];
            }
        }

        $extension = ChunkedRecording::extensionFromMime($request->input('mime_type'));
        $assembled = ChunkedRecording::assemble($session, $uploadId, $extension);
        if (! ($assembled['ok'] ?? false)) {
            return response()->json(['error' => $assembled['error'] ?? 'Could not assemble chunks.'], 500);
        }

        Storage::disk('local')->deleteDirectory(ChunkedRecording::chunkDir($session, $uploadId));

        $finalPath = $assembled['path'];
        $session->update([
            'audio_path' => $finalPath,
            'transcription_status' => 'pending',
        ]);

        TranscribeAudio::dispatch($session);

        return response()->json([
            'audio_path' => $finalPath,
            'parts' => $assembled['parts'] ?? $found,
            'chunk_count_mismatch' => $mismatch,
        ]);
    }

    /**
     * List leftover chunk folders and dated recovered files for this session.
     */
    public function takes(GameSession $session): JsonResponse
    {
        return response()->json(['takes' => ChunkedRecording::listTakes($session)]);
    }

    /**
     * Finalize an existing chunk directory or promote a recovered file to
     * session audio after an app restart — no manual shell concat required.
     */
    public function recover(Request $request, GameSession $session): JsonResponse
    {
        $request->validate([
            'upload_id' => 'nullable|string',
            'path' => 'nullable|string',
            'mime_type' => 'nullable|string',
            'transcribe' => 'nullable|boolean',
        ]);

        $path = $request->input('path');
        if (is_string($path) && $path !== '') {
            if (! ChunkedRecording::isSessionRelative($session, $path) || ! Storage::disk('local')->exists($path)) {
                return response()->json(['error' => 'Recovered file was not found for this session.'], 404);
            }

            $session->update([
                'audio_path' => $path,
                'transcription_status' => $request->boolean('transcribe', true) ? 'pending' : ($session->transcription_status ?? 'none'),
            ]);

            if ($request->boolean('transcribe', true)) {
                TranscribeAudio::dispatch($session);
            }

            return response()->json(['audio_path' => $path, 'kind' => 'file']);
        }

        $uploadId = $request->input('upload_id');
        if (! is_string($uploadId) || $uploadId === '') {
            return response()->json(['error' => 'Provide upload_id or path to recover.'], 422);
        }

        $extension = ChunkedRecording::extensionFromMime($request->input('mime_type'));
        $assembled = ChunkedRecording::assemble($session, $uploadId, $extension);
        if (! ($assembled['ok'] ?? false)) {
            return response()->json(['error' => $assembled['error'] ?? 'Could not assemble chunks.'], 422);
        }

        Storage::disk('local')->deleteDirectory(ChunkedRecording::chunkDir($session, $uploadId));

        $session->update([
            'audio_path' => $assembled['path'],
            'transcription_status' => $request->boolean('transcribe', true) ? 'pending' : ($session->transcription_status ?? 'none'),
        ]);

        if ($request->boolean('transcribe', true)) {
            TranscribeAudio::dispatch($session);
        }

        return response()->json([
            'audio_path' => $assembled['path'],
            'parts' => $assembled['parts'] ?? 0,
            'kind' => 'chunks',
        ]);
    }
}
