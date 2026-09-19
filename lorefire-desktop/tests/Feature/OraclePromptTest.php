<?php

namespace Tests\Feature;

use App\Http\Controllers\OracleController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OraclePromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_controller_prompt_includes_briefing_without_campaigns(): void
    {
        $prompt = app(OracleController::class)->buildSystemPrompt([]);

        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
        $this->assertStringContainsString('You are the Oracle', $prompt);
        $this->assertStringNotContainsString('## Campaign Data', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('spell slots', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('proficiency bonus', $prompt);
    }

    public function test_controller_prompt_keeps_briefing_when_campaigns_exist(): void
    {
        $prompt = app(OracleController::class)->buildSystemPrompt([
            'campaigns' => [[
                'name' => 'Empty Table',
                'characters' => [],
            ]],
        ]);

        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
        $this->assertStringContainsString('## Campaign Data', $prompt);
        $this->assertStringContainsString('Empty Table', $prompt);
        $this->assertStringContainsString('do not quote copyrighted rulebook text', $prompt);
        $this->assertStringContainsString('house switch', $prompt);
        $this->assertStringContainsString('when the new class is 5th', $prompt);
    }

    public function test_controller_prompt_includes_notes_and_backstory(): void
    {
        $prompt = app(OracleController::class)->buildSystemPrompt([
            'campaigns' => [[
                'name' => 'Moonshae Run',
                'description' => 'The fighter house chronicle.',
                'notes' => 'Full master doc: origin of the six PCs and the broken oath.',
                'characters' => [[
                    'name' => 'Aelindra',
                    'race' => 'Elf',
                    'class' => 'Fighter/Mage',
                    'backstory' => 'Born in the coastal fighter house.',
                    'thac0' => 20,
                    'armor_class' => 5,
                ]],
            ]],
        ]);

        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
        $this->assertStringContainsString('**Campaign notes:**', $prompt);
        $this->assertStringContainsString('Full master doc: origin of the six PCs and the broken oath.', $prompt);
        $this->assertStringContainsString('**Backstory:**', $prompt);
        $this->assertStringContainsString('Born in the coastal fighter house.', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('spell slots', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('proficiency bonus', $prompt);
    }

    public function test_controller_prompt_injects_engine_backed_rule_numbers(): void
    {
        $prompt = app(OracleController::class)->buildSystemPrompt(
            [],
            'What is the missile adjustment for DEX 17?'
        );

        $this->assertStringContainsString('## Engine lookup', $prompt);
        $this->assertStringContainsString('missile: +2', $prompt);
        $this->assertStringContainsString('Adnd2e::dexterityAdjustments', $prompt);
        $this->assertStringContainsString('Never invent official spell text', $prompt);

        $thac0 = app(OracleController::class)->buildSystemPrompt([], 'fighter THAC0 at level 5');
        $this->assertStringContainsString('Fighter 5 THAC0: 16', $thac0);

        $open = app(OracleController::class)->buildSystemPrompt([], 'STR 18/01 open doors');
        $this->assertStringContainsString('open doors: 12', $open);

        $armor = app(OracleController::class)->buildSystemPrompt([], 'What is the AC of chain mail?');
        $this->assertStringContainsString('Chain mail base AC: 5', $armor);
    }
}
