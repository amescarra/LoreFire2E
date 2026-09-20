<?php

namespace Tests\Unit;

use App\Models\Campaign;
use App\Models\CampaignVoiceprint;
use App\Support\VoiceprintMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceprintMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_cosine_of_identical_vectors_is_one(): void
    {
        $this->assertEqualsWithDelta(1.0, VoiceprintMatcher::cosine([1, 0, 0], [2, 0, 0]), 1e-6);
        $this->assertEqualsWithDelta(0.0, VoiceprintMatcher::cosine([1, 0], [0, 1]), 1e-6);
        $this->assertSame(0.0, VoiceprintMatcher::cosine([], [1]));
    }

    public function test_assigns_speakers_one_to_one_without_collapsing_elayas_and_dm(): void
    {
        $campaign = Campaign::factory()->create(['name' => 'Suor Noir']);
        $elayas = CampaignVoiceprint::factory()->withEmbedding([1, 0, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'is_dm' => false,
        ]);
        $dm = CampaignVoiceprint::factory()->withEmbedding([0, 1, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Dungeon Master',
            'is_dm' => true,
        ]);

        $assigned = (new VoiceprintMatcher)->assign([
            'SPEAKER_03' => [0.98, 0.02, 0],
            'SPEAKER_07' => [0.03, 0.97, 0],
        ], collect([$elayas, $dm]));

        $this->assertSame($elayas->id, $assigned['SPEAKER_03']['voiceprint']->id);
        $this->assertSame($dm->id, $assigned['SPEAKER_07']['voiceprint']->id);
        $this->assertCount(2, $assigned);
    }

    public function test_ambiguous_near_tie_is_left_unassigned(): void
    {
        $campaign = Campaign::factory()->create();
        $elayas = CampaignVoiceprint::factory()->withEmbedding([1, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
        ]);
        $dm = CampaignVoiceprint::factory()->withEmbedding([0.98, 0.02])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Dungeon Master',
            'is_dm' => true,
        ]);

        $assigned = (new VoiceprintMatcher)->assign([
            'SPEAKER_00' => [0.99, 0.01],
        ], collect([$elayas, $dm]), 0.70, 0.05);

        $this->assertSame([], $assigned);
    }
}
