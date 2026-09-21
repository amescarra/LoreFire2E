<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeAudio;
use App\Jobs\GenerateBardicSummary;
use App\Jobs\GenerateArtPrompts;
use App\Jobs\ExtractSessionDetails;
use App\Models\GameSession;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class TranscriptionController extends Controller
{
    /**
     * Accept a recorded audio blob, save it, and immediately dispatch transcription.
     */
    public function stopRecording(Request $request, GameSession $session): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:webm,ogg,wav,mp4|max:512000', // 500 MB
        ]);

        $path = $request->file('audio')->store("sessions/{$session->id}", 'local');

        $session->update([
            'audio_path'           => $path,
            'transcription_status' => 'pending',
        ]);

        TranscribeAudio::dispatch($session);

        return response()->json(['audio_path' => $path]);
    }

    /**
     * Dispatch the WhisperX transcription job.
     */
    public function transcribe(Request $request, GameSession $session): RedirectResponse
    {
        if (! $session->audio_path) {
            return back()->withErrors(['audio' => 'No audio file found for this session.']);
        }

        $session->update(['transcription_status' => 'pending']);
        TranscribeAudio::dispatch($session);

        return back()->with('success', 'Transcription queued.');
    }

    /**
     * Dispatch the bardic summary generation job.
     */
    public function generateSummary(Request $request, GameSession $session): JsonResponse|RedirectResponse
    {
        if (! $session->transcript_path) {
            return back()->withErrors(['transcript' => 'No transcript found. Transcribe the session first.']);
        }

        $session->update(['summary_status' => 'generating']);
        GenerateBardicSummary::dispatch($session);

        if ($request->expectsJson() || ! $request->header('X-Inertia')) {
            return response()->json(['queued' => true]);
        }

        return back()->with('success', 'Bardic summary generation queued.');
    }

    /**
     * JSON endpoint for polling summary generation status.
     */
    public function summaryStatus(GameSession $session): JsonResponse
    {
        $session->refresh();
        return response()->json([
            'status'        => $session->summary_status,
            'summary'       => $session->summary,
            'session_notes' => $session->session_notes,
        ]);
    }

    /**
     * JSON endpoint for polling transcription status.
     */
    public function transcriptionStatus(GameSession $session): JsonResponse
    {
        $session->refresh();

        $progress = null;
        $progressPath = "sessions/{$session->id}/transcription_progress.json";
        if (Storage::disk('local')->exists($progressPath)) {
            $raw = Storage::disk('local')->get($progressPath);
            $progress = json_decode($raw, true);
        }

        return response()->json([
            'status'          => $session->transcription_status,
            'transcript_path' => $session->transcript_path,
            'progress'        => $progress, // null | { stage: string, percent: int }
        ]);
    }

    /**
     * Cancel a pending or in-progress transcription.
     *
     * For a pending job: delete it from the queue before it is picked up.
     * For a running job: write a sentinel file the job checks each loop iteration.
     * Either way, mark the session status as 'cancelled'.
     */
    public function cancelTranscription(GameSession $session): JsonResponse
    {
        $session->refresh();

        $status = $session->transcription_status;

        if (! in_array($status, ['pending', 'processing'])) {
            return response()->json(['error' => 'No active transcription to cancel.'], 422);
        }

        // Remove any pending (not yet reserved) jobs for this session from the queue.
        // We match on the serialised session id inside the payload JSON.
        DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('payload', 'like', '%"TranscribeAudio"%')
            ->where('payload', 'like', '%"id":' . $session->id . '%')
            ->delete();

        // Write the sentinel file so a running job will cooperatively stop.
        Storage::disk('local')->put(
            "sessions/{$session->id}/cancel_transcription",
            '1'
        );

        // Clean up the progress file so the UI doesn't show stale data.
        Storage::disk('local')->delete("sessions/{$session->id}/transcription_progress.json");

        $session->update(['transcription_status' => 'cancelled']);

        return response()->json(['cancelled' => true]);
    }

    /**
     * Dispatch art prompt generation job.
     */
    public function generateArtPrompts(Request $request, GameSession $session): JsonResponse|RedirectResponse
    {
        if (! $session->transcript_path && ! $session->summary) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No transcript or summary found.'], 422);
            }

            return back()->withErrors(['transcript' => 'No transcript or summary found.']);
        }

        $session->update(['art_prompts_status' => 'generating']);

        GenerateArtPrompts::dispatch($session);

        if ($request->expectsJson()) {
            return response()->json([
                'queued' => true,
                'status' => $session->art_prompts_status,
            ]);
        }

        return back()->with('success', 'Art prompt generation queued.');
    }

    /**
     * Cancel art prompt generation, including recovery from a stuck spinner.
     *
     * Always returns 200. If the row is still "generating", it becomes
     * "cancelled" and matching queue rows are removed. If the UI was spinning
     * while the database was already idle, this is a no-op success so Cancel
     * can clear that state without a 422.
     */
    public function cancelArtPrompts(GameSession $session): JsonResponse
    {
        $session->refresh();

        // Drop queued and reserved rows so a killed worker cannot revive the
        // job after retry_after (4000s) and wipe prompts. A process that is
        // still inside handle() sees status=cancelled and bails.
        $this->deleteArtPromptJobs($session);

        if ($session->art_prompts_status === 'generating') {
            $session->update(['art_prompts_status' => 'cancelled']);
        }

        return response()->json([
            'cancelled' => true,
            'status' => $session->fresh()->art_prompts_status,
        ]);
    }

    /**
     * Return current art prompts generation status + fresh prompts when done.
     *
     * A database-queue row reserved longer than the job timeout means the
     * NativePHP worker died without failed() (SIGKILL). retry_after is 4000s,
     * so leaving that row would pin the spinner for over an hour. Settle it.
     */
    public function artPromptsStatus(GameSession $session): JsonResponse
    {
        $session->refresh();
        $this->settleStaleArtPromptGeneration($session);
        $session->refresh();

        $data = ['status' => $session->art_prompts_status];

        if ($session->art_prompts_status === 'done') {
            $data['scene_art_prompts'] = $session->sceneArtPrompts()
                ->get()
                ->map(fn ($p) => $p->toArray())
                ->values();
        }

        return response()->json($data);
    }

    /**
     * Seconds a reserved GenerateArtPrompts row may sit before we treat the
     * worker as dead. The job timeout is 300s and the only LLM call is 120s.
     */
    private const ART_PROMPT_STALE_SECONDS = 360;

    private function settleStaleArtPromptGeneration(GameSession $session): void
    {
        if ($session->art_prompts_status !== 'generating') {
            return;
        }

        if (config('queue.default') !== 'database') {
            return;
        }

        $jobs = $this->artPromptJobsFor($session);
        $staleBefore = now()->subSeconds(self::ART_PROMPT_STALE_SECONDS)->getTimestamp();

        $hasLiveJob = $jobs->contains(function ($row) use ($staleBefore) {
            if ($row->reserved_at === null) {
                return true;
            }

            return (int) $row->reserved_at >= $staleBefore;
        });

        if ($hasLiveJob) {
            return;
        }

        // Dispatch writes the row after flipping status. Don't fail a request
        // that has only just queued.
        if ($jobs->isEmpty() && $session->updated_at && $session->updated_at->gt(now()->subSeconds(15))) {
            return;
        }

        Log::warning('GenerateArtPrompts stale; resetting art_prompts_status', [
            'session_id' => $session->id,
            'jobs' => $jobs->count(),
        ]);

        $this->deleteArtPromptJobs($session, $jobs);
        $session->update(['art_prompts_status' => 'failed']);
    }

    private function deleteArtPromptJobs(GameSession $session, ?Collection $jobs = null): void
    {
        $jobs ??= $this->artPromptJobsFor($session);

        foreach ($jobs as $row) {
            DB::table('jobs')->where('id', $row->id)->delete();
        }
    }

    private function artPromptJobsFor(GameSession $session): Collection
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return collect();
        }

        return DB::table('jobs')
            ->where('payload', 'like', '%GenerateArtPrompts%')
            ->get()
            ->filter(fn ($row) => $this->jobPayloadIsArtPromptsForSession((string) $row->payload, (int) $session->id))
            ->values();
    }

    private function jobPayloadIsArtPromptsForSession(string $payload, int $sessionId): bool
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;
        if (! is_string($command) || ! str_contains($command, 'GenerateArtPrompts')) {
            return false;
        }

        try {
            $job = unserialize($command, ['allowed_classes' => true]);
        } catch (\Throwable) {
            $job = false;
        }

        if ($job instanceof GenerateArtPrompts) {
            try {
                return (int) $job->session->id === $sessionId;
            } catch (\Throwable) {
                return false;
            }
        }

        return preg_match('/s:2:"id";i:'.$sessionId.';/', $command) === 1;
    }

    /**
     * Dispatch the session detail extraction job (character updates + NPCs).
     */
    public function extractDetails(GameSession $session): JsonResponse
    {
        if (! $session->transcript_path) {
            return response()->json(['error' => 'No transcript found. Transcribe the session first.'], 422);
        }

        $session->update(['extraction_status' => 'generating']);
        ExtractSessionDetails::dispatch($session);

        return response()->json(['queued' => true]);
    }

    /**
     * Poll extraction status.
     */
    public function extractionStatus(GameSession $session): JsonResponse
    {
        $session->refresh();
        return response()->json(['status' => $session->extraction_status]);
    }
}
