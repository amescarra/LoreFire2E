<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignVoiceprint;
use App\Support\CampaignVoiceprintPromoter;
use App\Support\VoiceprintEmbeddingExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CampaignVoiceprintController extends Controller
{
    public function index(Campaign $campaign): Response
    {
        $campaign->load(['characters:id,campaign_id,name,class,level']);

        $voiceprints = $campaign->voiceprints()
            ->with('character:id,name')
            ->orderByDesc('is_dm')
            ->orderBy('display_name')
            ->get()
            ->map(fn (CampaignVoiceprint $vp) => $this->payload($vp));

        return Inertia::render('Campaigns/Voices', [
            'campaign' => $campaign,
            'characters' => $campaign->characters,
            'voiceprints' => $voiceprints,
            'embeddingExtractSupported' => app(VoiceprintEmbeddingExtractor::class)->isSupported(),
        ]);
    }

    public function store(Request $request, Campaign $campaign, CampaignVoiceprintPromoter $promoter): RedirectResponse
    {
        $data = $this->validated($request);
        $audio = $request->file('audio');

        if ($audio instanceof UploadedFile) {
            $voiceprint = $promoter->enrollAudio(
                $campaign->id,
                $data['display_name'],
                (bool) ($data['is_dm'] ?? false),
                $data['character_id'] ?? null,
                $audio,
            );

            return back()->with($this->flashFor($voiceprint));
        }

        CampaignVoiceprint::query()->create([
            'campaign_id' => $campaign->id,
            'display_name' => $data['display_name'],
            'character_id' => ! empty($data['is_dm']) ? null : ($data['character_id'] ?? null),
            'is_dm' => (bool) ($data['is_dm'] ?? false),
        ]);

        return back()->with('success', 'Voice saved for this campaign.');
    }

    public function update(Request $request, Campaign $campaign, CampaignVoiceprint $voiceprint): RedirectResponse
    {
        $this->assertCampaign($campaign, $voiceprint);
        $data = $this->validated($request, requireName: true);

        $voiceprint->update([
            'display_name' => $data['display_name'],
            'character_id' => ! empty($data['is_dm']) ? null : ($data['character_id'] ?? null),
            'is_dm' => (bool) ($data['is_dm'] ?? false),
        ]);

        return back()->with('success', 'Voice updated.');
    }

    public function enroll(
        Request $request,
        Campaign $campaign,
        CampaignVoiceprint $voiceprint,
        CampaignVoiceprintPromoter $promoter,
    ): RedirectResponse {
        $this->assertCampaign($campaign, $voiceprint);
        $request->validate([
            'audio' => 'required|file|max:512000',
        ]);

        $audio = $request->file('audio');
        if (! $audio instanceof UploadedFile) {
            return back()->withErrors(['audio' => 'Recording was empty. Read a few sentences, then Stop.']);
        }

        $updated = $promoter->enrollAudio(
            $campaign->id,
            $voiceprint->display_name,
            (bool) $voiceprint->is_dm,
            $voiceprint->character_id,
            $audio,
            $voiceprint,
        );

        return back()->with($this->flashFor($updated));
    }

    public function destroy(Campaign $campaign, CampaignVoiceprint $voiceprint): RedirectResponse
    {
        $this->assertCampaign($campaign, $voiceprint);

        if ($voiceprint->enrollment_audio_path) {
            Storage::disk('local')->delete($voiceprint->enrollment_audio_path);
        }
        $voiceprint->delete();

        return back()->with('success', 'Voice removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $requireName = true): array
    {
        return $request->validate([
            'display_name' => ($requireName ? 'required' : 'nullable').'|string|max:255',
            'character_id' => 'nullable|exists:characters,id',
            'is_dm' => 'sometimes|boolean',
            'audio' => 'nullable|file|max:512000',
        ]);
    }

    protected function assertCampaign(Campaign $campaign, CampaignVoiceprint $voiceprint): void
    {
        abort_unless($voiceprint->campaign_id === $campaign->id, 404);
    }

    /**
     * @return array<string, string>
     */
    protected function flashFor(CampaignVoiceprint $voiceprint): array
    {
        if ($voiceprint->hasEmbedding()) {
            return ['success' => 'Voiceprint ready.'];
        }

        $message = is_string($voiceprint->extract_error) && $voiceprint->extract_error !== ''
            ? $voiceprint->extract_error
            : null;

        if (! $voiceprint->hasEnrollmentAudio()) {
            return ['error' => $message ?: 'Enrollment audio could not be stored. Try Record again or upload a file.'];
        }

        if (! app(VoiceprintEmbeddingExtractor::class)->isSupported()) {
            return ['info' => $message ?: 'Audio saved. Embedding extraction is skipped on Windows ARM — auto-label needs Linux WhisperX + pyannote.'];
        }

        return ['error' => $message ?: 'Audio saved, but the voiceprint could not be extracted. Check the Hugging Face token and try again.'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(CampaignVoiceprint $voiceprint): array
    {
        return [
            'id' => $voiceprint->id,
            'campaign_id' => $voiceprint->campaign_id,
            'display_name' => $voiceprint->display_name,
            'character_id' => $voiceprint->character_id,
            'is_dm' => $voiceprint->is_dm,
            'has_embedding' => $voiceprint->hasEmbedding(),
            'has_enrollment_audio' => $voiceprint->hasEnrollmentAudio(),
            'extract_error' => $voiceprint->extract_error,
            'enrollment_status' => $voiceprint->enrollmentStatus(),
            'embedding_model' => $voiceprint->embedding_model,
            'enrollment_audio_path' => $voiceprint->enrollment_audio_path,
            'enrolled_at' => $voiceprint->enrolled_at?->toIso8601String(),
            'character' => $voiceprint->character
                ? ['id' => $voiceprint->character->id, 'name' => $voiceprint->character->name]
                : null,
        ];
    }
}
