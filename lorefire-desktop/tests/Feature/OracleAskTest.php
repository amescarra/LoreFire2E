<?php

namespace Tests\Feature;

use App\Jobs\AskOracle;
use App\Models\AppSetting;
use App\Models\Character;
use App\Models\OracleReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OracleAskTest extends TestCase
{
    use RefreshDatabase;

    public function test_ask_without_llm_returns_422(): void
    {
        AppSetting::set('llm_provider', 'none');

        $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'What is the missile adjustment for DEX 17?']],
        ])->assertStatus(422)
            ->assertJsonPath('error', 'No LLM provider configured. Set one in Settings.');
    }

    public function test_ask_rule_query_sends_engine_numbers_to_provider(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();

            return Http::response([
                'message' => [
                    'content' => 'Dexterity 17 missile adjustment is +2 from the Lorefire 2E engine.',
                ],
            ]);
        });

        $response = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'What is the missile adjustment for DEX 17?']],
        ]);

        $response->assertOk()->assertJsonStructure(['reply_id']);
        $this->assertIsArray($captured);
        $system = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('## Engine lookup', $system);
        $this->assertStringContainsString('missile: +2', $system);
        $this->assertStringContainsString('Adnd2e::dexterityAdjustments', $system);

        $this->getJson('/oracle/replies/'.$response->json('reply_id'))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('reply', 'Dexterity 17 missile adjustment is +2 from the Lorefire 2E engine.');
    }

    public function test_ask_open_doors_and_fighter_thac0_are_engine_backed(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        Http::fake([
            '*' => Http::response(['message' => ['content' => 'ok']], 200),
        ]);

        $open = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'STR 18/01 open doors']],
        ]);
        $open->assertOk();
        $openPrompt = '';
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/api/chat')) {
                $openPrompt = $request->data()['messages'][0]['content'] ?? '';
                break;
            }
        }
        $this->assertStringContainsString('open doors: 12', $openPrompt);

        Http::fake();

        $thac0 = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'fighter THAC0 at level 5']],
        ]);
        $thac0->assertOk();
        $this->getJson('/oracle/replies/'.$thac0->json('reply_id'))
            ->assertOk()
            ->assertJsonPath('status', 'done');
        $this->assertStringContainsString(
            'Fighter 5 THAC0: 16',
            (string) $this->getJson('/oracle/replies/'.$thac0->json('reply_id'))->json('reply')
        );
        Http::assertNothingSent();
    }

    public function test_thac0_questions_answer_from_engine_without_llm(): void
    {
        AppSetting::set('llm_provider', 'none');
        Http::fake();

        $cases = [
            ['what is THAC0 for a 10th level fighter', 'Fighter 10 THAC0: 11'],
            ['fighter 11 thac0', 'Fighter 11 THAC0: 10'],
            ['cleric 4 thac0', 'Cleric 4 THAC0: 18'],
            ['wizard 6 thac0', 'Mage 6 THAC0: 19'],
            ['rogue 5 thac0', 'Thief 5 THAC0: 19'],
        ];

        foreach ($cases as [$question, $expect]) {
            $response = $this->postJson('/oracle/ask', [
                'messages' => [['role' => 'user', 'content' => $question]],
            ]);
            $response->assertOk()->assertJsonStructure(['reply_id']);

            $status = $this->getJson('/oracle/replies/'.$response->json('reply_id'))
                ->assertOk()
                ->assertJsonPath('status', 'done')
                ->json('reply');

            $this->assertIsString($status);
            $this->assertStringContainsString($expect, $status, $question);
            $this->assertStringNotContainsString('THAC0 of 9', $status, $question);
            $this->assertStringNotContainsString('THAC0 of 5', $status, $question);
        }

        Http::assertNothingSent();
    }

    public function test_thac0_skips_llm_even_when_provider_is_configured(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        Http::fake([
            '*' => Http::response(['message' => ['content' => 'Level 11 Fighter has a THAC0 of 9']], 200),
        ]);

        $response = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'fighter 11 thac0']],
        ]);
        $response->assertOk();

        $reply = $this->getJson('/oracle/replies/'.$response->json('reply_id'))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->json('reply');

        $this->assertStringContainsString('Fighter 11 THAC0: 10', (string) $reply);
        $this->assertStringNotContainsString('THAC0 of 9', (string) $reply);
        Http::assertNothingSent();
    }

    public function test_ollama_missing_model_lists_available_names(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3.1:8b');

        Http::fake([
            'http://localhost:11434/api/tags' => Http::response([
                'models' => [['name' => 'llama3.1:latest']],
            ], 200),
            'http://localhost:11434/api/chat' => Http::response([
                'error' => "model 'llama3.1:8b' not found",
            ], 404),
        ]);

        $response = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'Summarize my most recent session.']],
        ]);
        $response->assertOk();

        $reply = $this->getJson('/oracle/replies/'.$response->json('reply_id'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->json('reply');

        $this->assertIsString($reply);
        $this->assertStringContainsString('llama3.1:8b', $reply);
        $this->assertStringContainsString('not found', $reply);
        $this->assertStringContainsString('llama3.1:latest', $reply);
        $this->assertStringContainsString('Settings', $reply);
    }

    public function test_ollama_http_failure_stores_visible_error(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        Http::fake([
            '*' => Http::response('internal error', 500),
        ]);

        $response = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'Summarize my most recent session.']],
        ]);
        $response->assertOk();

        $status = $this->getJson('/oracle/replies/'.$response->json('reply_id'))
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $reply = $status->json('reply');
        $this->assertIsString($reply);
        $this->assertNotSame('', $reply);
        $this->assertStringContainsString('Ollama', $reply);
        $this->assertStringContainsString('HTTP 500', $reply);
    }

    public function test_ollama_connection_exception_stores_visible_error(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to localhost port 11434');
        });

        $response = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'Summarize my most recent session.']],
        ]);
        $response->assertOk();

        $reply = $this->getJson('/oracle/replies/'.$response->json('reply_id'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->json('reply');

        $this->assertIsString($reply);
        $this->assertStringContainsString('Oracle request failed', $reply);
        $this->assertStringContainsString('cURL error 7', $reply);
    }

    public function test_ask_oracle_failed_handler_writes_visible_error(): void
    {
        $row = OracleReply::create(['status' => 'pending']);
        $job = new AskOracle($row, 'sys', [['role' => 'user', 'content' => 'hi']]);
        $job->failed(new \RuntimeException('cURL error 7: Failed to connect to localhost port 11434'));

        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->reply);
        $this->assertStringContainsString('Oracle request failed', $row->reply);
        $this->assertStringContainsString('cURL error 7', $row->reply);
    }

    public function test_ask_material_inspect_uses_sheet_not_phb(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        $character = Character::factory()->create(['name' => 'Hexer', 'class' => 'Mage']);
        $character->spells()->create([
            'name' => 'Fireball',
            'level' => 3,
            'components' => 'V, S, M (a pinch of sulfur)',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);
        $character->inventoryItems()->create([
            'name' => 'Sulfur',
            'quantity' => 2,
        ]);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();

            return Http::response(['message' => ['content' => 'ok']]);
        });

        $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'Does Hexer have material components for Fireball?']],
            'context' => [
                'campaigns' => [[
                    'name' => 'Test',
                    'characters' => [['name' => 'Hexer', 'class' => 'Mage', 'level' => 5]],
                ]],
            ],
        ])->assertOk();

        $system = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('SpellMaterialComponents', $system);
        $this->assertStringContainsString('sulfur', mb_strtolower($system));
        $this->assertStringContainsString('can supply those sheet requirements', $system);
        $this->assertStringNotContainsStringIgnoringCase('PHB page', $system);
    }

    public function test_oracle_index_renders(): void
    {
        $this->withoutVite()->get('/oracle')->assertOk();
    }
}
