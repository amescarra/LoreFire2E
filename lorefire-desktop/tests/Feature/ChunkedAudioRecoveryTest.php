<?php

namespace Tests\Feature;

use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeLiveAudio;
use App\Models\Campaign;
use App\Support\AppTemp;
use App\Support\ChunkedRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChunkedAudioRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::disk('local')->deleteDirectory('sessions');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('sessions');
        parent::tearDown();
    }

    /**
     * @return array{0: Campaign, 1: \App\Models\GameSession}
     */
    protected function liveSession(): array
    {
        $campaign = Campaign::factory()->create(['name' => 'Dragon Lava Cave']);
        $session = $campaign->gameSessions()->create([
            'title' => "Cont. Dragon's Lava Cave",
        ]);

        return [$campaign, $session];
    }

    public function test_init_archives_prior_chunk_folder_instead_of_orphaning_it(): void
    {
        [, $session] = $this->liveSession();
        $orphanId = '11111111-2222-3333-4444-555555555555';
        Storage::disk('local')->put("sessions/{$session->id}/chunks/{$orphanId}/000000.part", 'CHUNK-A');
        Storage::disk('local')->put("sessions/{$session->id}/chunks/{$orphanId}/000001.part", 'CHUNK-B');

        $init = $this->postJson(route('sessions.record.init', $session));
        $init->assertOk();
        $this->assertNotSame($orphanId, $init->json('upload_id'));
        $this->assertFalse(Storage::disk('local')->exists("sessions/{$session->id}/chunks/{$orphanId}"));

        $recovered = $init->json('recovered');
        $this->assertIsArray($recovered);
        $this->assertNotEmpty($recovered);
        $path = $recovered[0]['path'];
        $this->assertStringContainsString('/recovered/', $path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame('CHUNK-ACHUNK-B', Storage::disk('local')->get($path));

        $takes = $this->getJson(route('sessions.record.takes', $session))
            ->assertOk()
            ->json('takes');
        $this->assertNotEmpty($takes);
        $this->assertSame('file', $takes[0]['kind']);
    }

    public function test_finalize_assembles_existing_chunk_dir_after_restart_even_if_count_mismatches(): void
    {
        Bus::fake();
        [, $session] = $this->liveSession();
        $uploadId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        Storage::disk('local')->put("sessions/{$session->id}/chunks/{$uploadId}/000000.part", 'ONE');
        Storage::disk('local')->put("sessions/{$session->id}/chunks/{$uploadId}/000001.part", 'TWO');

        $this->postJson(route('sessions.record.finalize', $session), [
            'upload_id' => $uploadId,
            'total_chunks' => 99,
            'mime_type' => 'audio/webm',
        ])->assertOk()->assertJsonPath('audio_path', "sessions/{$session->id}/audio.webm");

        $this->assertSame('ONETWO', Storage::disk('local')->get("sessions/{$session->id}/audio.webm"));
        $this->assertFalse(Storage::disk('local')->exists("sessions/{$session->id}/chunks/{$uploadId}"));
        $this->assertSame("sessions/{$session->id}/audio.webm", $session->fresh()->audio_path);
        Bus::assertDispatched(TranscribeAudio::class);
    }

    public function test_recover_promotes_orphaned_chunks_without_manual_concat(): void
    {
        Bus::fake();
        [, $session] = $this->liveSession();
        $uploadId = '99999999-aaaa-bbbb-cccc-dddddddddddd';
        Storage::disk('local')->put("sessions/{$session->id}/chunks/{$uploadId}/000000.part", 'KEEP');

        $this->postJson(route('sessions.record.recover', $session), [
            'upload_id' => $uploadId,
            'transcribe' => true,
        ])->assertOk()->assertJsonPath('audio_path', "sessions/{$session->id}/audio.webm");

        $this->assertSame('KEEP', Storage::disk('local')->get("sessions/{$session->id}/audio.webm"));
        Bus::assertDispatched(TranscribeAudio::class);
    }

    public function test_recover_imports_an_assembled_recovered_file(): void
    {
        Bus::fake();
        [, $session] = $this->liveSession();
        $path = "sessions/{$session->id}/recovered/20260920-120000-old.webm";
        Storage::disk('local')->put($path, 'RECOVERED-WAV');

        $this->postJson(route('sessions.record.recover', $session), [
            'path' => $path,
        ])->assertOk()->assertJsonPath('audio_path', $path);

        $this->assertSame($path, $session->fresh()->audio_path);
        Bus::assertDispatched(TranscribeAudio::class);
    }

    public function test_chunk_write_failure_returns_507_and_does_not_dispatch_live_job(): void
    {
        Bus::fake();
        [, $session] = $this->liveSession();
        $init = $this->postJson(route('sessions.record.init', $session))->assertOk();
        $uploadId = $init->json('upload_id');

        Storage::disk('local')->deleteDirectory("sessions/{$session->id}/chunks/{$uploadId}");
        Storage::disk('local')->put("sessions/{$session->id}/chunks/{$uploadId}", 'not-a-directory');

        $this->postJson(route('sessions.record.chunk', $session), [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'chunk' => UploadedFile::fake()->create('chunk-0.part', 12, 'audio/webm'),
        ])->assertStatus(507)->assertJsonPath('stored', false);

        Bus::assertNotDispatched(TranscribeLiveAudio::class);
    }

    public function test_successful_chunk_still_dispatches_live_transcription(): void
    {
        Bus::fake();
        [, $session] = $this->liveSession();
        $init = $this->postJson(route('sessions.record.init', $session))->assertOk();

        $this->postJson(route('sessions.record.chunk', $session), [
            'upload_id' => $init->json('upload_id'),
            'chunk_index' => 0,
            'chunk' => UploadedFile::fake()->create('chunk-0.part', 12, 'audio/webm'),
        ])->assertOk();

        Bus::assertDispatched(TranscribeLiveAudio::class);
    }

    public function test_list_takes_includes_leftover_chunk_uuid_folders(): void
    {
        [, $session] = $this->liveSession();
        $uploadId = '37437437-0000-0000-0000-000000000374';
        for ($i = 0; $i < 3; $i++) {
            Storage::disk('local')->put(
                sprintf('sessions/%d/chunks/%s/%06d.part', $session->id, $uploadId, $i),
                'x'
            );
        }

        $takes = ChunkedRecording::listTakes($session);
        $this->assertCount(1, $takes);
        $this->assertSame('chunks', $takes[0]['kind']);
        $this->assertSame($uploadId, $takes[0]['upload_id']);
        $this->assertSame(3, $takes[0]['parts']);
        $this->assertSame(30, $takes[0]['estimated_seconds']);
    }

    public function test_app_temp_sweep_removes_stale_ffmpeg_dirs(): void
    {
        $legacy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lorefire_ffmpeg_phpunit_'.uniqid('', true);
        mkdir($legacy, 0755, true);
        file_put_contents($legacy.DIRECTORY_SEPARATOR.'ffmpeg', str_repeat('F', 32));
        touch($legacy, time() - 3600);

        $removed = AppTemp::sweep(maxAgeSeconds: 60);
        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertDirectoryDoesNotExist($legacy);
    }
}
