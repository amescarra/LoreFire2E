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

    public function test_natural_thac0_prompts_use_group_tables(): void
    {
        $cases = [
            ['what is THAC0 for a 10th level fighter', 'Fighter 10 THAC0: 11'],
            ['fighter 11 thac0', 'Fighter 11 THAC0: 10'],
            ['Level 11 Fighter thac0', 'Fighter 11 THAC0: 10'],
            ['warrior 11 thac0', 'Fighter 11 THAC0: 10'],
            ['cleric 4 thac0', 'Cleric 4 THAC0: 18'],
            ['priest 4 thac0', 'Cleric 4 THAC0: 18'],
            ['mage 6 thac0', 'Mage 6 THAC0: 19'],
            ['wizard 6 thac0', 'Mage 6 THAC0: 19'],
            ['thief 5 thac0', 'Thief 5 THAC0: 19'],
            ['rogue 5 thac0', 'Thief 5 THAC0: 19'],
        ];

        foreach ($cases as [$question, $expect]) {
            $result = Adnd2eOracleRulesLookup::lookup($question);
            $this->assertTrue($result['resolved'], $question);
            $this->assertSame('rules', $result['intent'], $question);
            $this->assertStringContainsString($expect, $result['markdown'], $question);
            $this->assertTrue(Adnd2eOracleRulesLookup::isDirectTableAnswer($question, $result), $question);

            $player = Adnd2eOracleRulesLookup::playerReply($question);
            $this->assertNotNull($player, $question);
            $this->assertStringContainsString($expect, $player, $question);
            $this->assertStringNotContainsString('These values come from', $player, $question);
            $this->assertStringNotContainsString('Do not invent', $player, $question);
        }
    }

    public function test_player_reply_skips_non_thac0_and_narrative(): void
    {
        $this->assertNull(Adnd2eOracleRulesLookup::playerReply('Summarize my most recent session.'));
        $this->assertNull(Adnd2eOracleRulesLookup::playerReply('What is the missile adjustment for DEX 17?'));
        $this->assertNull(Adnd2eOracleRulesLookup::playerReply('Quote the official Fireball spell text from the PHB page 145.'));
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

    public function test_combat_armor_weapon_and_expanded_thac0_lookups(): void
    {
        $armor = Adnd2eOracleRulesLookup::lookup('What is the AC of chain mail?');
        $this->assertTrue($armor['resolved']);
        $this->assertStringContainsString('Chain mail base AC: 5', $armor['markdown']);
        $this->assertStringContainsString('Adnd2e::armorBaseAc', $armor['markdown']);

        $weapon = Adnd2eOracleRulesLookup::lookup('Long sword damage dice and weapon speed?');
        $this->assertTrue($weapon['resolved']);
        $this->assertStringContainsString('SM 1d8', $weapon['markdown']);
        $this->assertStringContainsString('L 1d12', $weapon['markdown']);
        $this->assertStringContainsString('speed 5', $weapon['markdown']);
        $this->assertStringContainsString('Adnd2e::weaponStats', $weapon['markdown']);

        $paladin = Adnd2eOracleRulesLookup::lookup('Paladin THAC0 at level 7');
        $this->assertTrue($paladin['resolved']);
        $this->assertSame(14, Adnd2e::thac0('Paladin', 7));
        $this->assertStringContainsString('Paladin 7 THAC0: 14', $paladin['markdown']);
    }

    public function test_full_ability_columns_are_queryable(): void
    {
        $wis = Adnd2eOracleRulesLookup::lookup('WIS 17 bonus spells?');
        $this->assertTrue($wis['resolved']);
        $this->assertStringContainsString('bonus: 1st×2, 2nd×2, 3rd×1', $wis['markdown']);

        $con = Adnd2eOracleRulesLookup::lookup('CON 16 system shock?');
        $this->assertTrue($con['resolved']);
        $this->assertStringContainsString('shock: 95%', $con['markdown']);

        $int = Adnd2eOracleRulesLookup::lookup('INT 16 chance to learn?');
        $this->assertTrue($int['resolved']);
        $this->assertStringContainsString('learn: 70%', $int['markdown']);

        $cha = Adnd2eOracleRulesLookup::lookup('CHA 16 henchmen?');
        $this->assertTrue($cha['resolved']);
        $this->assertStringContainsString('hench: 8', $cha['markdown']);

        $wt = Adnd2eOracleRulesLookup::lookup('STR 16 weight allowance?');
        $this->assertTrue($wt['resolved']);
        $this->assertStringContainsString('wt: 70', $wt['markdown']);
    }

    public function test_saving_throw_matrix_and_category_lookup(): void
    {
        $poison = Adnd2eOracleRulesLookup::lookup('Fighter save vs poison at level 1');
        $this->assertTrue($poison['resolved']);
        $this->assertStringContainsString('Fighter 1 paralyzation: 14', $poison['markdown']);
        $this->assertStringContainsString('Adnd2e::savingThrows', $poison['markdown']);

        $matrix = Adnd2eOracleRulesLookup::lookup('Cleric saving throw matrix');
        $this->assertTrue($matrix['resolved']);
        $this->assertStringContainsString('Cleric 1–3:', $matrix['markdown']);
        $this->assertStringContainsString('Adnd2e::savingThrowBands', $matrix['markdown']);
    }

    public function test_memorization_and_priest_sphere_lookups(): void
    {
        $slots = Adnd2eOracleRulesLookup::lookup('How many spells can a 5th-level mage memorize?');
        $this->assertTrue($slots['resolved']);
        $this->assertStringContainsString('Mage 5 memorization: L1=4, L2=2, L3=1', $slots['markdown']);
        $this->assertStringContainsString('Adnd2e::memorizationCapacity', $slots['markdown']);
        $this->assertStringNotContainsStringIgnoringCase('spell slots', $slots['markdown']);

        $spheres = Adnd2eOracleRulesLookup::lookup('Cleric priest spheres?');
        $this->assertTrue($spheres['resolved']);
        $this->assertStringContainsString('Cleric major: All, Astral', $spheres['markdown']);
        $this->assertStringContainsString('minor: Elemental', $spheres['markdown']);
        $this->assertStringContainsString('Adnd2e::priestSpheres', $spheres['markdown']);
        $this->assertStringNotContainsString('Cure Light', $spheres['markdown']);
    }

    public function test_encumbrance_and_movement_lookups(): void
    {
        $move = Adnd2eOracleRulesLookup::lookup('Dwarf movement rate?');
        $this->assertTrue($move['resolved']);
        $this->assertStringContainsString('Dwarf movement rate: 6', $move['markdown']);

        $enc = Adnd2eOracleRulesLookup::lookup('STR 10 encumbrance thresholds and Human carrying 41 lb');
        $this->assertTrue($enc['resolved']);
        $this->assertStringContainsString('none 40', $enc['markdown']);
        $this->assertStringContainsString('light', $enc['markdown']);
        $this->assertStringContainsString('MV 9', $enc['markdown']);
        $this->assertStringContainsString('Adnd2e::encumbranceThresholds', $enc['markdown']);
        $this->assertStringContainsString('Adnd2e::movementAtLoad', $enc['markdown']);
    }

    public function test_spell_catalog_headers_are_queryable_without_effect_prose(): void
    {
        $fireball = Adnd2eOracleRulesLookup::lookup('What level is Fireball for a mage?');
        $this->assertTrue($fireball['resolved']);
        $this->assertStringContainsString('Fireball: Mage L3 invocation', $fireball['markdown']);
        $this->assertStringContainsString('V, S, M', $fireball['markdown']);
        $this->assertStringContainsString('bat guano', $fireball['markdown']);
        $this->assertStringContainsString('Adnd2eSpellCatalog', $fireball['markdown']);
        $this->assertStringNotContainsStringIgnoringCase('explosive', $fireball['markdown']);

        $cure = Adnd2eOracleRulesLookup::lookup('Cure Light Wounds cleric spell level?');
        $this->assertTrue($cure['resolved']);
        $this->assertStringContainsString('Cure Light Wounds: Cleric/Paladin L1 Healing', $cure['markdown']);

        $list = Adnd2eOracleRulesLookup::lookup('List 1st-level mage spells');
        $this->assertTrue($list['resolved']);
        $this->assertStringContainsString('Mage L1 names:', $list['markdown']);
        $this->assertStringContainsString('Magic Missile', $list['markdown']);
        $this->assertStringNotContainsString('Fireball', $list['markdown']);
    }

    public function test_official_fireball_text_stays_unresolved_after_catalog(): void
    {
        $result = Adnd2eOracleRulesLookup::lookup('Quote the official Fireball spell text from the PHB page 145.');

        $this->assertSame('rules', $result['intent']);
        $this->assertFalse($result['resolved']);
        $this->assertStringContainsString('could not resolve', $result['markdown']);
        $this->assertStringContainsString('does not ingest PHB', $result['markdown']);
        $this->assertStringNotContainsString('Fireball: Mage L3', $result['markdown']);
        $this->assertStringNotContainsString('A fireball is an explosive burst of flame', $result['markdown']);
    }

    public function test_magical_equipment_and_artifact_lookups(): void
    {
        $plate = Adnd2eOracleRulesLookup::lookup('What is the AC of plate mail +1?');
        $this->assertTrue($plate['resolved']);
        $this->assertStringContainsString('Plate mail +1: armor AC 2', $plate['markdown']);
        $this->assertStringContainsString('bonus +1', $plate['markdown']);
        $this->assertStringContainsString('Adnd2eEquipmentCatalog', $plate['markdown']);
        $this->assertStringNotContainsString('Plate mail base AC: 3', $plate['markdown']);

        $sword = Adnd2eOracleRulesLookup::lookup('Long sword +2 damage?');
        $this->assertTrue($sword['resolved']);
        $this->assertStringContainsString('Long sword +2: weapon SM 1d8 / L 1d12 / speed 5', $sword['markdown']);
        $this->assertStringContainsString('bonus +2', $sword['markdown']);

        $artifact = Adnd2eOracleRulesLookup::lookup('Sword of Kas mechanical tags?');
        $this->assertTrue($artifact['resolved']);
        $this->assertStringContainsString('Sword of Kas:', $artifact['markdown']);
        $this->assertStringContainsString('+6 hit/dmg', $artifact['markdown']);
        $this->assertStringContainsString('Adnd2eEquipmentCatalog::artifact', $artifact['markdown']);
        $this->assertStringNotContainsStringIgnoringCase('betrayed', $artifact['markdown']);
    }

    public function test_creature_stub_and_campaign_npc_lookups(): void
    {
        $orc = Adnd2eOracleRulesLookup::lookup('Orc THAC0 and AC?');
        $this->assertTrue($orc['resolved']);
        $this->assertStringContainsString('Orc stub: AC 6, HD 1, THAC0 19, dmg 1d8', $orc['markdown']);
        $this->assertStringContainsString('Adnd2eCreatureStubs', $orc['markdown']);
        $this->assertStringNotContainsString('Fighter 1 THAC0', $orc['markdown']);
        $this->assertStringNotContainsStringIgnoringCase('ecology', $orc['markdown']);

        $npc = Adnd2eOracleRulesLookup::lookup('What is Grumble THAC0 and AC?', [
            'campaigns' => [[
                'name' => 'Moonshae Run',
                'npcs' => [[
                    'name' => 'Grumble',
                    'race' => 'Dwarf',
                    'role' => 'innkeep',
                    'location' => 'Crossroads Inn',
                    'attitude' => 'friendly',
                    'tags' => ['quest'],
                    'stat_block' => ['ac' => 8, 'hd' => '3', 'thac0' => 18, 'dmg' => '1d6'],
                    'description' => 'A long tavern monologue that must not appear in engine facts.',
                    'notes' => 'Secret plot hook prose.',
                ]],
            ]],
        ]);
        $this->assertTrue($npc['resolved']);
        $this->assertStringContainsString('Grumble', $npc['markdown']);
        $this->assertStringContainsString('innkeep', $npc['markdown']);
        $this->assertStringContainsString('AC 8', $npc['markdown']);
        $this->assertStringContainsString('THAC0 18', $npc['markdown']);
        $this->assertStringContainsString('campaign NPC', $npc['markdown']);
        $this->assertStringNotContainsString('tavern monologue', $npc['markdown']);
        $this->assertStringNotContainsString('Secret plot hook', $npc['markdown']);
    }

    public function test_who_is_npc_stays_narrative(): void
    {
        $this->assertFalse(Adnd2eOracleRulesLookup::looksLikeRulesQuery('Who is Grumble in the campaign notes?'));
        $result = Adnd2eOracleRulesLookup::lookup('Who is Grumble in the campaign notes?');
        $this->assertSame('narrative', $result['intent']);
        $this->assertSame('', $result['markdown']);
    }
}
