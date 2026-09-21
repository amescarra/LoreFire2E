<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignVoiceprint;
use App\Models\Character;
use App\Models\SpeakerProfile;
use App\Support\SpeakerClipWindows;
use App\Support\SpeakerSegmentRemapper;
use App\Support\VoiceprintEmbeddingExtractor;
use App\Support\VoiceprintResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SpeakerSegmentRemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::disk('local')->deleteDirectory('sessions');
    }

    protected function tearDown(): void
    {
        VoiceprintEmbeddingExtractor::resetOverride();
        Storage::disk('local')->deleteDirectory('sessions');
        parent::tearDown();
    }

    /**
     * @return array{0: Campaign, 1: \App\Models\GameSession, 2: Character}
     */
    protected function sessionWithMixedLabel(): array
    {
        $campaign = Campaign::factory()->create(['name' => 'Suor Noir', 'dm_name' => 'Shaun']);
        $elayas = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Elyas Oakstaff',
            'player_name' => 'Shaun',
            'class' => 'Mage',
        ]);
        $session = $campaign->gameSessions()->create([
            'title' => 'The Ward Holds',
            'session_number' => 4,
        ]);

        Storage::disk('local')->put("sessions/{$session->id}/audio.wav", 'RIFF');
        Storage::disk('local')->put("sessions/{$session->id}/transcript/transcript.json", json_encode([
            'language' => 'en',
            'segments' => [
                ['start' => 0.5, 'end' => 2.0, 'text' => 'The ward holds.', 'speaker' => 'SPEAKER_00'],
                ['start' => 2.2, 'end' => 4.0, 'text' => 'I am Elayas.', 'speaker' => 'SPEAKER_00'],
                ['start' => 4.4, 'end' => 6.0, 'text' => 'Roll initiative.', 'speaker' => 'SPEAKER_00'],
                ['start' => 8.0, 'end' => 10.0, 'text' => 'Stay behind me.', 'speaker' => 'SPEAKER_01'],
            ],
        ]));
        $session->update([
            'audio_path' => "sessions/{$session->id}/audio.wav",
            'transcript_path' => "sessions/{$session->id}/transcript/transcript.json",
            'transcription_status' => 'done',
        ]);

        return [$campaign, $session->fresh(), $elayas];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rawSegments($session): array
    {
        $decoded = json_decode((string) Storage::disk('local')->get($session->transcript_path), true);

        return $decoded['segments'];
    }

    public function test_split_moves_selected_lines_to_a_new_unlabeled_speaker(): void
    {
        [, $session] = $this->sessionWithMixedLabel();

        $this->from("/campaigns/{$session->campaign_id}/sessions/{$session->id}")
            ->post("/sessions/{$session->id}/speakers/remap", [
                'segment_indexes' => [1, 2],
                'create_new_label' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame('SPEAKER_00', $segments[0]['speaker']);
        $this->assertSame('SPEAKER_02', $segments[1]['speaker']);
        $this->assertSame('SPEAKER_02', $segments[2]['speaker']);
        $this->assertSame('SPEAKER_01', $segments[3]['speaker']);
        $this->assertSame('SPEAKER_00', $segments[1]['speaker_diarized']);
        $this->assertSame('SPEAKER_00', $segments[2]['speaker_diarized']);
        $this->assertArrayNotHasKey('speaker_diarized', $segments[0]);
        $this->assertSame(0, SpeakerProfile::query()->where('game_session_id', $session->id)->count());

        $this->assertCount(1, SpeakerClipWindows::forLabel($segments, 'SPEAKER_00'));
        $this->assertSame(0.5, SpeakerClipWindows::forLabel($segments, 'SPEAKER_00')[0]['start']);
        $this->assertCount(2, SpeakerClipWindows::forLabel($segments, 'SPEAKER_02'));
    }

    public function test_assign_after_split_does_not_name_the_moved_lines(): void
    {
        [$campaign, $session, $elayas] = $this->sessionWithMixedLabel();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [2],
            'create_new_label' => 1,
        ])->assertRedirect();

        $this->post("/sessions/{$session->id}/speakers", [
            'speaker_label' => 'SPEAKER_00',
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'is_dm' => 0,
            'save_to_campaign' => 0,
        ])->assertRedirect();

        $this->get("/campaigns/{$campaign->id}/sessions/{$session->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sessions/Show')
                ->where('transcriptSegments.0.speaker', 'Elayas')
                ->where('transcriptSegments.0.speaker_label', 'SPEAKER_00')
                ->where('transcriptSegments.1.speaker', 'Elayas')
                ->where('transcriptSegments.1.speaker_label', 'SPEAKER_00')
                ->where('transcriptSegments.2.speaker', 'SPEAKER_02')
                ->where('transcriptSegments.2.speaker_label', 'SPEAKER_02')
                ->where('transcriptSegments.2.speaker_diarized', 'SPEAKER_00')
                ->where('transcriptSegments.2.segment_index', 2)
            );
    }

    public function test_remap_to_existing_named_profile(): void
    {
        [$campaign, $session] = $this->sessionWithMixedLabel();

        $this->post("/sessions/{$session->id}/speakers", [
            'speaker_label' => 'SPEAKER_01',
            'display_name' => 'Dungeon Master',
            'is_dm' => 1,
            'save_to_campaign' => 0,
        ])->assertRedirect();

        $profile = SpeakerProfile::query()
            ->where('game_session_id', $session->id)
            ->where('speaker_label', 'SPEAKER_01')
            ->firstOrFail();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [2],
            'speaker_profile_id' => $profile->id,
        ])->assertRedirect();

        $this->get("/campaigns/{$campaign->id}/sessions/{$session->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sessions/Show')
                ->where('transcriptSegments.2.speaker', 'Dungeon Master')
                ->where('transcriptSegments.2.speaker_label', 'SPEAKER_01')
                ->where('transcriptSegments.2.speaker_is_dm', true)
                ->where('transcriptSegments.0.speaker_label', 'SPEAKER_00')
            );
    }

    public function test_named_split_creates_profile_only_for_the_new_label(): void
    {
        [, $session, $elayas] = $this->sessionWithMixedLabel();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [1],
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'is_dm' => 0,
        ])->assertRedirect();

        $this->assertNull(
            SpeakerProfile::query()
                ->where('game_session_id', $session->id)
                ->where('speaker_label', 'SPEAKER_00')
                ->first()
        );

        $moved = SpeakerProfile::query()
            ->where('game_session_id', $session->id)
            ->where('speaker_label', 'SPEAKER_02')
            ->firstOrFail();
        $this->assertSame('Elayas', $moved->display_name);
        $this->assertSame($elayas->id, $moved->character_id);
        $this->assertSame('manual', $moved->match_source);

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame('SPEAKER_00', $segments[0]['speaker']);
        $this->assertSame('SPEAKER_02', $segments[1]['speaker']);
        $this->assertSame('SPEAKER_00', $segments[2]['speaker']);
    }

    public function test_naming_an_already_split_line_reuses_that_spare_label(): void
    {
        [, $session, $elayas] = $this->sessionWithMixedLabel();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [1, 2],
            'create_new_label' => 1,
        ])->assertRedirect();

        $this->assertSame('SPEAKER_02', $this->rawSegments($session->fresh())[1]['speaker']);

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [1, 2],
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'is_dm' => 0,
            'create_new_label' => 1,
        ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Named SPEAKER_02 as Elayas.');

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame('SPEAKER_00', $segments[0]['speaker']);
        $this->assertSame('SPEAKER_02', $segments[1]['speaker']);
        $this->assertSame('SPEAKER_02', $segments[2]['speaker']);
        $this->assertSame('SPEAKER_01', $segments[3]['speaker']);
        $this->assertSame('SPEAKER_00', $segments[1]['speaker_diarized']);
        $this->assertSame('SPEAKER_00', $segments[2]['speaker_diarized']);

        $this->assertNull(
            SpeakerProfile::query()
                ->where('game_session_id', $session->id)
                ->where('speaker_label', 'SPEAKER_03')
                ->first()
        );

        $named = SpeakerProfile::query()
            ->where('game_session_id', $session->id)
            ->where('speaker_label', 'SPEAKER_02')
            ->firstOrFail();
        $this->assertSame('Elayas', $named->display_name);
        $this->assertSame($elayas->id, $named->character_id);
        $this->assertSame(1, SpeakerProfile::query()->where('game_session_id', $session->id)->count());
    }

    public function test_naming_a_subset_of_a_split_label_still_allocates_a_spare(): void
    {
        [, $session, $elayas] = $this->sessionWithMixedLabel();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [1, 2],
            'create_new_label' => 1,
        ])->assertRedirect();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [2],
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
        ])->assertRedirect();

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame('SPEAKER_02', $segments[1]['speaker']);
        $this->assertSame('SPEAKER_03', $segments[2]['speaker']);
        $this->assertSame('SPEAKER_00', $segments[2]['speaker_diarized']);

        $this->assertNull(
            SpeakerProfile::query()
                ->where('game_session_id', $session->id)
                ->where('speaker_label', 'SPEAKER_02')
                ->first()
        );
        $this->assertSame(
            'Elayas',
            SpeakerProfile::query()
                ->where('game_session_id', $session->id)
                ->where('speaker_label', 'SPEAKER_03')
                ->value('display_name')
        );
    }

    public function test_naming_owned_spare_via_voiceprint_stays_on_that_label(): void
    {
        [$campaign, $session, $elayas] = $this->sessionWithMixedLabel();

        $voiceprint = CampaignVoiceprint::factory()->withEmbedding([1, 0, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
        ]);

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [1],
            'create_new_label' => 1,
        ])->assertRedirect();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [1],
            'campaign_voiceprint_id' => $voiceprint->id,
        ])->assertRedirect();

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame('SPEAKER_02', $segments[1]['speaker']);
        $this->assertSame('SPEAKER_00', $segments[0]['speaker']);
        $this->assertSame(
            $voiceprint->id,
            SpeakerProfile::query()
                ->where('game_session_id', $session->id)
                ->where('speaker_label', 'SPEAKER_02')
                ->value('campaign_voiceprint_id')
        );
        $this->assertNull(
            SpeakerProfile::query()
                ->where('game_session_id', $session->id)
                ->where('speaker_label', 'SPEAKER_03')
                ->first()
        );
    }

    public function test_remap_to_campaign_voiceprint_reuses_existing_session_label(): void
    {
        [$campaign, $session, $elayas] = $this->sessionWithMixedLabel();

        $voiceprint = CampaignVoiceprint::factory()->withEmbedding([1, 0, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
        ]);

        $session->speakerProfiles()->create([
            'campaign_id' => $campaign->id,
            'speaker_label' => 'SPEAKER_01',
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
            'campaign_voiceprint_id' => $voiceprint->id,
            'match_source' => 'auto',
        ]);

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [0],
            'campaign_voiceprint_id' => $voiceprint->id,
        ])->assertRedirect();

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame('SPEAKER_01', $segments[0]['speaker']);
        $this->assertSame(1, SpeakerProfile::query()->where('game_session_id', $session->id)->count());
    }

    public function test_voiceprint_auto_match_still_does_not_rewrite_transcript_labels(): void
    {
        [$campaign, $session, $elayas] = $this->sessionWithMixedLabel();

        CampaignVoiceprint::factory()->withEmbedding([1, 0, 0])->create([
            'campaign_id' => $campaign->id,
            'display_name' => 'Elayas',
            'character_id' => $elayas->id,
        ]);

        VoiceprintEmbeddingExtractor::$extractOverride = fn () => [
            'speakers' => [
                'SPEAKER_00' => [0.99, 0.01, 0.0],
                'SPEAKER_01' => [0.01, 0.99, 0.0],
            ],
        ];

        $before = $this->rawSegments($session);
        app(VoiceprintResolver::class)->applyToSession($session->fresh());
        $after = $this->rawSegments($session->fresh());

        $this->assertSame($before, $after);
        $this->assertSame('SPEAKER_00', $after[0]['speaker']);
        $this->assertSame('Elayas', SpeakerProfile::query()
            ->where('game_session_id', $session->id)
            ->where('speaker_label', 'SPEAKER_00')
            ->value('display_name'));
    }

    public function test_remap_rejects_empty_selection_and_unknown_indexes(): void
    {
        [, $session] = $this->sessionWithMixedLabel();

        $this->from("/campaigns/{$session->campaign_id}/sessions/{$session->id}")
            ->post("/sessions/{$session->id}/speakers/remap", [
                'segment_indexes' => [99],
                'create_new_label' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'create_new_label' => 1,
        ])->assertSessionHasErrors('segment_indexes');

        $this->assertSame('SPEAKER_00', $this->rawSegments($session->fresh())[0]['speaker']);
    }

    public function test_clip_route_excludes_lines_moved_off_the_label(): void
    {
        [, $session] = $this->sessionWithMixedLabel();

        $this->post("/sessions/{$session->id}/speakers/remap", [
            'segment_indexes' => [0, 1, 2],
            'create_new_label' => 1,
        ])->assertRedirect();

        $this->get("/sessions/{$session->id}/speakers/SPEAKER_00/clip")
            ->assertNotFound()
            ->assertJsonFragment(['error' => 'No timed speech found for this speaker label.']);

        $segments = $this->rawSegments($session->fresh());
        $this->assertSame([], SpeakerClipWindows::forLabel($segments, 'SPEAKER_00'));
        $this->assertCount(3, SpeakerClipWindows::forLabel($segments, 'SPEAKER_02'));
    }

    public function test_next_label_skips_existing_numbers(): void
    {
        [, $session] = $this->sessionWithMixedLabel();
        $remapper = app(SpeakerSegmentRemapper::class);

        $this->assertSame('SPEAKER_02', $remapper->nextLabel($this->rawSegments($session), $session));
    }
}
