<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $openPrompt = Http::recorded()[0][0]->data()['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('open doors: 12', $openPrompt);

        Http::fake([
            '*' => Http::response(['message' => ['content' => 'ok']], 200),
        ]);

        $thac0 = $this->postJson('/oracle/ask', [
            'messages' => [['role' => 'user', 'content' => 'fighter THAC0 at level 5']],
        ]);
        $thac0->assertOk();
        $thac0Prompt = Http::recorded()[0][0]->data()['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('Fighter 5 THAC0: 16', $thac0Prompt);
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
