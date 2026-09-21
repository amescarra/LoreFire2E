<?php

namespace Tests\Feature;

use App\Jobs\GenerateArtPrompts;
use App\Models\AppSetting;
use App\Models\Campaign;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateArtPromptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_handler_resets_generating_status(): void
    {
        $session = $this->sessionWithSummary();
        $session->update(['art_prompts_status' => 'generating']);

        $job = new GenerateArtPrompts($session);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(1, $job->tries);

        $job->failed(new \RuntimeException('Ollama timed out'));

        $this->assertSame('failed', $session->fresh()->art_prompts_status);
    }

    public function test_failed_handler_does_not_overwrite_cancelled_or_done(): void
    {
        $cancelled = $this->sessionWithSummary();
        $cancelled->update(['art_prompts_status' => 'cancelled']);
        (new GenerateArtPrompts($cancelled))->failed(new \RuntimeException('timeout'));
        $this->assertSame('cancelled', $cancelled->fresh()->art_prompts_status);

        $done = $this->sessionWithSummary();
        $done->update(['art_prompts_status' => 'done']);
        (new GenerateArtPrompts($done))->failed(new \RuntimeException('timeout'));
        $this->assertSame('done', $done->fresh()->art_prompts_status);
    }

    public function test_handle_exception_resets_art_prompts_status(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $session = $this->sessionWithSummary();
        $session->update(['art_prompts_status' => 'generating']);

        try {
            (new GenerateArtPrompts($session))->handle();
            $this->fail('Expected the Ollama client to throw.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $this->assertSame('failed', $session->fresh()->art_prompts_status);
    }

    public function test_summary_is_preferred_over_transcript(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['prompt'] ?? '';

            return Http::response([
                'response' => '[{"title":"Cave","description":"A briar-screened cave.","characters":[],"prompt":"A cave mouth."}]',
            ]);
        });

        $session = $this->sessionWithSummary('The party reached the briar-screened cave.');
        $path = "sessions/{$session->id}/transcript/transcript.json";
        $session->update(['transcript_path' => $path]);
        Storage::fake();
        Storage::put($path, json_encode([
            'segments' => [
                ['text' => 'UNIQUE_TRANSCRIPT_TOKEN_ZZZ the dragon monologues for hours'],
            ],
        ]));

        (new GenerateArtPrompts($session->fresh()))->handle();

        $this->assertIsString($captured);
        $this->assertStringContainsString('briar-screened cave', $captured);
        $this->assertStringNotContainsString('UNIQUE_TRANSCRIPT_TOKEN_ZZZ', $captured);
        $this->assertSame('done', $session->fresh()->art_prompts_status);
    }

    public function test_transcript_only_input_is_soft_capped(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['prompt'] ?? '';

            return Http::response([
                'response' => '[{"title":"Road","description":"A long road.","characters":[],"prompt":"A long road."}]',
            ]);
        });

        $tail = 'TAIL_MARKER_SHOULD_BE_CUT';
        $text = str_repeat('The goblin horde advanced through the pines. ', 400).$tail;
        $this->assertGreaterThan(GenerateArtPrompts::TRANSCRIPT_SOURCE_CHAR_CAP, mb_strlen($text));

        $campaign = Campaign::factory()->create();
        $session = $campaign->gameSessions()->create([
            'title' => 'Long road',
            'summary' => null,
        ]);
        $path = "sessions/{$session->id}/transcript/transcript.json";
        $session->update(['transcript_path' => $path, 'art_prompts_status' => 'generating']);
        Storage::fake();
        Storage::put($path, json_encode([
            'segments' => [
                ['text' => $text],
            ],
        ]));

        (new GenerateArtPrompts($session->fresh()))->handle();

        $this->assertIsString($captured);
        $this->assertStringContainsString('Transcript truncated for scene extraction', $captured);
        $this->assertStringNotContainsString($tail, $captured);
        $this->assertSame('done', $session->fresh()->art_prompts_status);
    }

    public function test_cancel_clears_generating_and_idle_cancel_succeeds(): void
    {
        config(['queue.default' => 'database']);

        $generating = $this->sessionWithSummary();
        $generating->update(['art_prompts_status' => 'generating']);
        $this->queueArtPromptJob($generating);

        $this->deleteJson(route('sessions.art-prompts.cancel', $generating))
            ->assertOk()
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', $generating->fresh()->art_prompts_status);
        $this->assertSame(0, DB::table('jobs')->count());

        $idle = $this->sessionWithSummary();
        $this->assertSame('idle', $idle->fresh()->art_prompts_status);

        $this->deleteJson(route('sessions.art-prompts.cancel', $idle))
            ->assertOk()
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('status', 'idle');

        $this->assertSame('idle', $idle->fresh()->art_prompts_status);
    }

    public function test_status_endpoint_fails_stale_generating_when_queue_row_is_dead(): void
    {
        config(['queue.default' => 'database']);

        $session = $this->sessionWithSummary();
        $session->update(['art_prompts_status' => 'generating']);
        $this->queueArtPromptJob($session, now()->subSeconds(400)->getTimestamp());

        $this->getJson(route('sessions.art-prompts-status', $session))
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('failed', $session->fresh()->art_prompts_status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_status_endpoint_keeps_generating_while_job_is_queued(): void
    {
        config(['queue.default' => 'database']);

        $session = $this->sessionWithSummary();
        $session->update(['art_prompts_status' => 'generating']);
        $this->queueArtPromptJob($session);

        $this->getJson(route('sessions.art-prompts-status', $session))
            ->assertOk()
            ->assertJsonPath('status', 'generating');

        $this->assertSame('generating', $session->fresh()->art_prompts_status);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_status_endpoint_fails_generating_when_the_job_row_is_gone(): void
    {
        config(['queue.default' => 'database']);

        $session = $this->sessionWithSummary();
        GameSession::whereKey($session->id)->update([
            'art_prompts_status' => 'generating',
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->getJson(route('sessions.art-prompts-status', $session))
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('failed', $session->fresh()->art_prompts_status);
    }

    public function test_status_endpoint_does_not_fail_a_generation_that_just_started(): void
    {
        config(['queue.default' => 'database']);

        $session = $this->sessionWithSummary();
        $session->update(['art_prompts_status' => 'generating']);

        $this->getJson(route('sessions.art-prompts-status', $session))
            ->assertOk()
            ->assertJsonPath('status', 'generating');
    }

    public function test_generate_json_marks_generating_only_when_source_exists(): void
    {
        Bus::fake();
        $session = $this->sessionWithSummary();

        $this->postJson(route('sessions.generate-art-prompts', $session))
            ->assertOk()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('status', 'generating');

        $this->assertSame('generating', $session->fresh()->art_prompts_status);
        Bus::assertDispatched(GenerateArtPrompts::class);

        $empty = Campaign::factory()->create()->gameSessions()->create(['title' => 'Empty']);

        $this->postJson(route('sessions.generate-art-prompts', $empty))
            ->assertStatus(422)
            ->assertJsonPath('error', 'No transcript or summary found.');

        $this->assertNotSame('generating', $empty->fresh()->art_prompts_status);
    }

    public function test_status_endpoint_reports_idle_without_spinning_state(): void
    {
        $session = $this->sessionWithSummary();

        $this->getJson(route('sessions.art-prompts-status', $session))
            ->assertOk()
            ->assertJsonPath('status', 'idle');
    }

    private function sessionWithSummary(string $summary = 'The party held the gate.'): GameSession
    {
        $campaign = Campaign::factory()->create();

        return $campaign->gameSessions()->create([
            'title' => 'Gate',
            'summary' => $summary,
        ]);
    }

    private function queueArtPromptJob(GameSession $session, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => 'art-prompt-'.$session->id,
                'displayName' => GenerateArtPrompts::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => GenerateArtPrompts::class,
                    'command' => serialize(new GenerateArtPrompts($session)),
                ],
            ]),
            'attempts' => $reservedAt ? 1 : 0,
            'reserved_at' => $reservedAt,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }
}
