<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignVoiceprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignVoiceprint>
 */
class CampaignVoiceprintFactory extends Factory
{
    protected $model = CampaignVoiceprint::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'display_name' => fake()->firstName(),
            'character_id' => null,
            'is_dm' => false,
            'embedding' => null,
            'embedding_model' => null,
            'enrollment_audio_path' => null,
            'enrolled_at' => null,
        ];
    }

    /**
     * @param  list<float>  $embedding
     */
    public function withEmbedding(array $embedding, string $model = 'test-embedding'): static
    {
        return $this->state(fn () => [
            'embedding' => $embedding,
            'embedding_model' => $model,
            'enrolled_at' => now(),
        ]);
    }
}
