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
}
