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

    public function test_attack_hits_uses_thac0_minus_ac_and_modifiers(): void
    {
        $this->assertTrue(RuleKernel::attack_hits(20, 4, 16, 0));
        $this->assertFalse(RuleKernel::attack_hits(20, 4, 15, 0));
        $this->assertTrue(RuleKernel::attack_hits(20, 4, 15, 1));
        $this->assertFalse(RuleKernel::attack_hits(20, 0, 1, 20));
        $this->assertTrue(RuleKernel::attack_hits(20, 0, 20, -20));
        $this->assertTrue(Adnd2e::resolveAttack(20, 4, 15, 1)['hit']);
    }

    public function test_save_succeeds_is_roll_plus_modifiers_versus_target(): void
    {
        $this->assertTrue(RuleKernel::save_succeeds(14, 14, 0));
        $this->assertFalse(RuleKernel::save_succeeds(14, 13, 0));
        $this->assertTrue(RuleKernel::save_succeeds(14, 13, 1));
        $this->assertFalse(RuleKernel::save_succeeds(20, 1, 20));
        $this->assertTrue(RuleKernel::save_succeeds(20, 20, -20));
    }

    public function test_initiative_order_is_lower_total_first(): void
    {
        $order = RuleKernel::initiative_order([
            ['name' => 'A', 'roll' => 7, 'modifiers' => 0],
            ['name' => 'B', 'roll' => 3, 'modifiers' => 0],
            ['name' => 'C', 'roll' => 3, 'modifiers' => 1],
        ]);
        $this->assertSame(['B', 'C', 'A'], array_column($order, 'name'));
        $this->assertSame(3, $order[0]['total']);
        $this->assertSame(1, $order[0]['order']);
        $this->assertSame(4, $order[1]['total']);
        $this->assertSame(7, $order[2]['total']);
    }

    public function test_surprise_segments_use_the_surprised_sides_roll(): void
    {
        $a = RuleKernel::surprise_segments(3, 7);
        $this->assertTrue($a['a_surprised']);
        $this->assertFalse($a['b_surprised']);
        $this->assertSame(3, $a['segments']);
        $this->assertSame('a', $a['surprised_side']);

        $both = RuleKernel::surprise_segments(2, 1);
        $this->assertSame(0, $both['segments']);
        $this->assertNull($both['surprised_side']);

        $none = RuleKernel::surprise_segments(8, 9);
        $this->assertSame(0, $none['segments']);

        $b = RuleKernel::surprise_segments(6, 2);
        $this->assertSame(2, $b['segments']);
        $this->assertSame('b', $b['surprised_side']);
    }

    public function test_morale_check_fails_when_roll_exceeds_morale(): void
    {
        $pass = RuleKernel::morale_check(12, 11, 0);
        $this->assertTrue($pass['passed']);
        $this->assertSame(11, $pass['total']);

        $tie = RuleKernel::morale_check(12, 12, 0);
        $this->assertTrue($tie['passed']);

        $fail = RuleKernel::morale_check(12, 13, 0);
        $this->assertFalse($fail['passed']);

        $modFail = RuleKernel::morale_check(12, 11, 2);
        $this->assertFalse($modFail['passed']);
        $this->assertSame(13, $modFail['total']);
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
