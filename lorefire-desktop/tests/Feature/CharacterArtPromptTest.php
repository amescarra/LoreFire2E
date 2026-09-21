<?php

namespace Tests\Feature;

use App\Jobs\Concerns\GeneratesViaComfyUI;
use App\Jobs\GenerateArtPrompts;
use App\Jobs\GeneratePartyPortrait;
use App\Jobs\GeneratePortrait;
use App\Models\AppSetting;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\SceneArtPrompt;
use App\Support\CharacterArtBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class CharacterArtPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipped_items_are_depicted_and_unequipped_magic_sword_is_omitted(): void
    {
        $logain = $this->logain();

        $context = CharacterArtBrief::from($logain->fresh())->contextBlock();

        $this->assertSame(<<<'TEXT'
- Logain — Human, Fighter, level 10
  Appearance: A lean man in his thirties with a thin scar along the left jaw.
  Visible mannerism: Rests one hand on his sword hilt.
  Equipped (depict these only): Chain mail (well-kept rings); Long sword (silvered)
TEXT, $context);
        $this->assertStringNotContainsString('Moonblade of Azul', $context);
        $this->assertStringNotContainsString('glowing blue', $context);
        $this->assertStringNotContainsString('silk rope', $context);
        $this->assertStringNotContainsString('low whisper', $context);

        $portrait = $this->invoke(new GeneratePortrait($logain->fresh()), 'buildPrompt');
        $this->assertStringContainsString('Equipped (depict these only): Chain mail (well-kept rings); Long sword (silvered).', $portrait);
        $this->assertStringContainsString(CharacterArtBrief::NO_INVENT_GEAR, $portrait);
        $this->assertStringContainsString('A lean man in his thirties with a thin scar along the left jaw.', $portrait);
        $this->assertStringNotContainsString('Moonblade of Azul', $portrait);
        $this->assertStringNotContainsString('glowing blue', $portrait);
        $this->assertStringNotContainsString('silk rope', $portrait);

        $party = $this->invoke(
            new GeneratePartyPortrait($logain->campaign),
            'buildPrompt',
            [collect([$logain->fresh()])],
        );
        $this->assertStringContainsString('Equipped (depict these only): Chain mail (well-kept rings); Long sword (silvered).', $party);
        $this->assertStringContainsString(CharacterArtBrief::NO_INVENT_GEAR, $party);
        $this->assertStringNotContainsString('Moonblade of Azul', $party);
        $this->assertStringNotContainsString('glowing blue', $party);
    }

    public function test_empty_appearance_does_not_fabricate_traits(): void
    {
        $sera = Character::factory()->create([
            'name' => 'Sera',
            'race' => 'Elf',
            'class' => 'Mage',
            'level' => 1,
            'appearance_description' => null,
            'mannerisms' => 'Speaks with a gravelly whisper and a coastal accent.',
        ]);

        $brief = CharacterArtBrief::from($sera->fresh());

        $this->assertSame(<<<'TEXT'
- Sera — Elf, Mage, level 1
  Appearance: unrecorded. Depict only the race and class above. Do not invent detailed face, hair, eye, skin, height, or body traits.
  Equipped (depict these only): none
  Clothing: plain undetailed clothing. No weapons, armor, or magic items are equipped.
TEXT, $brief->contextBlock());
        $this->assertNull($brief->appearance);
        $this->assertNull($brief->visualMannerism);
        $this->assertStringNotContainsString('whisper', $brief->contextBlock());
        $this->assertStringNotContainsString('violet', $brief->contextBlock());
        $this->assertStringNotContainsString('silver hair', $brief->contextBlock());

        $portrait = $this->invoke(new GeneratePortrait($sera->fresh()), 'buildPrompt');
        $this->assertStringContainsString('an Elf Mage', $portrait);
        $this->assertStringContainsString('Appearance is not recorded. Depict only the listed race and class.', $portrait);
        $this->assertStringContainsString('Do not invent detailed face, hair, eye, skin, height, or body traits.', $portrait);
        $this->assertStringContainsString('Equipped (depict these only): none.', $portrait);
        $this->assertStringContainsString('Wear plain undetailed clothing only.', $portrait);
        $this->assertStringContainsString(CharacterArtBrief::NO_INVENT_GEAR, $portrait);
        $this->assertStringNotContainsString('detailed face.', $portrait);
        $this->assertStringNotContainsString('whisper', $portrait);
        $this->assertStringNotContainsString('violet eyes', $portrait);
        $this->assertStringNotContainsString('long straight', $portrait);

        $party = $this->invoke(
            new GeneratePartyPortrait($sera->campaign),
            'buildPrompt',
            [collect([$sera->fresh()])],
        );
        $this->assertStringContainsString('Race: Elf', $party);
        $this->assertStringContainsString('Class: Mage', $party);
        $this->assertStringContainsString('Appearance is not recorded.', $party);
        $this->assertStringContainsString('Equipped (depict these only): none.', $party);
        $this->assertStringContainsString(CharacterArtBrief::NO_INVENT_GEAR, $party);
        $this->assertStringNotContainsString('violet', $party);
        $this->assertStringNotContainsString('whisper', $party);
    }

    public function test_art_director_prompt_forbids_inventing_gear(): void
    {
        AppSetting::set('llm_provider', 'ollama');
        AppSetting::set('ollama_base_url', 'http://localhost:11434');
        AppSetting::set('ollama_model', 'llama3');

        $logain = $this->logain();
        $campaign = $logain->campaign;
        Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Sera',
            'race' => 'Elf',
            'class' => 'Mage',
            'level' => 1,
            'appearance_description' => '',
            'mannerisms' => null,
        ]);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['prompt'] ?? '';

            return Http::response([
                'response' => json_encode([[
                    'title' => 'The Gate',
                    'description' => 'Logain held the ruined gate through a long night of rain and torchlight while arrows struck the wall.',
                    'characters' => ['Logain', 'Sera'],
                ]]),
            ]);
        });

        $session = $campaign->gameSessions()->create([
            'title' => 'Gate',
            'summary' => 'Logain held the ruined gate through a long night of rain and torchlight while arrows struck the wall.',
            'art_prompts_status' => 'generating',
        ]);

        (new GenerateArtPrompts($session->fresh()))->handle();

        $this->assertIsString($captured);
        $this->assertStringContainsString('Logain held the ruined gate', $captured);
        $this->assertStringContainsString('Equipped (depict these only): Chain mail (well-kept rings); Long sword (silvered)', $captured);
        $this->assertStringContainsString('Appearance: unrecorded.', $captured);
        $this->assertStringContainsString('Never invent magic items, colors of armor, or weapons that are not listed.', $captured);
        $this->assertStringContainsString('may ONLY come from that character\'s Equipped list', $captured);
        $this->assertStringContainsString('plain undetailed clothing', $captured);
        $this->assertStringContainsString('Do not invent detailed face or body traits.', $captured);
        $this->assertStringNotContainsString('Moonblade of Azul', $captured);
        $this->assertStringNotContainsString('glowing blue', $captured);
        $this->assertStringNotContainsString('silk rope', $captured);
        $this->assertStringNotContainsString('Every piece of clothing and armour, named specifically', $captured);
        $this->assertStringNotContainsString('omitting any is an error', $captured);
        $this->assertStringNotContainsString('silver chainmail hauberk', $captured);
        $this->assertStringNotContainsString('Precise skin tone', $captured);
        $this->assertStringNotContainsString('this is mandatory, never omit it', $captured);

        $scene = SceneArtPrompt::where('game_session_id', $session->id)->firstOrFail();
        $this->assertStringContainsString('Chain mail (well-kept rings)', $scene->prompt);
        $this->assertStringContainsString('Long sword (silvered)', $scene->prompt);
        $this->assertStringContainsString('Equipped (depict these only):', $scene->prompt);
        $this->assertStringContainsString('Appearance is not recorded.', $scene->prompt);
        $this->assertStringContainsString(CharacterArtBrief::NO_INVENT_GEAR, $scene->prompt);
        $this->assertStringNotContainsString('Moonblade of Azul', $scene->prompt);
        $this->assertStringNotContainsString('glowing blue', $scene->prompt);
        $this->assertStringContainsString('invented magic items', (string) $scene->negative_prompt);
        $this->assertSame('done', $session->fresh()->art_prompts_status);
    }

    public function test_lumina2_negative_prompt_carries_gear_hallucination_line(): void
    {
        $method = new ReflectionMethod(GeneratePortrait::class, 'callComfyUI');
        $negative = null;
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === 'negativePrompt') {
                $negative = $parameter->getDefaultValue();
            }
        }

        $this->assertSame(CharacterArtBrief::GEAR_HALLUCINATION_NEGATIVE, $negative);
        $this->assertNotSame('', $negative);
        $this->assertStringContainsString(
            'invented magic items',
            CharacterArtBrief::withGearNegative('blurry, low quality'),
        );
        $this->assertSame(
            'blurry, invented magic items already listed',
            CharacterArtBrief::withGearNegative('blurry, invented magic items already listed'),
        );

        $usesTrait = in_array(GeneratesViaComfyUI::class, class_uses(GeneratePortrait::class), true);
        $this->assertTrue($usesTrait);
        $this->assertTrue(in_array(GeneratesViaComfyUI::class, class_uses(GeneratePartyPortrait::class), true));
    }

    private function logain(): Character
    {
        $campaign = Campaign::factory()->create(['name' => 'Gate Road']);
        $logain = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Logain',
            'race' => 'Human',
            'class' => 'Fighter',
            'subclass' => null,
            'level' => 10,
            'appearance_description' => 'A lean man in his thirties with a thin scar along the left jaw.',
            'mannerisms' => 'Speaks in a low whisper. Rests one hand on his sword hilt.',
        ]);

        $logain->inventoryItems()->create([
            'name' => 'Long sword',
            'category' => 'weapon',
            'equipped' => true,
            'properties' => ['silvered'],
        ]);
        $logain->inventoryItems()->create([
            'name' => 'Chain mail',
            'category' => 'armor',
            'equipped' => true,
            'description' => 'well-kept rings',
        ]);
        $logain->inventoryItems()->create([
            'name' => 'Moonblade of Azul',
            'category' => 'weapon',
            'equipped' => false,
            'is_magical' => true,
            'description' => 'a glowing blue +2 long sword',
        ]);
        $logain->inventoryItems()->create([
            'name' => 'Silk rope',
            'category' => 'adventuring gear',
            'equipped' => false,
            'description' => 'fifty feet of silk rope',
        ]);

        return $logain;
    }

    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
