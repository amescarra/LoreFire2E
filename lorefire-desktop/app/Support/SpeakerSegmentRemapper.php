<?php

namespace App\Support;

use App\Models\CampaignVoiceprint;
use App\Models\Character;
use App\Models\GameSession;
use App\Models\SpeakerProfile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Move selected transcript segments onto another SPEAKER_N without
 * assigning a name to every line that still shares the old label.
 *
 * VoiceprintResolver auto-match stays label-wide and does not rewrite
 * SPEAKER_N. This path is the explicit user edit after diarization mixes
 * people under one label: rewrite `speaker` on the chosen rows, keep the
 * original in `speaker_diarized`, and optionally attach a profile to the
 * destination label only. Naming a selection that already exclusively
 * owns a spare SPEAKER_N reuses that label instead of minting another.
 */
class SpeakerSegmentRemapper
{
    /**
     * @param  list<int>  $segmentIndexes
     * @param  array<string, mixed>  $options
     * @return array{target_label: string, remapped: int, profile: ?SpeakerProfile, created_label: bool}
     */
    public function remap(GameSession $session, array $segmentIndexes, array $options = []): array
    {
        $indexes = $this->normalizeIndexes($segmentIndexes);
        if ($indexes === []) {
            throw new InvalidArgumentException('Select at least one transcript line.');
        }

        [$decoded, $segments] = $this->loadTranscript($session);

        foreach ($indexes as $index) {
            if (! array_key_exists($index, $segments)) {
                throw new InvalidArgumentException('One of the selected lines is no longer in this transcript.');
            }
        }

        $createdLabel = false;
        $targetLabel = $this->resolveTargetLabel($session, $segments, $indexes, $options, $createdLabel);
        if (! SpeakerClipWindows::isValidLabel($targetLabel)) {
            throw new InvalidArgumentException('Destination speaker label is invalid.');
        }

        $remapped = 0;
        foreach ($indexes as $index) {
            $seg = $segments[$index];
            if (! is_array($seg)) {
                continue;
            }
            $current = $this->currentLabel($seg);
            if ($current === $targetLabel) {
                continue;
            }
            if ($current !== null && ! isset($seg['speaker_diarized'])) {
                $seg['speaker_diarized'] = $current;
            }
            $seg['speaker'] = $targetLabel;
            if (isset($seg['speaker_label'])) {
                $seg['speaker_label'] = $targetLabel;
            }
            $segments[$index] = $seg;
            $remapped++;
        }

        $decoded['segments'] = array_values($segments);
        $this->writeTranscript($session, $decoded);

        $profile = $this->maybePersistProfile($session, $targetLabel, $options);

        return [
            'target_label' => $targetLabel,
            'remapped' => $remapped,
            'profile' => $profile,
            'created_label' => $createdLabel,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public function nextLabel(array $segments, GameSession $session): string
    {
        $max = -1;
        $consider = function (mixed $label) use (&$max): void {
            if (is_string($label) && preg_match('/^SPEAKER_(\d+)$/', $label, $match)) {
                $max = max($max, (int) $match[1]);
            }
        };

        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $consider($seg['speaker'] ?? null);
            $consider($seg['speaker_label'] ?? null);
            $consider($seg['speaker_diarized'] ?? null);
        }

        foreach ($session->speakerProfiles()->pluck('speaker_label') as $label) {
            $consider($label);
        }

        return sprintf('SPEAKER_%02d', $max + 1);
    }

    /**
     * @param  list<int|string>  $indexes
     * @return list<int>
     */
    protected function normalizeIndexes(array $indexes): array
    {
        $normalized = [];
        foreach ($indexes as $index) {
            if (! is_numeric($index)) {
                continue;
            }
            $int = (int) $index;
            if ($int < 0) {
                continue;
            }
            $normalized[$int] = $int;
        }
        $values = array_values($normalized);
        sort($values);

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  list<int>  $indexes
     * @param  array<string, mixed>  $options
     */
    protected function resolveTargetLabel(
        GameSession $session,
        array $segments,
        array $indexes,
        array $options,
        bool &$createdLabel,
    ): string {
        if (! empty($options['speaker_profile_id'])) {
            $profile = $session->speakerProfiles()->whereKey($options['speaker_profile_id'])->first();
            if (! $profile) {
                throw new InvalidArgumentException('That session voice was not found.');
            }

            return $profile->speaker_label;
        }

        $requestedLabel = isset($options['speaker_label']) ? trim((string) $options['speaker_label']) : '';
        if ($requestedLabel !== '') {
            if (! SpeakerClipWindows::isValidLabel($requestedLabel)) {
                throw new InvalidArgumentException('Destination speaker label is invalid.');
            }

            return $requestedLabel;
        }

        $voiceprintId = $options['campaign_voiceprint_id'] ?? null;
        $forceNew = ! empty($options['create_new_label']);

        if ($voiceprintId && ! $forceNew) {
            $existing = $session->speakerProfiles()
                ->where('campaign_voiceprint_id', $voiceprintId)
                ->first();
            if ($existing) {
                return $existing->speaker_label;
            }
        }

        if ($forceNew || $this->wantsNamedDestination($options) || $voiceprintId) {
            $owned = $this->labelOwnedBySelection($segments, $indexes);
            $attachingIdentity = $this->wantsNamedDestination($options) || (bool) $voiceprintId;
            // Unlabeled split still mints a spare even when the selection
            // already owns the current label. Naming that spare reuses it.
            if ($owned !== null && $attachingIdentity) {
                return $owned;
            }

            $createdLabel = true;

            return $this->nextLabel($segments, $session);
        }

        throw new InvalidArgumentException('Choose a destination speaker for the selected lines.');
    }

    /**
     * A selection owns a SPEAKER_N when every chosen line already shares
     * that label and no unselected line still uses it — typically a spare
     * minted by an earlier split. Naming attaches a profile to that spare
     * instead of allocating SPEAKER_N+1. An unlabeled split still mints a
     * new label even when the selection already owns the current one.
     *
     * @param  list<array<string, mixed>>  $segments
     * @param  list<int>  $indexes
     */
    protected function labelOwnedBySelection(array $segments, array $indexes): ?string
    {
        $owned = null;
        $selected = array_fill_keys($indexes, true);

        foreach ($indexes as $index) {
            $seg = $segments[$index] ?? null;
            if (! is_array($seg)) {
                return null;
            }
            $current = $this->currentLabel($seg);
            if ($current === null || ! SpeakerClipWindows::isValidLabel($current)) {
                return null;
            }
            if ($owned === null) {
                $owned = $current;
            } elseif ($owned !== $current) {
                return null;
            }
        }

        if ($owned === null) {
            return null;
        }

        foreach ($segments as $index => $seg) {
            if (! is_array($seg) || isset($selected[$index])) {
                continue;
            }
            if ($this->currentLabel($seg) === $owned) {
                return null;
            }
        }

        return $owned;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function wantsNamedDestination(array $options): bool
    {
        if (! empty($options['is_dm'])) {
            return true;
        }
        if (! empty($options['character_id'])) {
            return true;
        }
        $name = trim((string) ($options['display_name'] ?? ''));

        return $name !== '';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function maybePersistProfile(GameSession $session, string $targetLabel, array $options): ?SpeakerProfile
    {
        $voiceprint = null;
        if (! empty($options['campaign_voiceprint_id'])) {
            $voiceprint = CampaignVoiceprint::query()
                ->where('campaign_id', $session->campaign_id)
                ->whereKey($options['campaign_voiceprint_id'])
                ->first();
            if (! $voiceprint) {
                throw new InvalidArgumentException('That campaign voice was not found.');
            }
        }

        $characterId = ! empty($options['is_dm']) ? null : ($options['character_id'] ?? null);
        if ($characterId) {
            $belongs = Character::query()
                ->where('campaign_id', $session->campaign_id)
                ->whereKey($characterId)
                ->exists();
            if (! $belongs) {
                throw new InvalidArgumentException('That character is not in this campaign.');
            }
        }

        $displayName = trim((string) ($options['display_name'] ?? ''));
        if ($displayName === '' && $voiceprint) {
            $displayName = $voiceprint->transcriptLabel();
        }
        if ($displayName === '' && $characterId) {
            $displayName = (string) Character::query()->whereKey($characterId)->value('name');
        }
        if ($displayName === '' && ! empty($options['is_dm'])) {
            $displayName = 'Dungeon Master';
        }

        $hasIdentity = $displayName !== '' || $voiceprint || $characterId || ! empty($options['is_dm']);
        if (! $hasIdentity) {
            return $session->speakerProfiles()->where('speaker_label', $targetLabel)->first();
        }

        $payload = [
            'campaign_id' => $session->campaign_id,
            'display_name' => $displayName !== '' ? $displayName : $targetLabel,
            'character_id' => $voiceprint ? $voiceprint->character_id : $characterId,
            'is_dm' => $voiceprint ? $voiceprint->is_dm : (bool) ($options['is_dm'] ?? false),
            'campaign_voiceprint_id' => $voiceprint?->id,
            'match_source' => 'manual',
        ];

        $profile = $session->speakerProfiles()->updateOrCreate(
            ['speaker_label' => $targetLabel],
            $payload,
        );

        if (! empty($options['save_to_campaign']) || ! empty($options['update_voiceprint'])) {
            $embedding = null;
            if (! empty($options['update_voiceprint']) || ! $profile->campaign_voiceprint_id) {
                $extracted = app(VoiceprintEmbeddingExtractor::class)->extractForSession($session);
                $embedding = $extracted[$profile->speaker_label] ?? null;
            }
            app(CampaignVoiceprintPromoter::class)->upsertFromProfile($session, $profile, $embedding);
        }

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $seg
     */
    protected function currentLabel(array $seg): ?string
    {
        foreach (['speaker', 'speaker_label'] as $key) {
            $value = $seg[$key] ?? null;
            if (is_string($value) && SpeakerClipWindows::isValidLabel($value)) {
                return $value;
            }
        }

        return is_string($seg['speaker'] ?? null) ? $seg['speaker'] : null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function loadTranscript(GameSession $session): array
    {
        if (! $session->transcript_path || ! Storage::disk('local')->exists($session->transcript_path)) {
            throw new InvalidArgumentException('This session has no transcript to edit.');
        }

        $decoded = json_decode((string) Storage::disk('local')->get($session->transcript_path), true);
        if (! is_array($decoded) || ! is_array($decoded['segments'] ?? null)) {
            throw new InvalidArgumentException('This session has no transcript to edit.');
        }

        /** @var list<array<string, mixed>> $segments */
        $segments = array_values($decoded['segments']);

        return [$decoded, $segments];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function writeTranscript(GameSession $session, array $decoded): void
    {
        Storage::disk('local')->put(
            $session->transcript_path,
            json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
