<?php

namespace Tests\Unit;

use App\Support\Adnd2e;
use App\Support\RuleKernel;
use App\Support\TableLaw;
use PHPUnit\Framework\TestCase;

class RuleKernelTest extends TestCase
{
    public function test_dying_state_is_slain_at_zero_or_below(): void
    {
        $this->assertSame('ok', RuleKernel::dying_state(1));
        $this->assertSame('slain', RuleKernel::dying_state(0));
        $this->assertSame('slain', RuleKernel::dying_state(-1));
        $this->assertSame('slain', RuleKernel::dying_state(-10, 'phb_zero', TableLaw::defaults()));

        $this->assertSame('slain', Adnd2e::dying_state(0));
        $this->assertSame('slain', Adnd2e::dyingState(0));
        $this->assertSame('slain', Adnd2e::vitalityState(0));
        $this->assertSame('ok', Adnd2e::vitalityState(1));
    }

    public function test_massive_damage_check_is_separate_from_hit_point_death(): void
    {
        $under = RuleKernel::massive_damage_check(49, 0);
        $this->assertFalse($under['applies']);
        $this->assertFalse($under['save_required']);
        $this->assertFalse($under['slain']);
        $this->assertNull($under['saved']);

        $failed = Adnd2e::massive_damage_check(50, 0);
        $this->assertTrue($failed['applies']);
        $this->assertTrue($failed['save_required']);
        $this->assertTrue($failed['slain']);
        $this->assertFalse($failed['saved']);

        $made = Adnd2e::massiveDamageCheck(80, 14);
        $this->assertTrue($made['applies']);
        $this->assertFalse($made['slain']);
        $this->assertTrue($made['saved']);
    }

    public function test_hp_clamp_does_not_store_a_dying_band_below_zero(): void
    {
        $this->assertSame(0, Adnd2e::clampCurrentHp(-10, 12));
        $this->assertSame(0, Adnd2e::clampCurrentHp(0, 12));
        $this->assertSame(8, Adnd2e::clampCurrentHp(8, 12));
        $this->assertSame(12, Adnd2e::clampCurrentHp(20, 12));
    }

    public function test_overnight_rest_does_not_heal_a_slain_character(): void
    {
        $slain = Adnd2e::overnightRest(0, 10, 'Fighter', 1);
        $this->assertSame(0, $slain['current_hp']);

        $alive = Adnd2e::overnightRest(4, 10, 'Fighter', 1);
        $this->assertSame(5, $alive['current_hp']);
    }

    public function test_kit_field_kind_distinguishes_specialist_school(): void
    {
        $this->assertSame('kit', Adnd2e::kitFieldKind('Bladesinger'));
        $this->assertSame('specialist school', Adnd2e::kitFieldKind('Illusionist'));
        $this->assertNull(Adnd2e::kitFieldKind(null));
        $this->assertNull(Adnd2e::kitFieldKind(''));
    }

    public function test_dual_class_gate_is_table_law_not_phb_core(): void
    {
        $this->assertSame(6, TableLaw::DUAL_CLASS_HOUSE_SWITCH_MIN_ORIGINAL_LEVEL);
        $this->assertSame(5, TableLaw::DUAL_CLASS_HOUSE_SWITCH_RESUME_NEW_LEVEL);
        $this->assertSame(6, Adnd2e::HOUSE_DUAL_MIN_ORIGINAL_LEVEL);
        $this->assertSame(5, Adnd2e::HOUSE_DUAL_RESUME_NEW_LEVEL);
        $this->assertFalse(Adnd2e::canBeginNewClass(5));
        $this->assertTrue(Adnd2e::canBeginNewClass(6));
        $this->assertTrue(Adnd2e::canResumeOriginalClass(5));

        $php = file_get_contents(dirname(__DIR__, 2).'/app/Support/Adnd2e.php');
        $this->assertIsString($php);
        $this->assertStringContainsString('TABLE LAW', $php);
        $this->assertStringNotContainsString('This table switch is PHB', $php);

        $root = dirname(__DIR__, 2);
        foreach ([
            $root.'/resources/js/Pages/Characters/Show.tsx',
            $root.'/resources/js/Pages/Characters/Edit.tsx',
            $root.'/resources/js/Pages/Sessions/Live.tsx',
            $root.'/app/Support/Adnd2eOracleBriefing.php',
            $root.'/app/Support/Adnd2eOracleRulesLookup.php',
        ] as $path) {
            $text = file_get_contents($path);
            $this->assertIsString($text);
            $this->assertStringNotContainsString('DEATH_THRESHOLD', $text);
            $this->assertStringNotContainsString('-10 dead', $text);
            $this->assertStringNotContainsString('unconscious at 0', $text);
            $this->assertStringNotContainsString('0 unconscious', $text);
        }
    }
}
