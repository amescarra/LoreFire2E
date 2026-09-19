<?php

namespace Tests\Unit;

use App\Support\Adnd2eOracleBriefing;
use PHPUnit\Framework\TestCase;

class Adnd2eOracleBriefingTest extends TestCase
{
    public function test_briefing_is_present_with_empty_context(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([]);

        $this->assertStringContainsString('You are the Oracle', $prompt);
        $this->assertStringContainsString('do not quote copyrighted rulebook text', $prompt);
        $this->assertStringContainsString('Never invent official spell text', $prompt);
        $this->assertStringContainsString('Do not answer as if the table were using 5th Edition', $prompt);
        $this->assertStringNotContainsString('## Engine lookup', $prompt);
        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
        $this->assertStringContainsString('THAC0', $prompt);
        $this->assertStringContainsString('descending', $prompt);
        $this->assertStringContainsString('Vancian memorization', $prompt);
        $this->assertStringContainsString('Overnight rest', $prompt);
        $this->assertStringContainsString('-10', $prompt);
        $this->assertStringContainsString('Bladesinger', $prompt);
        $this->assertStringContainsString('Psionicist', $prompt);
        $this->assertStringContainsString('PSP totals and typed power names are sheet fields', $prompt);
        $this->assertStringContainsString('Do not invent 5th Edition psionics', $prompt);
        $this->assertStringContainsString('Do not treat Psionicist as a kit', $prompt);
        $this->assertStringContainsString('house switch', $prompt);
        $this->assertStringContainsString('Begin a new class at 6th in the original', $prompt);
        $this->assertStringContainsString('when the new class is 5th', $prompt);
        $this->assertStringNotContainsString('typically human', $prompt);
        $this->assertStringNotContainsString('9−1', $prompt);
        $this->assertStringNotContainsString('## Campaign Data', $prompt);
        $this->assertLessThan(1500, str_word_count($prompt));
    }

    public function test_briefing_is_present_with_campaign_context(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([
            'campaigns' => [[
                'name' => 'Moonshae Run',
                'description' => 'A coastal chronicle.',
                'characters' => [[
                    'name' => 'Aelindra',
                    'race' => 'Elf',
                    'class' => 'Fighter/Mage',
                    'level' => 1,
                    'class_path' => 'multi',
                    'subclass' => 'Bladesinger',
                    'current_hp' => 6,
                    'max_hp' => 6,
                    'armor_class' => 5,
                    'thac0' => 20,
                ]],
                'game_sessions' => [],
            ]],
        ]);

        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
        $this->assertStringContainsString('## Campaign Data', $prompt);
        $this->assertStringContainsString('Moonshae Run', $prompt);
        $this->assertStringContainsString('Aelindra', $prompt);
        $this->assertStringContainsString('kit: Bladesinger', $prompt);
        $this->assertStringContainsString('[multi]', $prompt);
        $this->assertStringContainsString('THAC0: 20', $prompt);
        $this->assertStringContainsString('AC: 5 (descending)', $prompt);
        $this->assertStringContainsString('HP: 6/6', $prompt);
    }

    public function test_briefing_avoids_fifth_edition_terms(): void
    {
        $briefing = Adnd2eOracleBriefing::markdown();
        $prompt = Adnd2eOracleBriefing::systemPrompt([]);

        foreach ([$briefing, $prompt] as $text) {
            $this->assertStringNotContainsStringIgnoringCase('spell slots', $text);
            $this->assertStringNotContainsStringIgnoringCase('spell slot', $text);
            $this->assertStringNotContainsStringIgnoringCase('proficiency bonus', $text);
            $this->assertStringNotContainsStringIgnoringCase('death save', $text);
            $this->assertStringNotContainsStringIgnoringCase('armor class 10 +', $text);
            $this->assertDoesNotMatchRegularExpression('/\bascending AC\b/i', $text);
            $this->assertStringContainsString('descending', $text);
        }
    }

    public function test_campaign_notes_and_character_backstory_are_injected(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([
            'campaigns' => [[
                'name' => 'Moonshae Run',
                'description' => 'A coastal chronicle.',
                'notes' => 'Master doc: the party fled the fighter house after the oath-breaking.',
                'characters' => [[
                    'name' => 'Aelindra',
                    'race' => 'Elf',
                    'class' => 'Fighter/Mage',
                    'level' => 1,
                    'backstory' => 'Aelindra was born in the coastal fighter house and left after the trial.',
                    'current_hp' => 6,
                    'max_hp' => 6,
                    'armor_class' => 5,
                    'thac0' => 20,
                ]],
                'game_sessions' => [[
                    'session_number' => 1,
                    'title' => 'Fighter house rules',
                    'session_notes' => 'House rules for the fighter school.',
                ]],
            ]],
        ]);

        $this->assertStringContainsString('Lorefire 2E procedures', $prompt);
        $this->assertStringContainsString('## Campaign Data', $prompt);
        $this->assertStringContainsString('**Campaign notes:**', $prompt);
        $this->assertStringContainsString('Master doc: the party fled the fighter house after the oath-breaking.', $prompt);
        $this->assertStringContainsString('**Backstory:**', $prompt);
        $this->assertStringContainsString('Aelindra was born in the coastal fighter house and left after the trial.', $prompt);
        $this->assertStringContainsString('Fighter house rules', $prompt);
        $this->assertStringContainsString('House rules for the fighter school.', $prompt);
        $this->assertStringNotContainsString('(truncated)', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('spell slots', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('proficiency bonus', $prompt);
    }

    public function test_empty_notes_and_backstory_are_omitted(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([
            'campaigns' => [[
                'name' => 'Empty Table',
                'description' => 'Just a name.',
                'notes' => '   ',
                'characters' => [[
                    'name' => 'Bran',
                    'backstory' => '',
                ]],
            ]],
        ]);

        $this->assertStringContainsString('Empty Table', $prompt);
        $this->assertStringContainsString('Just a name.', $prompt);
        $this->assertStringContainsString('Bran', $prompt);
        $this->assertStringNotContainsString('**Campaign notes:**', $prompt);
        $this->assertStringNotContainsString('**Backstory:**', $prompt);
    }

    public function test_long_notes_and_backstory_are_capped_with_truncation_note(): void
    {
        $notes = str_repeat('N', Adnd2eOracleBriefing::NOTES_CAP + 80);
        $backstory = str_repeat('B', Adnd2eOracleBriefing::BACKSTORY_CAP + 40);

        $prompt = Adnd2eOracleBriefing::systemPrompt([
            'campaigns' => [[
                'name' => 'Moonshae Run',
                'notes' => $notes,
                'characters' => [[
                    'name' => 'Aelindra',
                    'backstory' => $backstory,
                ]],
            ]],
        ]);

        $this->assertStringContainsString(str_repeat('N', Adnd2eOracleBriefing::NOTES_CAP), $prompt);
        $this->assertStringNotContainsString(str_repeat('N', Adnd2eOracleBriefing::NOTES_CAP + 1), $prompt);
        $this->assertStringContainsString(str_repeat('B', Adnd2eOracleBriefing::BACKSTORY_CAP), $prompt);
        $this->assertStringNotContainsString(str_repeat('B', Adnd2eOracleBriefing::BACKSTORY_CAP + 1), $prompt);
        $this->assertStringContainsString('(truncated)', $prompt);
        $this->assertSame(
            str_repeat('N', Adnd2eOracleBriefing::NOTES_CAP).' (truncated)',
            Adnd2eOracleBriefing::clipNarrative($notes, Adnd2eOracleBriefing::NOTES_CAP)
        );
    }

    public function test_character_line_prefers_stored_sheet_values(): void
    {
        $line = Adnd2eOracleBriefing::formatCharacterLine([
            'name' => 'Bran',
            'race' => 'Dwarf',
            'class' => 'Fighter',
            'level' => 3,
            'class_path' => 'single',
            'subclass' => 'Battlerager',
            'current_hp' => 18,
            'max_hp' => 22,
            'armor_class' => 2,
            'thac0' => 18,
        ]);

        $this->assertStringContainsString('THAC0: 18', $line);
        $this->assertStringContainsString('AC: 2 (descending)', $line);
        $this->assertStringContainsString('HP: 18/22', $line);
        $this->assertStringContainsString('kit: Battlerager', $line);
        $this->assertStringContainsString('[single]', $line);
    }

    public function test_character_line_includes_ability_scores(): void
    {
        $line = Adnd2eOracleBriefing::formatCharacterLine([
            'name' => 'Thurmbog',
            'strength' => 18,
            'exceptional_strength' => '67',
            'dexterity' => 17,
        ]);

        $this->assertStringContainsString('STR 18/67', $line);
        $this->assertStringContainsString('DEX 17', $line);
    }

    public function test_rule_math_question_injects_engine_lookup_into_prompt(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([], 'Fighter THAC0 at level 5?');

        $this->assertStringContainsString('## Engine lookup', $prompt);
        $this->assertStringContainsString('Fighter 5 THAC0: 16', $prompt);
        $this->assertStringContainsString('MUST use Engine lookup', $prompt);
    }

    public function test_campaign_npcs_are_compact_hooks_without_prose(): void
    {
        $prompt = Adnd2eOracleBriefing::systemPrompt([
            'campaigns' => [[
                'name' => 'Moonshae Run',
                'npcs' => [[
                    'name' => 'Grumble',
                    'race' => 'Dwarf',
                    'role' => 'innkeep',
                    'location' => 'Crossroads Inn',
                    'attitude' => 'friendly',
                    'tags' => ['quest'],
                    'stat_block' => ['ac' => 8, 'thac0' => 18],
                    'description' => 'A long tavern monologue.',
                    'notes' => 'Secret plot hook.',
                ]],
            ]],
        ]);

        $this->assertStringContainsString('**NPCs:**', $prompt);
        $this->assertStringContainsString('Grumble', $prompt);
        $this->assertStringContainsString('innkeep', $prompt);
        $this->assertStringContainsString('@ Crossroads Inn', $prompt);
        $this->assertStringContainsString('AC 8', $prompt);
        $this->assertStringContainsString('THAC0 18', $prompt);
        $this->assertStringNotContainsString('A long tavern monologue.', $prompt);
        $this->assertStringNotContainsString('Secret plot hook.', $prompt);
    }
}
