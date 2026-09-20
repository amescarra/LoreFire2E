<?php

namespace App\Support;

use App\Models\CampaignVoiceprint;
use Illuminate\Support\Collection;

/**
 * Cosine-similarity identity matching for unstable SPEAKER_N labels.
 *
 * pyannote assigns fresh SPEAKER_00 / SPEAKER_01 ids every recording.
 * Campaign voiceprints store durable embeddings; this class maps a new
 * session's labels onto those identities when confidence is high.
 *
 * Elayas and Dungeon Master stay distinct rows even when they are the
 * same physical person — 1:1 assignment never collapses two voiceprints.
 */
class VoiceprintMatcher
{
    public const DEFAULT_THRESHOLD = 0.70;

    public const DEFAULT_MARGIN = 0.05;

    /**
     * @param  array<string, list<float>>  $speakerEmbeddings  SPEAKER_N → vector
     * @param  Collection<int, CampaignVoiceprint>  $voiceprints
     * @return array<string, array{voiceprint: CampaignVoiceprint, score: float}>
     */
    public function assign(
        array $speakerEmbeddings,
        Collection $voiceprints,
        float $threshold = self::DEFAULT_THRESHOLD,
        float $margin = self::DEFAULT_MARGIN,
    ): array {
        $candidates = [];

        foreach ($speakerEmbeddings as $label => $vector) {
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            foreach ($voiceprints as $voiceprint) {
                if (! $voiceprint->hasEmbedding()) {
                    continue;
                }
                $score = self::cosine($vector, $voiceprint->embedding ?? []);
                if ($score < $threshold) {
                    continue;
                }
                $candidates[] = [
                    'label' => (string) $label,
                    'voiceprint' => $voiceprint,
                    'score' => $score,
                ];
            }
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        $usedLabels = [];
        $usedVoiceprints = [];
        $assigned = [];

        foreach ($candidates as $row) {
            $label = $row['label'];
            $id = $row['voiceprint']->id;
            if (isset($usedLabels[$label]) || isset($usedVoiceprints[$id])) {
                continue;
            }

            $secondBest = 0.0;
            foreach ($candidates as $other) {
                if ($other['label'] !== $label) {
                    continue;
                }
                if ($other['voiceprint']->id === $id) {
                    continue;
                }
                $secondBest = max($secondBest, $other['score']);
            }
            if ($secondBest > 0 && ($row['score'] - $secondBest) < $margin) {
                continue;
            }

            $usedLabels[$label] = true;
            $usedVoiceprints[$id] = true;
            $assigned[$label] = [
                'voiceprint' => $row['voiceprint'],
                'score' => $row['score'],
            ];
        }

        return $assigned;
    }

    /**
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
