<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\CampaignVoiceprint;
use App\Models\GameSession;
use App\Models\SpeakerProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Apply campaign-persistent voiceprints to a newly diarized transcript.
 *
 * Does not rewrite SPEAKER_N inside transcript.json — those labels stay
 * session-local. Session speaker_profiles become the mapping layer.
 */
class VoiceprintResolver
{
    public function __construct(
        protected VoiceprintMatcher $matcher = new VoiceprintMatcher,
        protected VoiceprintEmbeddingExtractor $extractor = new VoiceprintEmbeddingExtractor,
    ) {}

    /**
     * @return array<string, array{voiceprint: CampaignVoiceprint, score: float}>
     */
    public function applyToSession(GameSession $session): array
    {
        $session->loadMissing('campaign');
        $campaign = $session->campaign;
        if (! $campaign) {
            return [];
        }

        $voiceprints = $campaign->voiceprints()->with('character')->get()
            ->filter(fn (CampaignVoiceprint $vp) => $vp->hasEmbedding())
            ->values();

        if ($voiceprints->isEmpty()) {
            return [];
        }

        $embeddings = $this->extractor->extractForSession($session);
        if ($embeddings === []) {
            Log::info('[VoiceprintResolver] no embeddings for session', [
                'session' => $session->id,
            ]);

            return [];
        }

        $assigned = $this->matcher->assign($embeddings, $voiceprints);
        foreach ($assigned as $label => $hit) {
            $this->persistSessionMapping($session, (string) $label, $hit['voiceprint'], (float) $hit['score'], 'auto');
        }

        return $assigned;
    }

    /**
     * Resolve live-chunk SPEAKER_N labels onto durable display names.
     * Live chunks get fresh pyannote labels, so we rewrite the speaker
     * field after matching rather than trusting session-scoped SPEAKER_N.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<string, list<float>>  $embeddings
     * @return array<int, array<string, mixed>>
     */
    public function resolveSegments(Campaign $campaign, array $segments, array $embeddings): array
    {
        $voiceprints = $campaign->voiceprints()->with('character')->get()
            ->filter(fn (CampaignVoiceprint $vp) => $vp->hasEmbedding())
            ->values();

        if ($voiceprints->isEmpty() || $embeddings === []) {
            return $segments;
        }

        $assigned = $this->matcher->assign($embeddings, $voiceprints);
        if ($assigned === []) {
            return $segments;
        }

        return array_map(function (array $seg) use ($assigned) {
            $label = $seg['speaker'] ?? $seg['speaker_label'] ?? null;
            if (! is_string($label) || ! isset($assigned[$label])) {
                return $seg;
            }
            $voiceprint = $assigned[$label]['voiceprint'];
            $seg['speaker_label'] = $label;
            $seg['speaker'] = $voiceprint->transcriptLabel();
            $seg['speaker_is_dm'] = $voiceprint->is_dm;
            $seg['voiceprint_id'] = $voiceprint->id;
            $seg['match_confidence'] = $assigned[$label]['score'];

            return $seg;
        }, $segments);
    }

    public function persistSessionMapping(
        GameSession $session,
        string $speakerLabel,
        CampaignVoiceprint $voiceprint,
        ?float $confidence,
        string $source,
    ): SpeakerProfile {
        return $session->speakerProfiles()->updateOrCreate(
            ['speaker_label' => $speakerLabel],
            [
                'campaign_id' => $session->campaign_id,
                'display_name' => $voiceprint->transcriptLabel(),
                'character_id' => $voiceprint->character_id,
                'is_dm' => $voiceprint->is_dm,
                'campaign_voiceprint_id' => $voiceprint->id,
                'match_confidence' => $confidence,
                'match_source' => $source,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadTranscriptSegments(GameSession $session): array
    {
        if (! $session->transcript_path || ! Storage::disk('local')->exists($session->transcript_path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk('local')->get($session->transcript_path), true);

        return is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [];
    }
}
