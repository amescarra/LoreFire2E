<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignVoiceprint;
use App\Models\Character;
use App\Models\SpeakerProfile;
use App\Support\CampaignVoiceprintPromoter;
use App\Support\VoiceprintEmbeddingExtractor;
use App\Support\VoiceprintResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CampaignVoiceprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        VoiceprintEmbeddingExtractor::resetOverride();
        parent::tearDown();
    }

    /**
     * @return array{0: Campaign, 1: Character}
     */
    protected function suorNoir(): array
    {
        $campaign = Campaign::factory()->create(['name' => 'Suor Noir', 'dm_name' => 'Shaun']);
        $elayas = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Elyas Oakstaff',
            'player_name' => 'Shaun',
            'class' => 'Mage',
        ]);

        return [$campaign, $elayas];
    }

    /**
     * @param  list<float>  $vector
     */
    protected function fakeAudioAndTranscript($session, string $label, array $vector): void
    {
        Storage::disk('local')->put("sessions/{$session->id}/audio.wav", 'RIFF');
        Storage::disk('local')->put("sessions/{$session->id}/transcript/transcript.json", json_encode([
            'language' => 'en',
            'segments' => [
                ['start' => 0, 'end' => 2, 'text' => 'The ward holds.', 'speaker' => $label],
            ],
        ]));
        $session->update([
            'audio_path' => "sessions/{$session->id}/audio.wav",
            'transcript_path' => "sessions/{$session->id}/transcript/transcript.json",
            'transcription_status' => 'done',
        ]);
    }

    public function test_saving_a_campaign_voiceprint(): void
    {
        [$campaign, $elayas] = $this->suorNoir();

        VoiceprintEmbeddingExtractor::$extractOverride = fn () => [
            'model' => 'test-embedding',
            'speakers' => ['enrollment' => [1.0, 0.0, 0.0]],
        ];

        $this->from("/campaigns/{$campaign->id}/voices")
            ->post("/campaigns/{$campaign->id}/voiceprints", [
                'display_name' => 'Elayas',
                'character_id' => $elayas->id,
                'is_dm' => 0,
                'audio' => UploadedFile::fake()->create('elayas.wav', 20, 'audio/wav'),
            ])
            ->assertRedirect();

        $voiceprint = CampaignVoiceprint::query()->where('display_name', 'Elayas')->firstOrFail();
        $this->assertSame($campaign->id, $voiceprint->campaign_id);
        $this->assertSame($elayas->id, $voiceprint->character_id);
        $this->assertFalse($voiceprint->is_dm);
        $this->assertTrue($voiceprint->hasEmbedding());
        $this->assertNotNull($voiceprint->enrollment_audio_path);
        $this->assertSame('Elayas', $voiceprint->transcriptLabel());
    }

    public function test_elayas_and_dungeon_master_are_two_profiles_for_shaun(): void
    {
        [$campaign, $elayas] = $this->suorNoir();

        $pc = CampaignVoiceprint::factory()->withEmbedding([1, 0, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'is_dm' => false,
        ]);
        $dm = CampaignVoiceprint::factory()->withEmbedding([0, 1, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Dungeon Master',
            'character_id' => null,
            'is_dm' => true,
        ]);

        $this->assertNotSame($pc->id, $dm->id);
        $this->assertSame($elayas->id, $pc->character_id);
        $this->assertNull($dm->character_id);
        $this->assertSame('Elayas', $pc->transcriptLabel());
        $this->assertSame('Dungeon Master', $dm->transcriptLabel());
        $this->assertSame(2, $campaign->voiceprints()->count());

        $this->get("/campaigns/{$campaign->id}/voices")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaigns/Voices')
                ->has('voiceprints', 2)
                ->where('voiceprints.0.display_name', 'Dungeon Master')
                ->where('voiceprints.1.display_name', 'Elayas')
            );
    }

    public function test_promoting_session_mappings_saves_campaign_voiceprints(): void
    {
        [$campaign, $elayas] = $this->suorNoir();
        $session = $campaign->gameSessions()->create(['title' => 'Enrollment night']);
        $this->fakeAudioAndTranscript($session, 'SPEAKER_00', [1, 0, 0]);

        VoiceprintEmbeddingExtractor::$extractOverride = fn () => [
            'speakers' => [
                'SPEAKER_00' => [1.0, 0.0, 0.0],
                'SPEAKER_01' => [0.0, 1.0, 0.0],
            ],
        ];

        $this->post("/sessions/{$session->id}/speakers", [
            'speaker_label' => 'SPEAKER_00',
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'is_dm' => 0,
            'save_to_campaign' => 0,
        ])->assertRedirect();

        $this->post("/sessions/{$session->id}/speakers", [
            'speaker_label' => 'SPEAKER_01',
            'display_name' => 'Dungeon Master',
            'is_dm' => 1,
            'save_to_campaign' => 0,
        ])->assertRedirect();

        $this->post("/sessions/{$session->id}/speakers/promote")->assertRedirect();

        $this->assertSame(2, $campaign->voiceprints()->count());
        $pc = $campaign->voiceprints()->where('display_name', 'Elayas')->firstOrFail();
        $dm = $campaign->voiceprints()->where('is_dm', true)->firstOrFail();
        $this->assertSame($elayas->id, $pc->character_id);
        $this->assertTrue($pc->hasEmbedding());
        $this->assertTrue($dm->hasEmbedding());
        $this->assertSame('Dungeon Master', $dm->display_name);
    }

    public function test_resolver_applies_saved_voiceprints_to_a_new_session_transcript(): void
    {
        [$campaign, $elayas] = $this->suorNoir();

        CampaignVoiceprint::factory()->withEmbedding([1, 0, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
        ]);
        CampaignVoiceprint::factory()->withEmbedding([0, 1, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Dungeon Master',
            'is_dm' => true,
        ]);

        $session = $campaign->gameSessions()->create(['title' => 'Next game']);
        Storage::disk('local')->put("sessions/{$session->id}/audio.wav", 'RIFF');
        Storage::disk('local')->put("sessions/{$session->id}/transcript/transcript.json", json_encode([
            'language' => 'en',
            'segments' => [
                ['start' => 0, 'end' => 2, 'text' => 'I cast fireball.', 'speaker' => 'SPEAKER_04'],
                ['start' => 3, 'end' => 5, 'text' => 'Roll initiative.', 'speaker' => 'SPEAKER_09'],
            ],
        ]));
        $session->update([
            'audio_path' => "sessions/{$session->id}/audio.wav",
            'transcript_path' => "sessions/{$session->id}/transcript/transcript.json",
        ]);

        VoiceprintEmbeddingExtractor::$extractOverride = fn () => [
            'speakers' => [
                'SPEAKER_04' => [0.97, 0.02, 0.0],
                'SPEAKER_09' => [0.01, 0.98, 0.0],
            ],
        ];

        $assigned = app(VoiceprintResolver::class)->applyToSession($session->fresh());

        $this->assertArrayHasKey('SPEAKER_04', $assigned);
        $this->assertArrayHasKey('SPEAKER_09', $assigned);
        $this->assertSame('Elayas', $assigned['SPEAKER_04']['voiceprint']->display_name);
        $this->assertSame('Dungeon Master', $assigned['SPEAKER_09']['voiceprint']->display_name);

        $pc = SpeakerProfile::query()->where('game_session_id', $session->id)->where('speaker_label', 'SPEAKER_04')->firstOrFail();
        $dm = SpeakerProfile::query()->where('game_session_id', $session->id)->where('speaker_label', 'SPEAKER_09')->firstOrFail();
        $this->assertSame('Elayas', $pc->display_name);
        $this->assertSame($elayas->id, $pc->character_id);
        $this->assertSame('auto', $pc->match_source);
        $this->assertSame('Dungeon Master', $dm->display_name);
        $this->assertTrue($dm->is_dm);
        $this->assertNull($dm->character_id);

        $this->get("/campaigns/{$campaign->id}/sessions/{$session->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sessions/Show')
                ->has('transcriptSegments', 2)
                ->where('transcriptSegments.0.speaker', 'Elayas')
                ->where('transcriptSegments.0.speaker_label', 'SPEAKER_04')
                ->where('transcriptSegments.1.speaker', 'Dungeon Master')
            );
    }

    public function test_live_segments_resolve_fresh_speaker_labels(): void
    {
        [$campaign, $elayas] = $this->suorNoir();
        CampaignVoiceprint::factory()->withEmbedding([1, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
        ]);

        $resolved = app(VoiceprintResolver::class)->resolveSegments($campaign, [
            ['text' => 'I search the door.', 'speaker' => 'SPEAKER_11', 'start' => 0, 'end' => 1],
        ], ['SPEAKER_11' => [0.99, 0.01]]);

        $this->assertSame('Elayas', $resolved[0]['speaker']);
        $this->assertSame('SPEAKER_11', $resolved[0]['speaker_label']);
        $this->assertFalse($resolved[0]['speaker_is_dm']);
    }

    public function test_promoter_keeps_elayas_and_dm_as_separate_rows(): void
    {
        [$campaign, $elayas] = $this->suorNoir();
        $session = $campaign->gameSessions()->create(['title' => 'Table']);

        $pcProfile = $session->speakerProfiles()->create([
            'campaign_id' => $campaign->id,
            'speaker_label' => 'SPEAKER_00',
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'is_dm' => false,
        ]);
        $dmProfile = $session->speakerProfiles()->create([
            'campaign_id' => $campaign->id,
            'speaker_label' => 'SPEAKER_01',
            'display_name' => 'Dungeon Master',
            'is_dm' => true,
        ]);

        $promoter = new CampaignVoiceprintPromoter;
        $pc = $promoter->upsertFromProfile($session, $pcProfile, [1, 0]);
        $dm = $promoter->upsertFromProfile($session, $dmProfile, [0, 1]);

        $this->assertNotSame($pc->id, $dm->id);
        $this->assertFalse($pc->is_dm);
        $this->assertTrue($dm->is_dm);
        $this->assertSame(2, $campaign->voiceprints()->count());
    }
}
