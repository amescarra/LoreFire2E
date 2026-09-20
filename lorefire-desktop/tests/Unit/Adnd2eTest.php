<?php

namespace Tests\Unit;

use App\Support\Adnd2e;
use PHPUnit\Framework\TestCase;

class Adnd2eTest extends TestCase
{
    public function test_thac0_follows_class_group_tables(): void
    {
        $this->assertSame(20, Adnd2e::thac0('Fighter', 1));
        $this->assertSame(19, Adnd2e::thac0('Fighter', 2));
        $this->assertSame(18, Adnd2e::thac0('Paladin', 3));
        $this->assertSame(20, Adnd2e::thac0('Mage', 1));
        $this->assertSame(20, Adnd2e::thac0('Mage', 3));
        $this->assertSame(18, Adnd2e::thac0('Cleric', 4));
        $this->assertSame(20, Adnd2e::thac0('Thief', 1));
        $this->assertSame(19, Adnd2e::thac0('Thief', 5));

        $this->assertSame(11, Adnd2e::thac0('Fighter', 10));
        $this->assertSame(10, Adnd2e::thac0('Fighter', 11));
        $this->assertSame(10, Adnd2e::thac0('Paladin', 11));
        $this->assertSame(11, Adnd2e::thac0('Ranger', 10));

        $this->assertSame(20, Adnd2e::thac0('Cleric', 3));
        $this->assertSame(18, Adnd2e::thac0('Druid', 6));
        $this->assertSame(16, Adnd2e::thac0('Cleric', 7));
        $this->assertSame(14, Adnd2e::thac0('Cleric', 10));

        $this->assertSame(20, Adnd2e::thac0('Mage', 5));
        $this->assertSame(19, Adnd2e::thac0('Mage', 6));
        $this->assertSame(19, Adnd2e::thac0('Mage', 10));
        $this->assertSame(16, Adnd2e::thac0('Mage', 11));

        $this->assertSame(20, Adnd2e::thac0('Thief', 4));
        $this->assertSame(19, Adnd2e::thac0('Bard', 8));
        $this->assertSame(16, Adnd2e::thac0('Thief', 9));
        $this->assertSame(16, Adnd2e::thac0('Psionicist', 9));
    }

    public function test_attack_uses_thac0_minus_descending_ac(): void
    {
        $hit = Adnd2e::resolveAttack(20, 4, 16);
        $this->assertTrue($hit['hit']);
        $this->assertSame(16, $hit['needed']);

        $miss = Adnd2e::resolveAttack(20, 4, 15);
        $this->assertFalse($miss['hit']);

        $this->assertFalse(Adnd2e::resolveAttack(20, 0, 1)['hit']);
        $this->assertTrue(Adnd2e::resolveAttack(20, 0, 20)['hit']);
    }

    public function test_saving_throws_and_hit_dice(): void
    {
        $saves = Adnd2e::savingThrows('Fighter', 1);
        $this->assertSame(14, $saves['paralyzation']);
        $this->assertSame(16, $saves['rod']);
        $this->assertSame(15, $saves['petrification']);
        $this->assertSame(17, $saves['breath']);
        $this->assertSame(17, $saves['spell']);

        $this->assertSame('d10', Adnd2e::hitDie('Fighter'));
        $this->assertSame('d4', Adnd2e::hitDie('Mage'));
        $this->assertSame('d8', Adnd2e::hitDie('Cleric'));
        $this->assertSame('d6', Adnd2e::hitDie('Thief'));
    }

    public function test_memorization_capacity_includes_specialist_bonus(): void
    {
        $mage = Adnd2e::memorizationCapacity('Mage', 1, 10);
        $this->assertSame(1, $mage[1]);

        $illusionist = Adnd2e::memorizationCapacity('Mage', 1, 10, 'Illusionist');
        $this->assertSame(2, $illusionist[1]);

        $cleric = Adnd2e::memorizationCapacity('Cleric', 1, 17);
        $this->assertGreaterThan(1, $cleric[1]);
    }

    public function test_overnight_rest_recovers_one_hit_point_and_resets_daily_abilities(): void
    {
        $result = Adnd2e::overnightRest(4, 10, 'Paladin', 5, [
            'lay_on_hands_current' => 0,
        ]);

        $this->assertSame(5, $result['current_hp']);
        $this->assertNull($result['memorization_used']);
        $this->assertSame(10, $result['class_features']['lay_on_hands_max']);
        $this->assertSame(10, $result['class_features']['lay_on_hands_current']);
        $this->assertTrue($result['class_features']['detect_evil_ready']);
        $this->assertTrue($result['class_features']['turn_undead_ready']);
    }

    public function test_vitality_states(): void
    {
        $this->assertSame('ok', Adnd2e::vitalityState(1));
        $this->assertSame('unconscious', Adnd2e::vitalityState(0));
        $this->assertSame('dying', Adnd2e::vitalityState(-3));
        $this->assertSame('dead', Adnd2e::vitalityState(-10));
    }

    public function test_defaults_for_new_character(): void
    {
        $defaults = Adnd2e::defaultsFor('Mage', 1, 'Elf', 10);
        $this->assertSame(20, $defaults['thac0']);
        $this->assertSame('d4', $defaults['hit_die']);
        $this->assertSame(12, $defaults['speed']);
        $this->assertSame(1, $defaults['memorization'][1]);
    }

    public function test_rewrites_and_rejects_fifth_edition_class_names(): void
    {
        $this->assertSame('Mage', Adnd2e::rewriteLegacyClass('Warlock')['class']);
        $this->assertTrue(Adnd2e::rewriteLegacyClass('Warlock')['mapped']);
        $this->assertSame('Thief', Adnd2e::rewriteLegacyClass('Rogue')['class']);
        $this->assertSame('Fighter', Adnd2e::rewriteLegacyClass('Barbarian')['class']);
        $this->assertSame('Psionicist', Adnd2e::rewriteLegacyClass('Psion')['class']);
        $this->assertTrue(Adnd2e::rewriteLegacyClass('Psion')['mapped']);
        $this->assertSame('Psionicist', Adnd2e::rewriteLegacyClass('Psionics')['class']);
        $this->assertSame('Psionicist', Adnd2e::rewriteLegacyClass('Psionic')['class']);
        $this->assertSame('Psionicist', Adnd2e::rewriteLegacyClass('Psionicist')['class']);
        $this->assertFalse(Adnd2e::rewriteLegacyClass('Psionicist')['rejected']);
        $unknown = Adnd2e::rewriteLegacyClass('Echo Knight');
        $this->assertTrue($unknown['rejected']);
        $this->assertSame('Fighter', $unknown['class']);
    }

    public function test_fighter_and_psionicist_combine_thac0_and_hit_dice(): void
    {
        $this->assertContains('Psionicist', Adnd2e::CLASSES);
        $this->assertTrue(Adnd2e::hasPsionicist([
            ['class' => 'Fighter', 'level' => 10],
            ['class' => 'Psionics', 'level' => 9],
        ], 'Fighter', 10, 'multi'));
        $this->assertFalse(Adnd2e::hasPsionicist([
            ['class' => 'Fighter', 'level' => 10],
        ], 'Fighter', 10, 'single'));
        $this->assertSame('rogue', Adnd2e::classGroup('Psionicist'));
        $this->assertSame('d6', Adnd2e::hitDie('Psionicist'));
        $this->assertSame(Adnd2e::thac0('Thief', 9), Adnd2e::thac0('Psionicist', 9));
        $this->assertSame(Adnd2e::savingThrows('Thief', 9), Adnd2e::savingThrows('Psionicist', 9));

        $entries = Adnd2e::normalizeClassLevels([
            ['class' => 'Fighter', 'level' => 10],
            ['class' => 'Psion', 'level' => 9],
        ], 'Fighter/Psion', 10, 'multi');

        $this->assertSame('Fighter', $entries[0]['class']);
        $this->assertSame('Psionicist', $entries[1]['class']);
        $this->assertSame('Fighter/Psionicist', Adnd2e::displayClassName($entries, 'multi'));
        $this->assertSame(11, Adnd2e::combinedThac0($entries));
        $this->assertSame('d10/d6', Adnd2e::combinedHitDie($entries));

        $dual = Adnd2e::normalizeClassLevels([
            ['class' => 'Psionicist', 'level' => 9],
            ['class' => 'Fighter', 'level' => 10],
        ], 'Psionicist', 9, 'dual');
        $this->assertSame('Psionicist → Fighter', Adnd2e::displayClassName($dual, 'dual'));
        $this->assertSame(10, Adnd2e::displayLevel($dual, 'dual'));
        $this->assertSame(11, Adnd2e::combinedThac0($dual));
        $this->assertTrue(Adnd2e::canResumeOriginalClass(10));
        $this->assertTrue(Adnd2e::dualResumeAllowed($dual));
    }

    public function test_house_dual_switch_gates(): void
    {
        $this->assertSame(5, Adnd2e::HOUSE_DUAL_MIN_ORIGINAL_LEVEL - 1);
        $this->assertSame(5, Adnd2e::HOUSE_DUAL_RESUME_NEW_LEVEL);

        $this->assertFalse(Adnd2e::canBeginNewClass(5));
        $this->assertTrue(Adnd2e::canBeginNewClass(6));
        $this->assertTrue(Adnd2e::canBeginNewClass(9));

        $atSwitch = Adnd2e::normalizeClassLevels([
            ['class' => 'Psionicist', 'level' => 6],
            ['class' => 'Fighter', 'level' => 1],
        ], 'Psionicist', 6, 'dual');
        $this->assertTrue(Adnd2e::canBeginNewClass(6));
        $this->assertFalse(Adnd2e::canResumeOriginalClass(4));
        $this->assertFalse(Adnd2e::dualResumeAllowed($atSwitch));

        $fighter5 = Adnd2e::normalizeClassLevels([
            ['class' => 'Psionicist', 'level' => 6],
            ['class' => 'Fighter', 'level' => 5],
        ], 'Psionicist', 6, 'dual');
        $this->assertTrue(Adnd2e::canResumeOriginalClass(5));
        $this->assertTrue(Adnd2e::dualResumeAllowed($fighter5));

        $dual = Adnd2e::normalizeClassLevels([
            ['class' => 'Psionicist', 'level' => 9],
            ['class' => 'Fighter', 'level' => 10],
        ], 'Psionicist', 9, 'dual');
        $this->assertTrue(Adnd2e::dualResumeAllowed($dual));
        $this->assertTrue(Adnd2e::canResumeOriginalClass(10));

        $psi9fighter5 = Adnd2e::normalizeClassLevels([
            ['class' => 'Psionicist', 'level' => 9],
            ['class' => 'Fighter', 'level' => 5],
        ], 'Psionicist', 9, 'dual');
        $this->assertTrue(Adnd2e::dualResumeAllowed($psi9fighter5), 'Resume is 5th in the new class, not 9−1=8 of current original.');

        $this->assertFalse(Adnd2e::hasStoredDualSwitch('single', [['class' => 'Fighter', 'level' => 5]], 'Fighter', 5));
        $this->assertTrue(Adnd2e::hasStoredDualSwitch('dual', [
            ['class' => 'Fighter', 'level' => 4],
            ['class' => 'Mage', 'level' => 1],
        ], 'Fighter', 4));
    }

    public function test_vancian_spell_copies_and_cast_burn_one_instance(): void
    {
        $this->assertSame(2, Adnd2e::timesMemorizedFromInput(2, false));
        $this->assertSame(1, Adnd2e::timesMemorizedFromInput(null, true));
        $this->assertSame(0, Adnd2e::timesMemorizedFromInput(0, true));
        $this->assertSame(1, Adnd2e::effectiveTimesMemorized(0, true));

        $two = Adnd2e::spellVancianFlags(2, 0);
        $this->assertTrue($two['is_prepared']);
        $this->assertFalse($two['is_cast']);
        $this->assertSame(2, Adnd2e::remainingMemorized(2, 0));

        $afterOne = Adnd2e::burnMemorizedInstance(2, 0);
        $this->assertSame(1, $afterOne['times_cast']);
        $this->assertFalse($afterOne['is_cast']);
        $this->assertSame(1, Adnd2e::remainingMemorized($afterOne['times_memorized'], $afterOne['times_cast']));

        $afterTwo = Adnd2e::burnMemorizedInstance(2, 1);
        $this->assertTrue($afterTwo['is_cast']);
        $this->assertSame(2, $afterTwo['times_cast']);

        $capped = Adnd2e::burnMemorizedInstance(2, 2);
        $this->assertSame(2, $capped['times_cast']);

        $restored = Adnd2e::restoreMemorizedInstance(2, 2);
        $this->assertSame(1, $restored['times_cast']);
        $this->assertFalse($restored['is_cast']);

        $this->assertSame(['is_cast' => false, 'times_cast' => 0], Adnd2e::rememorizeSpellFields());

        $copies = Adnd2e::memorizedCopyTotal([
            ['name' => 'Magic Missile', 'times_memorized' => 3, 'is_prepared' => true],
            ['name' => 'Sleep', 'times_memorized' => 0, 'is_prepared' => false],
        ]);
        $this->assertSame(3, $copies);
        $this->assertSame(4, Adnd2e::slotCapacityAtLevel(['1' => 4, '2' => 2], 1));
        $this->assertLessThanOrEqual(Adnd2e::slotCapacityAtLevel(['1' => 4], 1), $copies);
    }

    public function test_multi_class_uses_best_thac0_and_saves(): void
    {
        $entries = Adnd2e::normalizeClassLevels(null, 'Fighter/Mage', 5, 'multi');
        $this->assertCount(2, $entries);
        $this->assertSame('Fighter/Mage', Adnd2e::displayClassName($entries, 'multi'));
        $this->assertSame(16, Adnd2e::combinedThac0($entries));
        $this->assertSame('d10/d4', Adnd2e::combinedHitDie($entries));
        $saves = Adnd2e::combinedSavingThrows($entries);
        $this->assertSame(11, $saves['rod']);
    }

    public function test_weapon_speed_factors(): void
    {
        $this->assertSame(2, Adnd2e::weaponSpeed('Dagger'));
        $this->assertSame(5, Adnd2e::weaponSpeed('long sword'));
        $this->assertSame(10, Adnd2e::weaponSpeed('Two-handed sword'));
        $this->assertNull(Adnd2e::weaponSpeed('Mysterious orb'));
    }

    public function test_weapon_stats_include_dice_and_speed(): void
    {
        $long = Adnd2e::weaponStats('long sword');
        $this->assertNotNull($long);
        $this->assertSame('1d8', $long['sm']);
        $this->assertSame('1d12', $long['l']);
        $this->assertSame(5, $long['speed']);
        $this->assertSame(Adnd2e::weaponSpeed('Dagger'), Adnd2e::weaponStats('Dagger')['speed'] ?? null);
        $this->assertNotEmpty(Adnd2e::weaponCatalog());
    }

    public function test_armor_base_ac_and_shield_bonus(): void
    {
        $this->assertSame(10, Adnd2e::armorBaseAc('none'));
        $this->assertSame(8, Adnd2e::armorBaseAc('leather'));
        $this->assertSame(5, Adnd2e::armorBaseAc('chain mail'));
        $this->assertSame(3, Adnd2e::armorBaseAc('plate mail'));
        $this->assertSame(1, Adnd2e::armorBaseAc('full plate'));
        $this->assertSame(4, Adnd2e::descendingArmorClass('chain mail', true));
        $this->assertSame(-1, Adnd2e::SHIELD_AC_BONUS);
        $this->assertNull(Adnd2e::armorBaseAc('Mysterious robe'));
    }

    public function test_thac0_progression_covers_all_groups_to_20(): void
    {
        $fighter = Adnd2e::thac0Progression('Fighter');
        $this->assertCount(20, $fighter);
        $this->assertSame(20, $fighter[1]);
        $this->assertSame(16, $fighter[5]);
        $this->assertSame(11, $fighter[10]);
        $this->assertSame(Adnd2e::thac0('Paladin', 7), Adnd2e::thac0('Fighter', 7));
        $this->assertSame(Adnd2e::thac0('Ranger', 3), Adnd2e::thac0('Fighter', 3));
        $this->assertSame(Adnd2e::thac0('Druid', 4), Adnd2e::thac0('Cleric', 4));
        $this->assertSame(Adnd2e::thac0('Bard', 5), Adnd2e::thac0('Thief', 5));
        $this->assertSame(Adnd2e::thac0('Psionicist', 9), Adnd2e::thac0('Thief', 9));
        $this->assertSame(19, Adnd2e::thac0('Mage', 6));
    }

    public function test_saving_throw_bands_and_category_lookup(): void
    {
        $this->assertSame(14, Adnd2e::savingThrow('Fighter', 1, 'paralyzation'));
        $bands = Adnd2e::savingThrowBands('Fighter');
        $this->assertSame(1, $bands[0]['from']);
        $this->assertSame(2, $bands[0]['to']);
        $this->assertSame(14, $bands[0]['saves']['paralyzation']);
        $this->assertSame(Adnd2e::savingThrows('Cleric', 1), Adnd2e::savingThrows('Druid', 1));
        $this->assertSame(Adnd2e::savingThrows('Thief', 8), Adnd2e::savingThrows('Bard', 8));
    }

    public function test_priest_spheres_are_tag_lists(): void
    {
        $cleric = Adnd2e::priestSpheres('Cleric');
        $this->assertContains('Healing', $cleric['major']);
        $this->assertContains('Elemental', $cleric['minor']);
        $this->assertNotContains('Animal', $cleric['major']);
        $druid = Adnd2e::priestSpheres('Druid');
        $this->assertContains('Weather', $druid['major']);
        $this->assertContains('Divination', $druid['minor']);
        $this->assertSame(['Combat', 'Divination', 'Healing', 'Protection'], Adnd2e::priestSpheres('Paladin')['minor']);
        $this->assertSame(['major' => [], 'minor' => []], Adnd2e::priestSpheres('Mage'));
        $this->assertSame($cleric, Adnd2e::defaultsFor('Cleric', 1, 'Human')['priest_spheres']);
    }

    public function test_wizard_and_priest_memorization_progression(): void
    {
        $wizard = Adnd2e::memorizationProgression('Mage', 10);
        $this->assertSame([1 => 1], $wizard[1]);
        $this->assertSame([1 => 4, 2 => 2, 3 => 1], $wizard[5]);
        $priest = Adnd2e::memorizationCapacity('Cleric', 5, 10);
        $this->assertSame(3, $priest[1]);
        $this->assertSame(1, $priest[3]);
        $this->assertArrayNotHasKey(4, $priest);
    }

    public function test_encumbrance_uses_strength_weight_allowance(): void
    {
        $t = Adnd2e::encumbranceThresholds(10);
        $this->assertSame(40, $t['none']);
        $this->assertSame(40, $t['weight_allow']);
        $this->assertSame(115, $t['max_press']);
        $this->assertSame(115, $t['severe']);
        $this->assertSame('none', Adnd2e::encumbranceCategory(40, 10));
        $this->assertSame('light', Adnd2e::encumbranceCategory(41, 10));
        $this->assertSame(12, Adnd2e::movementAtEncumbrance('Human', 'none'));
        $this->assertSame(9, Adnd2e::movementAtEncumbrance('Human', 'light'));
        $this->assertSame(6, Adnd2e::movementAtEncumbrance('Human', 'moderate'));
        $this->assertSame(4, Adnd2e::movementAtEncumbrance('Human', 'heavy'));
        $this->assertSame(1, Adnd2e::movementAtEncumbrance('Human', 'severe'));
        $this->assertSame(6, Adnd2e::movementAtEncumbrance('Dwarf', 'none'));
        $this->assertSame(4, Adnd2e::movementAtEncumbrance('Dwarf', 'light'));
        $exc = Adnd2e::encumbranceThresholds(18, '01');
        $this->assertSame(135, $exc['weight_allow']);
        $load = Adnd2e::movementAtLoad('Human', 40, 10);
        $this->assertSame('none', $load['category']);
        $this->assertSame(12, $load['movement']);
    }

    public function test_elf_fighter_mage_sees_bladesinger_human_mage_does_not(): void
    {
        $elfFm = Adnd2e::suggestedRacialKits('Elf', [
            ['class' => 'Fighter', 'level' => 1],
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertContains('Bladesinger', $elfFm);
        $this->assertContains('War Wizard', $elfFm);
        $this->assertNotContains('Battlerager', $elfFm);

        $humanMage = Adnd2e::suggestedRacialKits('Human', [
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertNotContains('Bladesinger', $humanMage);
        $this->assertSame([], $humanMage);

        $humanOptions = Adnd2e::suggestedSubclassOptions('Human', [
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertContains('Illusionist', $humanOptions);
        $this->assertNotContains('Bladesinger', $humanOptions);

        $elfMage = Adnd2e::suggestedRacialKits('Elf', [
            ['class' => 'Mage', 'level' => 1],
        ]);
        $this->assertNotContains('Bladesinger', $elfMage);
        $this->assertContains('Undead Slayer', $elfMage);
    }

    public function test_dwarf_fighter_sees_dwarf_kits_elf_does_not(): void
    {
        $dwarf = Adnd2e::suggestedRacialKits('Dwarf', [
            ['class' => 'Fighter', 'level' => 1],
        ]);
        $this->assertContains('Battlerager', $dwarf);
        $this->assertContains('Clansdwarf', $dwarf);
        $this->assertNotContains('Bladesinger', $dwarf);
        $this->assertNotContains('Champion', $dwarf);

        $elf = Adnd2e::suggestedRacialKits('Elf', [
            ['class' => 'Fighter', 'level' => 1],
        ]);
        $this->assertNotContains('Battlerager', $elf);
        $this->assertNotContains('Bladesinger', $elf);
        $this->assertContains('Archer', $elf);
    }

    public function test_class_abbreviations_cover_all_classes(): void
    {
        $expected = [
            'Fighter' => 'FR',
            'Paladin' => 'PAL',
            'Ranger' => 'RAN',
            'Mage' => 'Wiz',
            'Cleric' => 'CLR',
            'Druid' => 'DRU',
            'Thief' => 'TH',
            'Bard' => 'BRD',
            'Psionicist' => 'PSI',
        ];
        $this->assertSame($expected, Adnd2e::CLASS_ABBREVIATIONS);
        foreach (Adnd2e::CLASSES as $class) {
            $this->assertArrayHasKey($class, Adnd2e::CLASS_ABBREVIATIONS);
            $this->assertSame($expected[$class], Adnd2e::classAbbreviation($class));
        }
        $this->assertSame('Wiz', Adnd2e::classAbbreviation('Illusionist'));
        $this->assertSame('Wiz', Adnd2e::classAbbreviation('Wizard'));
        $this->assertSame('TH', Adnd2e::classAbbreviation('Rogue'));
    }

    public function test_format_class_levels_line_for_multi_dual_and_single(): void
    {
        $this->assertSame('FR 11 / Wiz 12', Adnd2e::formatClassLevelsLine([
            ['class' => 'Fighter', 'level' => 11],
            ['class' => 'Mage', 'level' => 12],
        ], 'multi'));
        $this->assertSame('PSI 9 → FR 10', Adnd2e::formatClassLevelsLine([
            ['class' => 'Psionicist', 'level' => 9],
            ['class' => 'Fighter', 'level' => 10],
        ], 'dual'));
        $this->assertSame('CLR 10', Adnd2e::formatClassLevelsLine([
            ['class' => 'Cleric', 'level' => 10],
        ], 'single'));
        $this->assertSame('FR 10 / Wiz 13 / CLR 11', Adnd2e::formatClassLevelsLine([
            ['class' => 'Fighter', 'level' => 10],
            ['class' => 'Mage', 'level' => 13],
            ['class' => 'Cleric', 'level' => 11],
        ], 'multi'));
    }

    public function test_normalize_preserves_per_class_xp_when_present(): void
    {
        $entries = Adnd2e::normalizeClassLevels([
            ['class' => 'Fighter', 'level' => 11, 'xp' => 500000],
            ['class' => 'Mage', 'level' => 12, 'xp' => 750000],
        ], 'Fighter/Mage', 12, 'multi');

        $this->assertSame(500000, $entries[0]['xp']);
        $this->assertSame(750000, $entries[1]['xp']);
        $this->assertArrayNotHasKey('xp', Adnd2e::normalizeClassLevels([
            ['class' => 'Fighter', 'level' => 5],
        ], 'Fighter', 5, 'single')[0]);
        $this->assertSame(1250000, Adnd2e::derivedExperiencePoints($entries, 0));
    }

    public function test_backfill_xp_rules_do_not_split_multi_class(): void
    {
        $single = Adnd2e::backfillClassLevelsXp(
            [['class' => 'Cleric', 'level' => 10]],
            'single',
            250000,
        );
        $this->assertSame(250000, $single[0]['xp']);

        $dual = Adnd2e::backfillClassLevelsXp([
            ['class' => 'Psionicist', 'level' => 9],
            ['class' => 'Fighter', 'level' => 10],
        ], 'dual', 760016);
        $this->assertArrayNotHasKey('xp', $dual[0]);
        $this->assertSame(760016, $dual[1]['xp']);

        $multi = Adnd2e::backfillClassLevelsXp([
            ['class' => 'Fighter', 'level' => 10],
            ['class' => 'Mage', 'level' => 13],
            ['class' => 'Cleric', 'level' => 11],
        ], 'multi', 1275942);
        $this->assertArrayNotHasKey('xp', $multi[0]);
        $this->assertArrayNotHasKey('xp', $multi[1]);
        $this->assertArrayNotHasKey('xp', $multi[2]);

        $this->assertSame('FR 500k · Wiz 750k', Adnd2e::formatClassXpLine([
            ['class' => 'Fighter', 'level' => 11, 'xp' => 500000],
            ['class' => 'Mage', 'level' => 12, 'xp' => 750000],
        ], 'multi', true, true));
        $this->assertSame('PSI — · FR 760,016', Adnd2e::formatClassXpLine($dual, 'dual', false, false));
    }

    public function test_ability_tables_include_full_2e_columns_and_exceptional_strength(): void
    {
        $plain18 = Adnd2e::strengthAdjustments(18, null);
        $this->assertSame(1, $plain18['hit']);
        $this->assertSame(2, $plain18['damage']);
        $this->assertSame(110, $plain18['weight_allow']);
        $this->assertSame(255, $plain18['max_press']);
        $this->assertSame('11', $plain18['open_doors']);
        $this->assertSame(16, $plain18['bend_bars']);

        $thurmbog = Adnd2e::strengthAdjustments(18, '67');
        $this->assertSame(2, $thurmbog['hit']);
        $this->assertSame(3, $thurmbog['damage']);
        $this->assertSame(160, $thurmbog['weight_allow']);
        $this->assertSame(305, $thurmbog['max_press']);
        $this->assertSame('13', $thurmbog['open_doors']);
        $this->assertSame(25, $thurmbog['bend_bars']);
        $this->assertSame('18/67', Adnd2e::formatAbilityScore('strength', 18, '67'));
        $this->assertSame('+2', Adnd2e::formatSigned(Adnd2e::primaryAdjustment('strength', 18, '67')));
        $this->assertSame('hit', Adnd2e::primaryAdjustmentLabel('strength'));

        $lines = Adnd2e::abilityAdjustmentLines('strength', 18, '67');
        $this->assertSame(['hit', 'dmg', 'wt', 'press', 'open', 'BB'], array_column($lines, 'label'));
        $this->assertContains('25%', array_column($lines, 'value'));

        $dex = Adnd2e::dexterityAdjustments(16);
        $this->assertSame(['reaction' => 1, 'missile' => 1, 'defensive' => -2], $dex);
        $this->assertSame('missile', Adnd2e::primaryAdjustmentLabel('dexterity'));

        $con = Adnd2e::constitutionAdjustments(16, 'Fighter');
        $this->assertSame(2, $con['hp']);
        $this->assertSame(95, $con['system_shock']);
        $this->assertSame(96, $con['resurrection']);
        $this->assertSame(0, $con['poison_save']);
        $this->assertNull($con['regeneration']);

        $int = Adnd2e::intelligenceLimits(16);
        $this->assertSame(5, $int['languages']);
        $this->assertSame(8, $int['max_spell_level']);
        $this->assertSame(70, $int['chance_to_learn']);
        $this->assertSame(11, $int['max_spells_per_level']);
        $this->assertSame(3, Adnd2e::primaryAdjustment('intelligence', 16));

        $wisLines = Adnd2e::abilityAdjustmentLines('wisdom', 16);
        $this->assertSame(['MD', 'bonus', 'fail'], array_column($wisLines, 'label'));
        $this->assertSame('+2', $wisLines[0]['value']);
        $this->assertSame('1st×2, 2nd×2', $wisLines[1]['value']);
        $this->assertSame('0%', $wisLines[2]['value']);

        $cha = Adnd2e::charismaAdjustments(16);
        $this->assertSame(8, $cha['max_henchmen']);
        $this->assertSame(4, $cha['loyalty']);
        $this->assertSame(5, $cha['reaction']);

        $this->assertSame(3, Adnd2e::wisdomBonusSpells(19)[1]);
        $this->assertSame(8, Adnd2e::intelligenceLimits(19)['languages']);
        $this->assertSame('all', Adnd2e::abilityAdjustmentLines('intelligence', 19)[3]['value']);
        $this->assertSame(10, Adnd2e::charismaAdjustments(19)['loyalty']);
        $this->assertSame(4, Adnd2e::strengthAdjustments(21)['hit']);
        $this->assertSame(70, Adnd2e::strengthAdjustments(21)['bend_bars']);
        $this->assertSame(80, Adnd2e::strengthAdjustments(22)['bend_bars']);
        $this->assertSame(2, Adnd2e::wisdomAdjustments(16)['magical_defense']);
    }
}
