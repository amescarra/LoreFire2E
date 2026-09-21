<?php

namespace App\Support;

/**
 * Named 2E procedures used by the sheet and Oracle.
 *
 * Wording is original. Do not paste rulebook prose here.
 */
class RuleKernel
{
    public const DEATH_MODE_PHB_ZERO = 'phb_zero';

    public const MASSIVE_DAMAGE_THRESHOLD = 50;

    public const SURPRISE_THRESHOLD = 3;

    public const ORACLE_LABEL = 'KERNEL';

    /**
     * PHB 1989: at 0 hit points the character is slain.
     * The old DMG optional survival to -10 is not used.
     *
     * @param  array<string, mixed>  $table_law
     */
    public static function dying_state(int $hp, string $death_mode = 'phb_zero', array $table_law = []): string
    {
        $mode = $death_mode !== ''
            ? $death_mode
            : (string) ($table_law['death_mode'] ?? TableLaw::DEATH_MODE);
        // Only phb_zero is live. The old DMG optional survival to -10 is not used.
        if ($mode !== self::DEATH_MODE_PHB_ZERO) {
            $mode = self::DEATH_MODE_PHB_ZERO;
        }

        if ($hp <= 0) {
            return 'slain';
        }

        return 'ok';
    }

    /**
     * 50 or more from one attack: save versus death or die.
     * Separate from hit-point death. save_roll is the d20; pass 0 for a failed save.
     *
     * @return array{applies: bool, save_required: bool, saved: bool|null, slain: bool, save_roll: int}
     */
    public static function massive_damage_check(int $damage, int $save_roll): array
    {
        $applies = $damage >= self::MASSIVE_DAMAGE_THRESHOLD;
        if (! $applies) {
            return [
                'applies' => false,
                'save_required' => false,
                'saved' => null,
                'slain' => false,
                'save_roll' => $save_roll,
            ];
        }

        $saved = $save_roll > 0;

        return [
            'applies' => true,
            'save_required' => true,
            'saved' => $saved,
            'slain' => ! $saved,
            'save_roll' => $save_roll,
        ];
    }

    /**
     * d20 vs descending AC. Number needed = THAC0 minus AC, then add modifiers to the roll.
     * This app treats 1 as a miss and 20 as a hit.
     */
    public static function attack_hits(int $thac0, int $ac, int $roll, int $modifiers = 0): bool
    {
        if ($roll === 1) {
            return false;
        }
        if ($roll === 20) {
            return true;
        }

        $needed = $thac0 - $ac;

        return ($roll + $modifiers) >= $needed;
    }

    /**
     * d20 save. Succeeds if roll + modifiers >= target.
     * This app treats 1 as a failure and 20 as a success.
     */
    public static function save_succeeds(int $target, int $roll, int $modifiers = 0): bool
    {
        if ($roll === 1) {
            return false;
        }
        if ($roll === 20) {
            return true;
        }

        return ($roll + $modifiers) >= $target;
    }

    /**
     * d10 initiative, lower total acts first. Ties keep input order.
     *
     * @param  list<array{name?: string, id?: int|string, roll: int, modifiers?: int}>  $combatants
     * @return list<array{name: string, roll: int, modifiers: int, total: int, order: int}>
     */
    public static function initiative_order(array $combatants): array
    {
        $indexed = [];
        foreach ($combatants as $i => $row) {
            $roll = (int) ($row['roll'] ?? 0);
            $modifiers = (int) ($row['modifiers'] ?? 0);
            $name = (string) ($row['name'] ?? $row['id'] ?? ('combatant-'.$i));
            $indexed[] = [
                'name' => $name,
                'roll' => $roll,
                'modifiers' => $modifiers,
                'total' => $roll + $modifiers,
                '_i' => $i,
            ];
        }

        usort($indexed, function (array $a, array $b) {
            if ($a['total'] !== $b['total']) {
                return $a['total'] <=> $b['total'];
            }

            return $a['_i'] <=> $b['_i'];
        });

        $out = [];
        foreach (array_values($indexed) as $order => $row) {
            unset($row['_i']);
            $row['order'] = $order + 1;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Each side rolls d10. Default surprised on 1-3. Segments equal the
     * surprised side's roll when the other side is not surprised. Both or
     * neither surprised: 0 segments.
     *
     * @return array{roll_a: int, roll_b: int, threshold_a: int, threshold_b: int, a_surprised: bool, b_surprised: bool, segments: int, surprised_side: 'a'|'b'|null}
     */
    public static function surprise_segments(
        int $roll_a,
        int $roll_b,
        int $threshold_a = self::SURPRISE_THRESHOLD,
        int $threshold_b = self::SURPRISE_THRESHOLD
    ): array {
        $aSurprised = $roll_a >= 1 && $roll_a <= $threshold_a;
        $bSurprised = $roll_b >= 1 && $roll_b <= $threshold_b;
        $segments = 0;
        $side = null;
        if ($aSurprised && ! $bSurprised) {
            $segments = $roll_a;
            $side = 'a';
        } elseif ($bSurprised && ! $aSurprised) {
            $segments = $roll_b;
            $side = 'b';
        }

        return [
            'roll_a' => $roll_a,
            'roll_b' => $roll_b,
            'threshold_a' => $threshold_a,
            'threshold_b' => $threshold_b,
            'a_surprised' => $aSurprised,
            'b_surprised' => $bSurprised,
            'segments' => $segments,
            'surprised_side' => $side,
        ];
    }

    /**
     * 2d10 vs morale rating. Total (roll + modifiers) higher than morale fails.
     *
     * @return array{morale: int, roll: int, modifiers: int, total: int, passed: bool}
     */
    public static function morale_check(int $morale, int $roll, int $modifiers = 0): array
    {
        $total = $roll + $modifiers;

        return [
            'morale' => $morale,
            'roll' => $roll,
            'modifiers' => $modifiers,
            'total' => $total,
            'passed' => $total <= $morale,
        ];
    }
}
