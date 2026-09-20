<?php

namespace App\Jobs;

use App\Models\GameSession;
use App\Support\LiveTranscript;
use App\Support\WhisperxRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribe only the newest recording chunk (existing MediaRecorder path).
 * Does not re-run WhisperX on the whole session.
 */
class TranscribeLiveAudio implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(
        public GameSession $session,
        public int $chunkIndex,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('live-audio-'.$this->session->id))
                ->releaseAfter(8)
                ->expireAfter(700),
        ];
    }

    public function handle(WhisperxRunner $runner): void
    {
        $session = $this->session->fresh();
        if (! $session) {
            return;
        }

        $last = (int) ($session->live_chunk_index ?? -1);
        if ($this->chunkIndex <= $last) {
            return;
        }
        if ($this->chunkIndex > $last + 1) {
            $this->release(5);

            return;
        }

        $partialRel = LiveTranscript::partialAudioPath($session);
        $partialAbs = Storage::disk('local')->path($partialRel);
        if (! is_file($partialAbs)) {
            Log::info('[TranscribeLiveAudio] no live partial yet', ['session' => $session->id]);

            return;
        }

        $start = (float) ($session->live_audio_seconds ?? 0);
        $partialDuration = $runner->durationSeconds($partialAbs);
        $sliceRel = LiveTranscript::relativeDir($session).'/slice-'.$this->chunkIndex.'.wav';
        $sliceAbs = Storage::disk('local')->path($sliceRel);
        $outRel = LiveTranscript::relativeDir($session).'/slice-'.$this->chunkIndex.'.json';
        $outAbs = Storage::disk('local')->path($outRel);

        $audioForWhisper = $partialAbs;
        if ($start > 0.05) {
            if (! $runner->sliceAudio($partialAbs, $sliceAbs, $start)) {
                Log::info('[TranscribeLiveAudio] empty or failed slice', [
                    'session' => $session->id,
                    'chunk' => $this->chunkIndex,
                    'start' => $start,
                ]);
                $session->update([
                    'live_chunk_index' => $this->chunkIndex,
                    'live_audio_seconds' => $partialDuration ?? $start,
                ]);

                return;
            }
            $audioForWhisper = $sliceAbs;
        }

        $result = $runner->transcribe($audioForWhisper, $outAbs, $runner->shouldDiarizeLive());
        $offset = $start;
        $segments = [];

        foreach ($result['segments'] ?? [] as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segStart = $offset + (float) ($segment['start'] ?? 0);
            $segEnd = $offset + (float) ($segment['end'] ?? 0);
            $label = $segment['speaker'] ?? null;
            $segments[] = [
                'text' => $text,
                'start' => $segStart,
                'end' => $segEnd,
                'speaker' => $label,
                'speaker_label' => $label,
                'chunk_index' => $this->chunkIndex,
            ];
        }

        if ($segments !== []) {
            $campaign = $session->campaign;
            if ($campaign) {
                $embeddings = app(\App\Support\VoiceprintEmbeddingExtractor::class)
                    ->extract($audioForWhisper, $outAbs);
                $segments = app(\App\Support\VoiceprintResolver::class)
                    ->resolveSegments($campaign, $segments, $embeddings);
            }
        }

        if ($segments !== []) {
            LiveTranscript::appendSegments($session, $segments);
        }

        $session->update([
            'live_chunk_index' => $this->chunkIndex,
            'live_audio_seconds' => $partialDuration ?? max($start, collect($segments)->max('end') ?? $start),
        ]);

        if ($segments !== []) {
            ExtractLiveSheetUpdates::dispatch($session);
        }
    }
}
