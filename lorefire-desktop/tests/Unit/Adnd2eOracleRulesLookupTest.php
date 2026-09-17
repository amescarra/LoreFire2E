<?php

namespace Tests\Unit;

use App\Support\Adnd2e;
use App\Support\Adnd2eOracleBriefing;
use App\Support\Adnd2eOracleRulesLookup;
use PHPUnit\Framework\TestCase;

class Adnd2eOracleRulesLookupTest extends TestCase
{
    public function test_dex_17_missile_uses_engine_table(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('What is the missile adjustment for DEX 17?');

        $this->assertSame('rules', $result['intent']);
        $this->assertTrue($result['resolved']);
        $this->assertSame(2, Adnd2e::dexterityAdjustments(17)['missile']);
        $this->assertStringContainsString('missile: +2', $result['markdown']);
        $this->assertStringContainsString('Adnd2e::dexterityAdjustments', $result['markdown']);
        $this->assertStringContainsString('## Engine lookup', $result['markdown']);
    }

    public function test_fighter_thac0_by_level_uses_engine(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('Fighter THAC0 at level 1, level 5, and level 10.');

        $this->assertSame('rules', $result['intent']);
        $this->assertTrue($result['resolved']);
        $this->assertSame(20, Adnd2e::thac0('Fighter', 1));
        $this->assertSame(16, Adnd2e::thac0('Fighter', 5));
        $this->assertSame(11, Adnd2e::thac0('Fighter', 10));
        $this->assertStringContainsString('Fighter 5 THAC0: 16', $result['markdown']);
        $this->assertStringContainsString('Fighter 1 THAC0: 20', $result['markdown']);
        $this->assertStringContainsString('Fighter 10 THAC0: 11', $result['markdown']);
        $this->assertStringContainsString('Adnd2e::thac0', $result['markdown']);
    }

    public function test_strength_18_01_open_doors_uses_engine(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('STR 18/01 open doors?');

        $this->assertSame('rules', $result['intent']);
        $this->assertTrue($result['resolved']);
        $this->assertSame('12', Adnd2e::strengthAdjustments(18, '01')['open_doors']);
        $this->assertStringContainsString('open doors: 12', $result['markdown']);
        $this->assertStringContainsString('Adnd2e::strengthAdjustments', $result['markdown']);
        $this->assertStringContainsString('18/01', $result['markdown']);
    }

    public function test_unknown_phb_spell_text_is_admitted(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('Quote the official Fireball spell text from the PHB page 145.');

        $this->assertSame('rules', $result['intent']);
        $this->assertFalse($result['resolved']);
        $this->assertStringContainsString('could not resolve', $result['markdown']);
        $this->assertStringContainsString('does not ingest PHB', $result['markdown']);
        $this->assertStringNotContainsString('A fireball is an explosive burst of flame', $result['markdown']);
    }

    public function test_narrative_questions_skip_lookup(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('Summarize my most recent session.');

        $this->assertSame('narrative', $result['intent']);
        $this->assertSame('', $result['markdown']);
        $this->assertFalse(Adnd2eOracleRulesLookup::looksLikeRulesQuery('Who is Aelindra in the campaign notes?'));
    }

    public function test_dual_class_house_gates_come_from_engine_constants(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('Can a 5th-level fighter begin a new dual-class?');

        $this->assertTrue($result['resolved']);
        $this->assertFalse(Adnd2e::canBeginNewClass(5));
        $this->assertTrue(Adnd2e::canBeginNewClass(6));
        $this->assertStringContainsString('canBeginNewClass(5): no', $result['markdown']);
        $this->assertStringContainsString((string) Adnd2e::HOUSE_DUAL_MIN_ORIGINAL_LEVEL, $result['markdown']);
        $this->assertStringContainsString((string) Adnd2e::HOUSE_DUAL_RESUME_NEW_LEVEL, $result['markdown']);
    }

    public function test_thac0_versus_ac_uses_number_needed_to_hit(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('THAC0 20 vs AC 0 — number needed to hit?');

        $this->assertTrue($result['resolved']);
        $this->assertSame(20, Adnd2e::numberNeededToHit(20, 0));
        $this->assertStringContainsString('needs 20 on d20', $result['markdown']);
        $this->assertStringContainsString('Adnd2e::numberNeededToHit', $result['markdown']);
    }

    public function test_character_context_ability_lookup_uses_sheet_score_and_engine(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('What is Thurmbog missile adjustment?', [
            'campaigns' => [[
                'characters' => [[
                    'name' => 'Thurmbog',
                    'class' => 'Fighter',
                    'level' => 7,
                    'dexterity' => 17,
                ]],
            ]],
        ]);

        $this->assertTrue($result['resolved']);
        $this->assertStringContainsString('Thurmbog', $result['markdown']);
        $this->assertStringContainsString('missile: +2', $result['markdown']);
    }

    public function test_system_prompt_injects_engine_numbers_for_rule_query(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([], 'What is the missile adjustment for DEX 17?');

        $this->assertStringContainsString('## Engine lookup', $prompt);
        $this->assertStringContainsString('missile: +2', $prompt);
        $this->assertStringContainsString('Never invent official spell text', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('spell slots', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('proficiency bonus', $prompt);
    }

    public function test_system_prompt_omits_lookup_section_for_narrative(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([], 'Summarize my most recent session.');

        $this->assertStringNotContainsString('## Engine lookup', $prompt);
        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
    }
}
