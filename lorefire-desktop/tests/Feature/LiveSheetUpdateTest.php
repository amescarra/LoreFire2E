<?php

namespace Tests\Feature;

use App\Jobs\ExtractLiveSheetUpdates;
use App\Jobs\ExtractSessionDetails;
use App\Jobs\TranscribeLiveAudio;
use App\Models\Campaign;
use App\Models\Character;
use App\Support\Adnd2e;
use App\Support\LiveTranscript;
use App\Support\SessionSheetUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LiveSheetUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::disk('local')->deleteDirectory('sessions');
    }

    /**
     * @return array{0: Campaign, 1: \App\Models\GameSession, 2: Character}
     */
    protected function liveTable(): array
    {
        $campaign = Campaign::factory()->create(['name' => 'Empty Table']);
        $character = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Elara',
            'class' => 'Mage',
            'current_hp' => 20,
            'max_hp' => 20,
            'gold' => 40,
            'experience_points' => 1000,
            'memorization' => ['3' => 2],
            'memorization_used' => ['3' => 0],
        ]);
        $character->spells()->create([
            'name' => 'Fireball',
            'level' => 3,
            'times_memorized' => 2,
            'times_cast' => 0,
        ]);
        $session = $campaign->gameSessions()->create([
            'title' => 'Live extract',
            'participant_character_ids' => [$character->id],
        ]);

        return [$campaign, $session, $character->fresh(['spells'])];
    }

    public function test_spoken_cast_burns_one_memorized_copy(): void
    {
        [, $session, $character] = $this->liveTable();

        $this->assertTrue(SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 't1'));

        $spell = $character->spells()->firstOrFail();
        $this->assertSame(1, (int) $spell->times_cast);
        $this->assertSame(1, Adnd2e::remainingMemorized((int) $spell->times_memorized, (int) $spell->times_cast));
        $character->refresh();
        $this->assertSame(1, (int) ($character->memorization_used['3'] ?? 0));
    }

    public function test_same_transcript_line_does_not_burn_twice(): void
    {
        [, $session, $character] = $this->liveTable();

        SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'live-0');
        SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'live-0');

        $spell = $character->spells()->firstOrFail();
        $this->assertSame(1, (int) $spell->times_cast);
    }

    public function test_second_distinct_cast_burns_the_second_copy(): void
    {
        [, $session, $character] = $this->liveTable();

        SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'live-0');
        SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'live-1');

        $spell = $character->spells()->firstOrFail();
        $this->assertSame(2, (int) $spell->times_cast);
        $this->assertSame(0, Adnd2e::remainingMemorized((int) $spell->times_memorized, (int) $spell->times_cast));
    }

    public function test_unknown_spell_is_ignored(): void
    {
        [, $session, $character] = $this->liveTable();

        $this->assertFalse(SessionSheetUpdates::applyFromText($session, 'Elara casts Wish', 't1'));
        $this->assertSame(0, (int) $character->spells()->firstOrFail()->times_cast);
    }

    public function test_unclear_or_hedged_line_is_ignored(): void
    {
        [, $session, $character] = $this->liveTable();

        $this->assertFalse(SessionSheetUpdates::applyFromText($session, 'Elara might cast Fireball later', 't1'));
        $this->assertFalse(SessionSheetUpdates::applyFromText($session, 'How does Fireball work?', 't2'));
        $this->assertSame(0, (int) $character->spells()->firstOrFail()->times_cast);
    }

    public function test_hp_damage_applies_during_play(): void
    {
        [, $session, $character] = $this->liveTable();

        SessionSheetUpdates::applyFromText($session, 'Elara takes 6 damage', 't1');
        $this->assertSame(14, (int) $character->fresh()->current_hp);

        SessionSheetUpdates::applyFromText($session, 'Elara is down to 9 hp', 't2');
        $this->assertSame(9, (int) $character->fresh()->current_hp);
    }

    public function test_gold_xp_and_inventory_names(): void
    {
        [, $session, $character] = $this->liveTable();

        SessionSheetUpdates::applyFromText($session, 'Elara gains 25 gold', 't1');
        SessionSheetUpdates::applyFromText($session, 'Elara is awarded 200 xp', 't2');
        SessionSheetUpdates::applyFromText($session, 'Elara picks up a longsword', 't3');

        $character->refresh();
        $this->assertSame(65, (int) $character->gold);
        $this->assertSame(1200, (int) $character->experience_points);
        $this->assertSame('longsword', $character->inventoryItems()->firstOrFail()->name);
    }

    public function test_spoken_cast_spends_named_material_from_inventory(): void
    {
        [, $session, $character] = $this->liveTable();
        $spell = $character->spells()->firstOrFail();
        $spell->update(['components' => 'V, S, M (sulfur)']);
        $character->inventoryItems()->create([
            'name' => 'Sulfur',
            'quantity' => 2,
        ]);

        $this->assertTrue(SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'mat-1'));

        $this->assertSame(1, (int) $spell->fresh()->times_cast);
        $this->assertSame(1, (int) $character->inventoryItems()->firstOrFail()->quantity);
    }

    public function test_spoken_cast_is_blocked_when_named_material_is_missing(): void
    {
        [, $session, $character] = $this->liveTable();
        $spell = $character->spells()->firstOrFail();
        $spell->update(['components' => 'V, S, M (sulfur)']);

        $this->assertFalse(SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'mat-miss'));

        $this->assertSame(0, (int) $spell->fresh()->times_cast);
        $this->assertSame(0, $character->inventoryItems()->count());
    }

    public function test_spoken_cast_does_not_consume_a_named_focus(): void
    {
        [, $session, $character] = $this->liveTable();
        $spell = $character->spells()->firstOrFail();
        $spell->update(['components' => 'V, S, F (holy symbol)']);
        $character->inventoryItems()->create([
            'name' => 'Holy Symbol',
            'quantity' => 1,
        ]);

        $this->assertTrue(SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'mat-focus'));

        $this->assertSame(1, (int) $spell->fresh()->times_cast);
        $this->assertSame(1, (int) $character->inventoryItems()->firstOrFail()->quantity);
    }

    public function test_overnight_rest_after_spoken_cast_does_not_refund_materials(): void
    {
        [, $session, $character] = $this->liveTable();
        $spell = $character->spells()->firstOrFail();
        $spell->update(['components' => 'V, S, M (sulfur)']);
        $character->inventoryItems()->create([
            'name' => 'Sulfur',
            'quantity' => 1,
        ]);

        SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 'mat-rest-1');
        $this->assertSame(0, $character->inventoryItems()->count());

        SessionSheetUpdates::applyFromText($session, 'Elara takes an overnight rest', 'mat-rest-2');
        $this->assertSame(0, (int) $spell->fresh()->times_cast);
        $this->assertSame(0, $character->inventoryItems()->count());
    }

    public function test_overnight_rest_rememorizes_remaining_copies(): void
    {
        [, $session, $character] = $this->liveTable();

        SessionSheetUpdates::applyFromText($session, 'Elara casts Fireball', 't1');
        $this->assertSame(1, (int) $character->spells()->firstOrFail()->times_cast);

        SessionSheetUpdates::applyFromText($session, 'Elara takes an overnight rest', 't2');
        $spell = $character->spells()->firstOrFail();
        $this->assertSame(0, (int) $spell->times_cast);
        $this->assertSame(2, (int) $spell->times_memorized);
    }

    public function test_live_job_applies_only_new_segments(): void
    {
        [, $session, $character] = $this->liveTable();

        LiveTranscript::appendSegments($session, [
            ['text' => 'Elara casts Fireball', 'start' => 0, 'end' => 2, 'chunk_index' => 0],
        ]);
        (new ExtractLiveSheetUpdates($session))->handle();

        $this->assertSame(1, (int) $character->spells()->firstOrFail()->times_cast);
        $session->refresh();
        $this->assertSame(1, (int) $session->sheet_update_cursor);

        LiveTranscript::appendSegments($session, [
            ['text' => 'Elara casts Fireball', 'start' => 12, 'end' => 14, 'chunk_index' => 1],
        ]);
        (new ExtractLiveSheetUpdates($session))->handle();

        $this->assertSame(2, (int) $character->spells()->firstOrFail()->times_cast);
        $session->refresh();
        $this->assertSame(2, (int) $session->sheet_update_cursor);

        (new ExtractLiveSheetUpdates($session))->handle();
        $this->assertSame(2, (int) $character->spells()->firstOrFail()->times_cast);
    }

    public function test_end_extract_does_not_double_apply_live_lines(): void
    {
        [, $session, $character] = $this->liveTable();

        LiveTranscript::appendSegments($session, [
            ['text' => 'Elara casts Fireball', 'start' => 0, 'end' => 2],
        ]);
        (new ExtractLiveSheetUpdates($session))->handle();
        $this->assertSame(1, (int) $character->spells()->firstOrFail()->times_cast);

        Storage::disk('local')->put("sessions/{$session->id}/transcript/transcript.json", json_encode([
            'segments' => [
                ['start' => 0, 'end' => 2, 'text' => 'Elara casts Fireball'],
                ['start' => 3, 'end' => 5, 'text' => 'The torch sputters.'],
            ],
        ]));
        $session->update(['transcript_path' => "sessions/{$session->id}/transcript/transcript.json"]);

        (new ExtractSessionDetails($session->fresh()))->handle();

        $this->assertSame(1, (int) $character->spells()->firstOrFail()->times_cast);
        $this->assertSame('done', $session->fresh()->extraction_status);
    }

    public function test_recording_chunk_reuses_existing_recorder_job(): void
    {
        Bus::fake();
        [, $session] = $this->liveTable();

        $init = $this->postJson(route('sessions.record.init', $session));
        $init->assertOk();
        $uploadId = $init->json('upload_id');

        $this->postJson(route('sessions.record.chunk', $session), [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'chunk' => \Illuminate\Http\UploadedFile::fake()->create('chunk-0.part', 12, 'audio/webm'),
        ])->assertOk();

        Bus::assertDispatched(TranscribeLiveAudio::class, function (TranscribeLiveAudio $job) use ($session) {
            return $job->session->id === $session->id && $job->chunkIndex === 0;
        });
        Bus::assertNotDispatched(\App\Jobs\TranscribeAudio::class);
    }

    public function test_live_state_reflects_sheet_after_apply(): void
    {
        [$campaign, $session, $character] = $this->liveTable();

        $this->postJson(route('sessions.live-sheet-updates', $session), [
            'text' => 'Elara casts Fireball',
        ])->assertOk()->assertJson(['applied' => true]);

        $this->getJson(route('campaigns.sessions.live-state', [$campaign, $session]))
            ->assertOk()
            ->assertJsonPath('characters.0.spells.0.times_cast', 1)
            ->assertJsonPath('characters.0.spells.0.remaining_memorized', 1);

        $this->assertSame(1, (int) $character->spells()->firstOrFail()->times_cast);
    }

    public function test_oracle_confirmed_write_uses_the_same_apply_path(): void
    {
        [, $session, $character] = $this->liveTable();

        $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'Mark Fireball as cast']],
            'session_id' => $session->id,
            'context' => ['campaigns' => []],
        ])->assertStatus(422);

        $this->assertSame(1, (int) $character->spells()->firstOrFail()->times_cast);
    }
}
