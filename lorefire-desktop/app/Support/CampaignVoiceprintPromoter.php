<?php

namespace App\Support;

use App\Models\CampaignVoiceprint;
use App\Models\GameSession;
use App\Models\SpeakerProfile;
use Illuminate\Http\UploadedFile;
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
     * Persist the recorded/uploaded take first, then extract an embedding.
     * Audio is kept even when pyannote cannot produce a vector.
     *
     * @param  UploadedFile|string  $audio  Uploaded File (preferred) or an absolute path
     */
    public function enrollAudio(
        int $campaignId,
        string $displayName,
        bool $isDm,
        ?int $characterId,
        UploadedFile|string $audio,
        ?CampaignVoiceprint $existing = null,
    ): CampaignVoiceprint {
        $storedPath = $this->storeEnrollmentAudio($campaignId, $audio, $existing?->id);
        $extractPath = $this->absoluteAudioPath($audio, $storedPath);

        $detailed = is_string($extractPath) && $extractPath !== ''
            ? $this->extractor->extractDetailed($extractPath)
            : [
                'speakers' => [],
                'model' => null,
                'error' => $storedPath
                    ? 'Enrollment audio was stored but the extract path was not readable.'
                    : 'Enrollment audio could not be stored from this upload. Try Record again or upload a wav/webm file.',
                'skipped' => false,
            ];

        $speakers = $detailed['speakers'] ?? [];
        $embedding = (is_array($speakers) && $speakers !== []) ? array_values($speakers)[0] : null;
        $extractError = is_string($detailed['error'] ?? null) && $detailed['error'] !== ''
            ? $detailed['error']
            : null;

        $payload = [
            'campaign_id' => $campaignId,
            'display_name' => $displayName,
            'character_id' => $isDm ? null : $characterId,
            'is_dm' => $isDm,
            'enrollment_audio_path' => $storedPath ?? $existing?->enrollment_audio_path,
            'extract_error' => (is_array($embedding) && $embedding !== []) ? null : $extractError,
        ];

        if (is_array($embedding) && $embedding !== []) {
            $payload['embedding'] = $embedding;
            $payload['embedding_model'] = $detailed['model'] ?: 'pyannote/embedding';
            $payload['enrolled_at'] = now();
            $payload['extract_error'] = null;
        }

        if ($existing) {
            if ($storedPath && $existing->enrollment_audio_path && $existing->enrollment_audio_path !== $storedPath) {
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

    protected function storeEnrollmentAudio(int $campaignId, UploadedFile|string $audio, ?int $voiceprintId): ?string
    {
        $ext = $this->audioExtension($audio);
        $name = ($voiceprintId ? (string) $voiceprintId : 'new').'-'.uniqid('', true).'.'.$ext;
        $dir = "campaigns/{$campaignId}/voiceprints";
        $relative = $dir.'/'.$name;
        Storage::disk('local')->makeDirectory($dir);

        if ($audio instanceof UploadedFile) {
            try {
                $stored = $audio->storeAs($dir, $name, 'local');
                if (is_string($stored) && $stored !== '') {
                    return $stored;
                }
            } catch (\Throwable) {
                // Fall through to a raw copy — storeAs uses getRealPath().
            }
        }

        $contents = $this->audioContents($audio);
        if ($contents === null) {
            return null;
        }

        Storage::disk('local')->put($relative, $contents);

        return $relative;
    }

    protected function audioExtension(UploadedFile|string $audio): string
    {
        $allowed = ['webm', 'ogg', 'wav', 'mp3', 'm4a', 'mp4', 'opus', 'flac'];
        $ext = '';

        if ($audio instanceof UploadedFile) {
            $ext = strtolower((string) ($audio->getClientOriginalExtension() ?: pathinfo($audio->getClientOriginalName(), PATHINFO_EXTENSION)));
        } else {
            $ext = strtolower((string) pathinfo($audio, PATHINFO_EXTENSION));
        }

        return in_array($ext, $allowed, true) ? $ext : 'webm';
    }

    protected function audioContents(UploadedFile|string $audio): ?string
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getPathname() ?: $audio->getRealPath();
            if (is_string($path) && $path !== '' && is_file($path)) {
                $contents = file_get_contents($path);

                return $contents === false ? null : $contents;
            }

            try {
                return $audio->getContent();
            } catch (\Throwable) {
                return null;
            }
        }

        if (! is_file($audio)) {
            return null;
        }

        $contents = file_get_contents($audio);

        return $contents === false ? null : $contents;
    }

    protected function absoluteAudioPath(UploadedFile|string $audio, ?string $storedPath): ?string
    {
        if (is_string($storedPath) && $storedPath !== '') {
            $storedAbs = Storage::disk('local')->path($storedPath);
            if (is_file($storedAbs)) {
                return $storedAbs;
            }
        }

        if ($audio instanceof UploadedFile) {
            $path = $audio->getPathname() ?: $audio->getRealPath();

            return (is_string($path) && is_file($path)) ? $path : null;
        }

        return is_file($audio) ? $audio : null;
    }
}
