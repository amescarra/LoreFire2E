<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AbilityScoreUiTest extends TestCase
{
    public function test_show_edit_live_and_pdf_render_full_ability_blocks(): void
    {
        $root = dirname(__DIR__, 2);
        $block = file_get_contents($root.'/resources/js/Components/AbilityScoreBlock.tsx');
        $show = file_get_contents($root.'/resources/js/Pages/Characters/Show.tsx');
        $edit = file_get_contents($root.'/resources/js/Pages/Characters/Edit.tsx');
        $create = file_get_contents($root.'/resources/js/Pages/Characters/Create.tsx');
        $live = file_get_contents($root.'/resources/js/Pages/Sessions/Live.tsx');
        $pdf = file_get_contents($root.'/resources/views/pdf/batch-sheets.blade.php');
        $engine = file_get_contents($root.'/resources/js/lib/adnd2e.ts');

        $this->assertIsString($block);
        $this->assertIsString($show);
        $this->assertIsString($edit);
        $this->assertIsString($create);
        $this->assertIsString($live);
        $this->assertIsString($pdf);
        $this->assertIsString($engine);

        $this->assertStringContainsString('abilityAdjustmentLines', $block);
        $this->assertStringContainsString('primaryAdjustmentLabel', $block);
        $this->assertStringContainsString('formatAbilityScore', $block);
        $this->assertStringContainsString('data-testid={`ability-block-${ability}`}', $block);

        $this->assertStringContainsString('AbilityScoreBlock', $show);
        $this->assertStringContainsString('variant="sheet"', $show);
        $this->assertStringNotContainsString('adj(key, character[key]', $show);

        $this->assertStringContainsString('AbilityScoreBlock', $edit);
        $this->assertStringContainsString('variant="form"', $edit);
        $this->assertStringContainsString('AbilityScoreBlock', $create);

        $this->assertStringContainsString('AbilityScoreBlock', $live);
        $this->assertStringContainsString('character.exceptional_strength', $live);
        $this->assertStringNotContainsString('primaryAdjustment(ability, score, null', $live);

        $this->assertStringContainsString('abilityAdjustmentLines', $pdf);
        $this->assertStringContainsString('formattedAbilityScore', $pdf);
        $this->assertStringContainsString('data-mod="{{ $abilityKey }}"', $pdf);

        $this->assertStringContainsString('weight_allow', $engine);
        $this->assertStringContainsString('chance_to_learn', $engine);
        $this->assertStringContainsString('wisdomSpellFailure', $engine);
        $this->assertStringContainsString('intelligenceLimits(score).languages - 2', $engine);
    }
}
