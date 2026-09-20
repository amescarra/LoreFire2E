<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\GameSession;
use App\Models\SpeakerProfile;
use App\Support\CampaignVoiceprintPromoter;
use App\Support\VoiceprintEmbeddingExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpeakerProfileController extends Controller
{
    /**
     * Store/update a speaker profile scoped to a specific session.
     * WhisperX assigns SPEAKER_00 per-session, so the same label means
     * different people across sessions — mappings must be session-scoped.
     * Optional save_to_campaign / update_voiceprint writes the durable
     * campaign voiceprint (embeddings when extractable).
     */
    public function storeForSession(Request $request, GameSession $session): RedirectResponse
    {
        $data = $request->validate([
            'speaker_label' => 'required|string|max:100',
            'display_name' => 'required|string|max:255',
            'character_id' => 'nullable|exists:characters,id',
            'is_dm' => 'boolean',
            'save_to_campaign' => 'sometimes|boolean',
            'update_voiceprint' => 'sometimes|boolean',
            'campaign_voiceprint_id' => 'nullable|exists:campaign_voiceprints,id',
        ]);

        $saveToCampaign = $request->boolean('save_to_campaign');
        $updateVoiceprint = $request->boolean('update_voiceprint');

        $profile = $session->speakerProfiles()->updateOrCreate(
            ['speaker_label' => $data['speaker_label']],
            [
                'campaign_id' => $session->campaign_id,
                'display_name' => $data['display_name'],
                'character_id' => ! empty($data['is_dm']) ? null : ($data['character_id'] ?? null),
                'is_dm' => (bool) ($data['is_dm'] ?? false),
                'campaign_voiceprint_id' => $data['campaign_voiceprint_id'] ?? null,
                'match_source' => 'manual',
            ],
        );

        if ($saveToCampaign || $updateVoiceprint) {
            $this->syncCampaignVoiceprint($session, $profile, $updateVoiceprint);
        }

        return back()->with('success', 'Speaker profile saved.');
    }

    public function update(Request $request, GameSession $session, SpeakerProfile $speaker): RedirectResponse
    {
        abort_unless($speaker->game_session_id === $session->id, 404);

        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'character_id' => 'nullable|exists:characters,id',
            'is_dm' => 'boolean',
            'update_voiceprint' => 'sometimes|boolean',
        ]);

        $speaker->update([
            'display_name' => $data['display_name'],
            'character_id' => ! empty($data['is_dm']) ? null : ($data['character_id'] ?? null),
            'is_dm' => (bool) ($data['is_dm'] ?? false),
            'match_source' => 'manual',
        ]);

        if ($request->boolean('update_voiceprint')) {
            $this->syncCampaignVoiceprint($session, $speaker->fresh(), true);
        }

        return back()->with('success', 'Speaker updated.');
    }

    public function destroy(GameSession $session, SpeakerProfile $speaker): RedirectResponse
    {
        abort_unless($speaker->game_session_id === $session->id, 404);
        $speaker->delete();

        return back()->with('success', 'Speaker removed.');
    }

    /**
     * Delete ALL speaker profiles for a session so the user can re-assign from scratch.
     */
    public function reset(GameSession $session): RedirectResponse
    {
        $session->speakerProfiles()->delete();

        return back()->with('success', 'Speaker assignments cleared.');
    }

    public function promote(GameSession $session, CampaignVoiceprintPromoter $promoter): RedirectResponse
    {
        if ($session->speakerProfiles()->count() === 0) {
            return back()->with('error', 'Assign speakers on this session first.');
        }

        $promoter->promoteSession($session);

        return back()->with('success', 'Voices saved for this campaign.');
    }

    // ── Legacy campaign-scoped store (kept for backwards compat) ──────────────

    public function store(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'speaker_label' => 'required|string|max:100',
            'display_name' => 'required|string|max:255',
            'character_id' => 'nullable|exists:characters,id',
            'is_dm' => 'boolean',
        ]);

        $campaign->speakerProfiles()->updateOrCreate(
            ['speaker_label' => $data['speaker_label'], 'game_session_id' => null],
            $data,
        );

        return back()->with('success', 'Speaker profile saved.');
    }

    public function updateForCampaign(Request $request, Campaign $campaign, SpeakerProfile $speaker): RedirectResponse
    {
        abort_unless($speaker->campaign_id === $campaign->id, 404);

        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'character_id' => 'nullable|exists:characters,id',
            'is_dm' => 'boolean',
        ]);

        $speaker->update($data);

        return back()->with('success', 'Speaker updated.');
    }

    public function destroyForCampaign(Campaign $campaign, SpeakerProfile $speaker): RedirectResponse
    {
        abort_unless($speaker->campaign_id === $campaign->id, 404);
        $speaker->delete();

        return back()->with('success', 'Speaker removed.');
    }

    protected function syncCampaignVoiceprint(GameSession $session, SpeakerProfile $profile, bool $reextract): void
    {
        $embedding = null;
        if ($reextract || ! $profile->campaign_voiceprint_id) {
            $extracted = app(VoiceprintEmbeddingExtractor::class)->extractForSession($session);
            $embedding = $extracted[$profile->speaker_label] ?? null;
        }

        app(CampaignVoiceprintPromoter::class)->upsertFromProfile($session, $profile, $embedding);
    }
}
