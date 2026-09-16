<?php

namespace Tests\Unit;

use App\Support\Adnd2e;
use PHPUnit\Framework\TestCase;

class KitFieldUiTest extends TestCase
{
    public function test_visible_racial_kits_section_lists_bladesinger_for_elf_fighter_mage(): void
    {
        $tsx = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/KitField.tsx');
        $this->assertIsString($tsx);

        $this->assertStringContainsString('data-testid="racial-handbook-kits"', $tsx);
        $this->assertStringContainsString('Racial handbook kits', $tsx);
        $this->assertStringContainsString('data-kit={k}', $tsx);
        $this->assertStringContainsString('onClick={() => onChange(k)}', $tsx);
        $this->assertStringContainsString('kits.length > 0 &&', $tsx);

        $racialGroup = strpos($tsx, 'optgroup label="Racial handbook kits"');
        $schoolGroup = strpos($tsx, 'optgroup label="Specialist school"');
        $this->assertNotFalse($racialGroup);
        $this->assertNotFalse($schoolGroup);
        $this->assertLessThan(
            $schoolGroup,
            $racialGroup,
            'Racial handbook kits must appear above specialist schools in the select.'
        );

        $elfFm = Adnd2e::suggestedRacialKits('Elf', [
            ['class' => 'Fighter', 'level' => 1],
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertContains('Bladesinger', $elfFm);
        $this->assertContains('War Wizard', $elfFm);
    }

    public function test_human_mage_has_no_visible_racial_handbook_kits(): void
    {
        $human = Adnd2e::suggestedRacialKits('Human', [
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertSame([], $human);
        $this->assertNotContains('Bladesinger', $human);

        $options = Adnd2e::suggestedSubclassOptions('Human', [
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertContains('Illusionist', $options);
        $this->assertNotContains('Bladesinger', $options);
    }

    public function test_psionicist_kit_is_optional_free_text_not_disciplines(): void
    {
        $tsx = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/KitField.tsx');
        $this->assertIsString($tsx);
        $this->assertStringNotContainsString('PSIONIC_DISCIPLINES', $tsx);
        $this->assertStringNotContainsString('Psionic disciplines', $tsx);
        $this->assertStringNotContainsString('hasPsionicist', $tsx);
        $this->assertStringContainsString("placeholder=\"Optional kit\"", $tsx);

        $options = Adnd2e::suggestedSubclassOptions('Human', [
            ['class' => 'Psionicist', 'level' => 9],
        ]);
        $this->assertNotContains('Telepathy', $options);
        $this->assertNotContains('Metapsionics', $options);
        $this->assertNotContains('Clairsentience', $options);
        $this->assertNotContains('Psychokinesis', $options);
        $this->assertSame([], $options);

        $php = file_get_contents(dirname(__DIR__, 2).'/app/Support/Adnd2e.php');
        $this->assertIsString($php);
        $this->assertStringNotContainsString('Total Disciplines', $php);
        $this->assertStringNotContainsString('Total Sciences', $php);
        $this->assertStringNotContainsString('Def. Modes', $php);
    }

    public function test_psionicist_sheet_fields_are_gated_and_have_no_handbook_excerpts(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Create.tsx', 'Edit.tsx'] as $page) {
            $tsx = file_get_contents($root.'/resources/js/Pages/Characters/'.$page);
            $this->assertIsString($tsx);
            $this->assertStringContainsString('hasPsionicist', $tsx);
            $this->assertStringContainsString('{hasPsionicist(', $tsx);
            $this->assertStringContainsString('<PsionicistSheetFields', $tsx);
            $this->assertStringNotContainsString('Total Sciences', $tsx);
            $this->assertStringNotContainsString('Total Devotions', $tsx);
            $this->assertStringNotContainsString('Power Score', $tsx);
        }

        $show = file_get_contents($root.'/resources/js/Pages/Characters/Show.tsx');
        $this->assertIsString($show);
        $this->assertStringContainsString('hasPsionicist(classEntries)', $show);
        $this->assertStringContainsString('data-testid="psionicist-sheet"', $show);
        $this->assertStringNotContainsString('Total Sciences', $show);

        $sheet = file_get_contents($root.'/resources/js/Components/PsionicistSheetFields.tsx');
        $this->assertIsString($sheet);
        $this->assertStringContainsString('data-testid="psionicist-sheet"', $sheet);
        $this->assertStringContainsString('psionic-discipline-labels', $sheet);
        $this->assertStringContainsString('PSIONIC_DISCIPLINES', $sheet);
        $this->assertStringNotContainsString('science', strtolower($sheet));
        $this->assertStringNotContainsString('devotion', strtolower($sheet));
        $this->assertStringNotContainsString('power score', strtolower($sheet));
    }

    public function test_class_path_fields_list_psionicist_and_house_dual_hint(): void
    {
        $tsx = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/ClassPathFields.tsx');
        $this->assertIsString($tsx);
        $this->assertStringContainsString('CLASSES.map', $tsx);
        $this->assertStringContainsString('House dual-class', $tsx);
        $this->assertStringContainsString('when the new class is 5th', $tsx);
        $this->assertStringNotContainsString('typically human', $tsx);

        $this->assertContains('Psionicist', Adnd2e::CLASSES);
        $this->assertSame(
            ['Fighter', 'Paladin', 'Ranger', 'Mage', 'Cleric', 'Druid', 'Thief', 'Bard', 'Psionicist'],
            Adnd2e::CLASSES
        );
    }

    public function test_class_path_fields_and_lists_show_per_class_xp(): void
    {
        $root = dirname(__DIR__, 2);
        $fields = file_get_contents($root.'/resources/js/Components/ClassPathFields.tsx');
        $this->assertIsString($fields);
        $this->assertStringContainsString("label={path === 'dual' ? (i === 0 ? 'Original XP' : 'Current XP') : 'XP'}", $fields);

        $ts = file_get_contents($root.'/resources/js/lib/adnd2e.ts');
        $this->assertIsString($ts);
        $this->assertStringContainsString("Fighter: 'FR'", $ts);
        $this->assertStringContainsString("Mage: 'Wiz'", $ts);
        $this->assertStringContainsString('export function formatClassLevelsLine', $ts);
        $this->assertStringContainsString('export function formatClassXpLine', $ts);

        $summary = file_get_contents($root.'/resources/js/Components/ClassSummary.tsx');
        $this->assertIsString($summary);
        $this->assertStringContainsString('formatClassLevelsLine', $summary);
        $this->assertStringContainsString('whitespace-nowrap', $summary);
        $this->assertStringNotContainsString('truncate', $summary);

        foreach (['Index.tsx'] as $page) {
            $tsx = file_get_contents($root.'/resources/js/Pages/Characters/'.$page);
            $this->assertIsString($tsx);
            $this->assertStringContainsString('ClassSummary', $tsx);
            $this->assertStringNotContainsString('Lv {character.level}', $tsx);
        }

        $campaignShow = file_get_contents($root.'/resources/js/Pages/Campaigns/Show.tsx');
        $this->assertIsString($campaignShow);
        $this->assertStringContainsString('ClassSummary', $campaignShow);
        $this->assertStringContainsString('characterClassDisplay', $campaignShow);
        $this->assertStringContainsString('showXp={false}', $campaignShow);
        $this->assertStringNotContainsString('Level {character.level}', $campaignShow);
        $this->assertDoesNotMatchRegularExpression(
            '/className="text-xs text-\[var\(--color-text-dim\)\] mt-0\.5[^"]*truncate/',
            $campaignShow,
            'Campaign party class line must not use truncate/ellipsis.'
        );

        $batch = file_get_contents($root.'/resources/js/Pages/BatchSheets/Index.tsx');
        $this->assertIsString($batch);
        $this->assertStringContainsString('ClassSummary', $batch);
        $this->assertStringNotContainsString('Lv {character.level}', $batch);
    }
}
