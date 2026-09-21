<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Table-only rulings. Not 1989 PHB core.
 *
 * Dual-class house switch mechanics live in Adnd2e::canBeginNewClass /
 * canResumeOriginalClass. This store names that switch as table law so
 * Oracle and comments do not treat it as PHB procedure.
 */
class TableLaw
{
    public const SETTING_KEY = 'table_law';

    public const DEATH_MODE = 'phb_zero';

    public const ORACLE_LABEL = 'TABLE LAW';

    /**
     * TABLE LAW: begin a new class only after the original is this level.
     * Not 1989 PHB core. Existing sheets keep this gate.
     */
    public const DUAL_CLASS_HOUSE_SWITCH_MIN_ORIGINAL_LEVEL = 6;

    /**
     * TABLE LAW: resume the original class when the new class is this level.
     * Resume is the switch level minus one (6 − 1 = 5). Not PHB “must exceed original.”
     */
    public const DUAL_CLASS_HOUSE_SWITCH_RESUME_NEW_LEVEL = 5;

    /**
     * @return array{
     *     death_mode: string,
     *     dual_class_house_switch: array{
     *         enabled: bool,
     *         human_only: bool,
     *         apply_phb_xp_penalties: bool,
     *         min_original_level: int,
     *         resume_new_level: int
     *     }
     * }
     */
    public static function defaults(): array
    {
        return [
            'death_mode' => self::DEATH_MODE,
            'dual_class_house_switch' => [
                'enabled' => true,
                'human_only' => false,
                'apply_phb_xp_penalties' => false,
                'min_original_level' => self::DUAL_CLASS_HOUSE_SWITCH_MIN_ORIGINAL_LEVEL,
                'resume_new_level' => self::DUAL_CLASS_HOUSE_SWITCH_RESUME_NEW_LEVEL,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $defaults = self::defaults();
        if (! class_exists(AppSetting::class)) {
            return $defaults;
        }

        try {
            $stored = AppSetting::get(self::SETTING_KEY, []);
        } catch (\Throwable) {
            return $defaults;
        }

        return array_replace_recursive($defaults, is_array($stored) ? $stored : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function dualClassHouseSwitch(): array
    {
        $row = self::current()['dual_class_house_switch'] ?? [];

        return is_array($row) ? $row : self::defaults()['dual_class_house_switch'];
    }

    public static function deathMode(): string
    {
        $mode = self::current()['death_mode'] ?? self::DEATH_MODE;

        return is_string($mode) && $mode !== '' ? $mode : self::DEATH_MODE;
    }

    public static function persistDefaults(): void
    {
        AppSetting::set(self::SETTING_KEY, self::defaults(), 'json');
    }
}
