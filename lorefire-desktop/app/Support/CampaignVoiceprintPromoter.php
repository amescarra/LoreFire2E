<?php

namespace App\Support;

use App\Models\CampaignVoiceprint;
use App\Models\GameSession;
use App\Models\SpeakerProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Promote session speaker mappings (or a dedicated enrollment file)
 * into campaign-persistent voiceprints.
 */
class CampaignVoiceprintPromoter
{
    public function __construct(
        protected VoiceprintEmbeddingExtractor $extractor = new VoiceprintEmbeddingExtractor,
    ) {}

    /**
     * @return Collection<int, CampaignVoiceprint>
     */
    public function promoteSession(GameSession $session): Collection
    {
        $profiles = $session->speakerProfiles()->get();
        $embeddings = $this->extractor->extractForSession($session);

        $created = collect();
        foreach ($profiles as $profile) {
            $created->push(
                $this->upsertFromProfile($session, $profile, $embeddings[$profile->speaker_label] ?? null)
            );
        }

        return $created;
    }

    /**
     * @param  list<float>|null  $embedding
     */
    public function upsertFromProfile(
        GameSession $session,
        SpeakerProfile $profile,
        ?array $embedding = null,
        ?string $enrollmentAudioPath = null,
        ?string $embeddingModel = null,
    ): CampaignVoiceprint {
        $voiceprint = $this->findExisting($session->campaign_id, $profile);

        $payload = [
            'display_name' => $this->displayNameFor($profile),
            'character_id' => $profile->is_dm ? null : $profile->character_id,
            'is_dm' => (bool) $profile->is_dm,
        ];

        if (is_array($embedding) && $embedding !== []) {
            $payload['embedding'] = $embedding;
            $payload['embedding_model'] = $embeddingModel ?: 'pyannote/embedding';
            $payload['enrolled_at'] = now();
        }

        if (is_string($enrollmentAudioPath) && $enrollmentAudioPath !== '') {
            $payload['enrollment_audio_path'] = $enrollmentAudioPath;
        }

        if ($voiceprint) {
            $voiceprint->update($payload);
            $voiceprint->refresh();
        } else {
            $voiceprint = CampaignVoiceprint::query()->create(array_merge($payload, [
                'campaign_id' => $session->campaign_id,
            ]));
        }

        $profile->update([
            'campaign_voiceprint_id' => $voiceprint->id,
            'display_name' => $voiceprint->transcriptLabel(),
            'match_source' => $profile->match_source === 'auto' ? 'auto' : 'promoted',
        ]);

        return $voiceprint;
    }

    /**
     * @param  list<float>|null  $embedding
     */
    public function enrollAudio(
        int $campaignId,
        string $displayName,
        bool $isDm,
        ?int $characterId,
        string $audioAbsolutePath,
        ?CampaignVoiceprint $existing = null,
    ): CampaignVoiceprint {
        $detailed = $this->extractor->extractDetailed($audioAbsolutePath);
        $speakers = $detailed['speakers'];
        $embedding = $speakers === [] ? null : array_values($speakers)[0];

        $storedPath = $this->storeEnrollmentAudio($campaignId, $audioAbsolutePath, $existing?->id);

        $payload = [
            'campaign_id' => $campaignId,
            'display_name' => $displayName,
            'character_id' => $isDm ? null : $characterId,
            'is_dm' => $isDm,
            'enrollment_audio_path' => $storedPath,
        ];

        if (is_array($embedding) && $embedding !== []) {
            $payload['embedding'] = $embedding;
            $payload['embedding_model'] = $detailed['model'] ?: 'pyannote/embedding';
            $payload['enrolled_at'] = now();
        }

        if ($existing) {
            if ($existing->enrollment_audio_path && $existing->enrollment_audio_path !== $storedPath) {
                Storage::disk('local')->delete($existing->enrollment_audio_path);
            }
            $existing->update($payload);
            $existing->refresh();

            return $existing;
        }

        return CampaignVoiceprint::query()->create($payload);
    }

    protected function findExisting(int $campaignId, SpeakerProfile $profile): ?CampaignVoiceprint
    {
        if ($profile->campaign_voiceprint_id) {
            $linked = CampaignVoiceprint::query()
                ->where('campaign_id', $campaignId)
                ->whereKey($profile->campaign_voiceprint_id)
                ->first();
            if ($linked) {
                return $linked;
            }
        }

        $query = CampaignVoiceprint::query()->where('campaign_id', $campaignId);

        if ($profile->is_dm) {
            return $query->where('is_dm', true)
                ->where('display_name', $this->displayNameFor($profile))
                ->first();
        }

        if ($profile->character_id) {
            $byCharacter = (clone $query)
                ->where('is_dm', false)
                ->where('character_id', $profile->character_id)
                ->first();
            if ($byCharacter) {
                return $byCharacter;
            }
        }

        return $query->where('is_dm', false)
            ->where('display_name', $this->displayNameFor($profile))
            ->first();
    }

    protected function displayNameFor(SpeakerProfile $profile): string
    {
        $name = trim((string) $profile->display_name);
        if ($name !== '') {
            return $name;
        }

        return $profile->is_dm ? 'Dungeon Master' : (string) ($profile->character?->name ?: $profile->speaker_label);
    }

    protected function storeEnrollmentAudio(int $campaignId, string $absolutePath, ?int $voiceprintId): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $ext = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'wav';
        $name = ($voiceprintId ? (string) $voiceprintId : 'new').'-'.uniqid('', true).'.'.$ext;
        $relative = "campaigns/{$campaignId}/voiceprints/{$name}";
        Storage::disk('local')->makeDirectory("campaigns/{$campaignId}/voiceprints");
        Storage::disk('local')->put($relative, (string) file_get_contents($absolutePath));

        return $relative;
    }
}
