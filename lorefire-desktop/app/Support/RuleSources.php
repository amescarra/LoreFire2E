<?php

namespace App\Support;

use App\Models\RuleSource;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Bibliographic 2E source catalog. Titles, years, and TSR numbers only.
 * No official prose. Enable flags decide which packs Oracle LOOKUP names first.
 */
class RuleSources
{
    /** Live core enabled=true: PHB_1989 TSR 2101, DMG_1989 TSR 2100, kit PHBR1-5,7,11-15, DMGR7, race PHBR6,8-10, MC1, MC2, MC3_FR TSR 2104, MC11_FR TSR 2125. */
    public const ERA_CORE_1989 = 'core_1989';

    public const ERA_KIT = 'kit';

    public const ERA_RACE = 'race';

    public const ERA_MONSTROUS = 'monstrous';

    public const ERA_EXTRA_2E = 'extra_2e';

    public const ERA_LATER_2E = 'later_2e';

    /**
     * Live core, extra packs, and later 2E bibliographic rows.
     *
     * @return list<array{
     *     code: string,
     *     title: string,
     *     year: int,
     *     tsr_number: string,
     *     era: string,
     *     enabled: bool,
     *     citation_only: bool,
     *     notes: string
     * }>
     */
    public static function catalog(): array
    {
        return array_merge(
            self::liveCore(),
            self::extraPacks(),
            self::laterOff()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function liveCore(): array
    {
        $core = [
            ['PHB_1989', "Player's Handbook", 1989, '2101', self::ERA_CORE_1989, '1989 core. LOOKUP names this before 1995 revised.'],
            ['DMG_1989', "Dungeon Master's Guide", 1989, '2100', self::ERA_CORE_1989, '1989 core. LOOKUP names this before 1995 revised.'],
        ];

        $kits = [
            ['PHBR1', "The Complete Fighter's Handbook", 1989, '2110', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR2', "The Complete Thief's Handbook", 1989, '2111', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR3', "The Complete Priest's Handbook", 1990, '2113', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR4', "The Complete Wizard's Handbook", 1990, '2115', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR5', 'The Complete Psionics Handbook', 1991, '2117', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR7', "The Complete Bard's Handbook", 1992, '2127', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR11', "The Complete Ranger's Handbook", 1993, '2136', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR12', "The Complete Paladin's Handbook", 1994, '2147', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR13', "The Complete Druid's Handbook", 1994, '2150', self::ERA_KIT, 'Kit book. Live core.'],
            ['PHBR14', "The Complete Barbarian's Handbook", 1995, '2148', self::ERA_KIT, 'Kit book. Live core. Not the 1995 revised PHB.'],
            ['PHBR15', "The Complete Ninja's Handbook", 1995, '2155', self::ERA_KIT, 'Kit book. Live core. Not the 1995 revised PHB.'],
            ['DMGR7', 'The Complete Book of Necromancers', 1995, '2151', self::ERA_KIT, 'Kit book (DMGR7). Live core.'],
        ];

        $races = [
            ['PHBR6', 'The Complete Book of Dwarves', 1991, '2124', self::ERA_RACE, 'Race book. Live core.'],
            ['PHBR8', 'The Complete Book of Elves', 1992, '2131', self::ERA_RACE, 'Race book. Live core.'],
            ['PHBR9', 'The Complete Book of Gnomes and Halflings', 1993, '2134', self::ERA_RACE, 'Race book. Live core.'],
            ['PHBR10', 'The Complete Book of Humanoids', 1993, '2135', self::ERA_RACE, 'Race book. Live core.'],
        ];

        $mc = [
            ['MC1', 'Monstrous Compendium Volume One', 1989, '2102', self::ERA_MONSTROUS, 'Live core MC.'],
            ['MC2', 'Monstrous Compendium Volume Two', 1989, '2103', self::ERA_MONSTROUS, 'Live core MC.'],
            ['MC3_FR', 'Monstrous Compendium Forgotten Realms Appendix', 1989, '2104', self::ERA_MONSTROUS, 'Live core Forgotten Realms MC (TSR 2104).'],
            ['MC11_FR', 'Monstrous Compendium Forgotten Realms Appendix II', 1991, '2125', self::ERA_MONSTROUS, 'Live core Forgotten Realms MC II (TSR 2125).'],
        ];

        return array_map(fn (array $row) => self::row($row[0], $row[1], $row[2], $row[3], $row[4], true, false, $row[5]), array_merge($core, $kits, $races, $mc));
    }

    /**
     * Extra 2E packs. Seeded enabled=false. Toggle in Settings to name them in LOOKUP.
     *
     * @return list<array<string, mixed>>
     */
    public static function extraPacks(): array
    {
        $rows = [
            ['TOM', 'Tome of Magic', 1991, '2121', 'Extra 2E pack. Off until enabled.'],
            ['LNL_2E', 'Legends & Lore', 1990, '2108', '2E Legends & Lore reprint. Not a first-edition gods book.'],
            ['MMYTH', 'Monster Mythology', 1992, '2128', 'Extra 2E pack. Off until enabled.'],
            ['AEG', 'Arms and Equipment Guide', 1991, '2123', 'Extra 2E pack. Off until enabled.'],
            ['DMGR1', 'Campaign Sourcebook and Catacomb Guide', 1990, '2112', 'Extra 2E pack. Off until enabled.'],
            ['DMGR2', 'The Castle Guide', 1990, '2114', 'Extra 2E pack. Off until enabled.'],
            ['MC4', 'Monstrous Compendium Dragonlance Appendix', 1990, '2105', 'Later MC (not Forgotten Realms). Off.'],
            ['MC5', 'Monstrous Compendium Greyhawk Adventures Appendix', 1990, '2107', 'Later MC (not Forgotten Realms). Off.'],
            ['MC6', 'Monstrous Compendium Kara-Tur Appendix', 1990, '2116', 'Later MC (not Forgotten Realms). Off.'],
            ['MC7', 'Monstrous Compendium Spelljammer Appendix', 1990, '2109', 'Later MC (not Forgotten Realms). Off.'],
            ['MC8', 'Monstrous Compendium Outer Planes Appendix', 1991, '2118', 'Later MC (not Forgotten Realms). Off.'],
            ['MC9', 'Monstrous Compendium Spelljammer Appendix II', 1991, '2119', 'Later MC (not Forgotten Realms). Off.'],
            ['MC10', 'Monstrous Compendium Ravenloft Appendix', 1991, '2122', 'Later MC (not Forgotten Realms). Off.'],
            ['MC12', 'Monstrous Compendium Dark Sun Appendix', 1992, '2405', 'Later MC (not Forgotten Realms). Off.'],
            ['MC13', 'Monstrous Compendium Al-Qadim Appendix', 1992, '2129', 'Later MC (not Forgotten Realms). Off.'],
            ['MC14', 'Monstrous Compendium Fiend Folio Appendix', 1992, '2132', 'Later MC (not Forgotten Realms). Off.'],
            ['MC15', 'Monstrous Compendium Ravenloft Appendix II', 1993, '2139', 'Later MC (not Forgotten Realms). Off.'],
            ['MM_1993', 'Monstrous Manual', 1993, '2140', '1993 Monstrous Manual. Off until enabled.'],
        ];

        return array_map(
            fn (array $row) => self::row($row[0], $row[1], $row[2], $row[3], self::ERA_EXTRA_2E, false, false, $row[4]),
            $rows
        );
    }

    /**
     * Later 2E / Player's Option / 1995 revised. Seeded off. Not used for pagination.
     *
     * @return list<array<string, mixed>>
     */
    public static function laterOff(): array
    {
        $rows = [
            ['PHB_1995', "Player's Handbook (revised)", 1995, '2159', '1995 revised PHB. Off. Do not use this pagination.'],
            ['DMG_1995', "Dungeon Master's Guide (revised)", 1995, '2160', '1995 revised DMG. Off. Do not use this pagination.'],
            ['PO_CT', "Player's Option: Combat & Tactics", 1995, '2149', "Player's Option. Off."],
            ['PO_SP', "Player's Option: Skills & Powers", 1995, '2154', "Player's Option. Off."],
            ['PO_SM', "Player's Option: Spells & Magic", 1996, '2163', "Player's Option. Off."],
        ];

        return array_map(
            fn (array $row) => self::row($row[0], $row[1], $row[2], $row[3], self::ERA_LATER_2E, false, true, $row[4]),
            $rows
        );
    }

    /**
     * @return array{
     *     code: string,
     *     title: string,
     *     year: int,
     *     tsr_number: string,
     *     era: string,
     *     enabled: bool,
     *     citation_only: bool,
     *     notes: string
     * }
     */
    private static function row(
        string $code,
        string $title,
        int $year,
        string $tsrNumber,
        string $era,
        bool $enabled,
        bool $citationOnly,
        string $notes
    ): array {
        return [
            'code' => $code,
            'title' => $title,
            'year' => $year,
            'tsr_number' => $tsrNumber,
            'era' => $era,
            'enabled' => $enabled,
            'citation_only' => $citationOnly,
            'notes' => $notes,
        ];
    }

    /**
     * Catalog with enabled flags overlaid from the database when present.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $catalog = self::catalog();
        $db = self::databaseByCode();
        if ($db === []) {
            return $catalog;
        }

        foreach ($catalog as $i => $row) {
            $stored = $db[$row['code']] ?? null;
            if (! is_array($stored)) {
                continue;
            }
            if (array_key_exists('enabled', $stored)) {
                $catalog[$i]['enabled'] = (bool) $stored['enabled'];
            }
            if (array_key_exists('citation_only', $stored)) {
                $catalog[$i]['citation_only'] = (bool) $stored['citation_only'];
            }
        }

        return $catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(self::rows(), fn (array $row) => (bool) $row['enabled']));
    }

    /**
     * Live core codes (1989 PHB/DMG, kit, race, FR MC). Seeded enabled.
     *
     * @return list<string>
     */
    public static function liveCoreCodes(): array
    {
        return array_map(fn (array $row) => $row['code'], self::liveCore());
    }

    public static function find(string $code): ?array
    {
        foreach (self::rows() as $row) {
            if (strcasecmp((string) $row['code'], $code) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * LOOKUP preference: enabled 1989 core / kit / race / FR MC first.
     * Never 1E. Never 1995 revised pagination.
     *
     * @return list<array<string, mixed>>
     */
    public static function lookupPreferred(): array
    {
        $preferredEras = [self::ERA_CORE_1989, self::ERA_KIT, self::ERA_RACE, self::ERA_MONSTROUS];
        $rows = [];
        foreach (self::enabled() as $row) {
            if ((string) $row['era'] === self::ERA_LATER_2E) {
                continue;
            }
            if (! in_array((string) $row['era'], $preferredEras, true) && ! (bool) $row['enabled']) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, function (array $a, array $b) use ($preferredEras) {
            $rank = fn (array $row) => array_search((string) $row['era'], $preferredEras, true);
            $ra = $rank($a);
            $rb = $rank($b);
            $ra = $ra === false ? 99 : $ra;
            $rb = $rb === false ? 99 : $rb;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return ((int) $a['year']) <=> ((int) $b['year']);
        });

        return $rows;
    }

    /**
     * Match a question to bibliographic rows. Pages stay unknown unless a citation exists.
     *
     * @return list<array<string, mixed>>
     */
    public static function matchQuestion(string $question): array
    {
        $q = mb_strtolower($question);
        $hits = [];
        foreach (self::rows() as $row) {
            if (self::questionMentionsRow($q, $row)) {
                $hits[] = $row;
            }
        }

        if ($hits === []) {
            if (preg_match('/\bphb\b|player.?s handbook/i', $question)) {
                $phb = self::find('PHB_1989');
                if ($phb !== null) {
                    $hits[] = $phb;
                }
            }
            if (preg_match('/\bdmg\b|dungeon master.?s guide/i', $question)) {
                $dmg = self::find('DMG_1989');
                if ($dmg !== null) {
                    $hits[] = $dmg;
                }
            }
        }

        usort($hits, function (array $a, array $b) {
            $score = function (array $row): int {
                if (! (bool) $row['enabled']) {
                    return 50;
                }
                if ((string) $row['era'] === self::ERA_LATER_2E) {
                    return 80;
                }
                if ((string) $row['code'] === 'PHB_1995' || (string) $row['code'] === 'DMG_1995') {
                    return 90;
                }

                return match ((string) $row['era']) {
                    self::ERA_CORE_1989 => 0,
                    self::ERA_KIT => 1,
                    self::ERA_RACE => 2,
                    self::ERA_MONSTROUS => 3,
                    self::ERA_EXTRA_2E => 10,
                    default => 40,
                };
            };

            return $score($a) <=> $score($b);
        });

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function questionMentionsRow(string $q, array $row): bool
    {
        $code = mb_strtolower((string) $row['code']);
        $title = mb_strtolower((string) $row['title']);
        $tsr = mb_strtolower((string) $row['tsr_number']);

        if ($code !== '' && (str_contains($q, mb_strtolower(str_replace('_', ' ', (string) $row['code']))) || preg_match('/\b'.preg_quote($code, '/').'\b/i', $q))) {
            if ($code === 'phb_1989' && (str_contains($q, '1995') && ! str_contains($q, '1989'))) {
                return false;
            }

            return true;
        }

        if ($tsr !== '' && str_contains($q, 'tsr '.$tsr)) {
            return true;
        }

        $needles = match ((string) $row['code']) {
            'PHB_1989' => ['1989 phb', 'phb 1989', 'player\'s handbook 1989', 'players handbook 1989', '1989 player'],
            'DMG_1989' => ['1989 dmg', 'dmg 1989', 'dungeon master\'s guide 1989', '1989 dungeon'],
            'PHB_1995' => ['1995 phb', 'phb 1995', 'revised phb', '1995 player'],
            'DMG_1995' => ['1995 dmg', 'dmg 1995', 'revised dmg'],
            'TOM' => ['tome of magic'],
            'LNL_2E' => ['legends & lore', 'legends and lore'],
            'MMYTH' => ['monster mythology'],
            'AEG' => ['arms and equipment', 'arms & equipment'],
            'DMGR1' => ['dmgr1', 'catacomb guide', 'campaign sourcebook'],
            'DMGR2' => ['dmgr2', 'castle guide'],
            'DMGR7' => ['dmgr7', 'necromancer'],
            'MM_1993' => ['monstrous manual'],
            'MC1' => ['mc1', 'monstrous compendium volume one', 'monstrous compendium 1'],
            'MC2' => ['mc2', 'monstrous compendium volume two'],
            'MC3_FR' => ['mc3', 'forgotten realms appendix', 'tsr 2104'],
            'MC11_FR' => ['mc11', 'forgotten realms appendix ii', 'tsr 2125', 'fr ii'],
            'PO_CT' => ['combat & tactics', 'combat and tactics', 'player\'s option'],
            'PO_SP' => ['skills & powers', 'skills and powers'],
            'PO_SM' => ['spells & magic', 'spells and magic'],
            default => [],
        };

        foreach ($needles as $needle) {
            if (str_contains($q, $needle)) {
                return true;
            }
        }

        if (preg_match('/phbr\s*(\d{1,2})/i', $q, $m)) {
            $want = 'PHBR'.((int) $m[1]);
            if (strcasecmp((string) $row['code'], $want) === 0) {
                return true;
            }
        }

        if (preg_match('/\bmc\s*(\d{1,2})\b/i', $q, $m)) {
            $n = (int) $m[1];
            $codeUpper = strtoupper((string) $row['code']);
            if ($codeUpper === 'MC'.$n || $codeUpper === 'MC'.$n.'_FR') {
                return true;
            }
        }

        $shortTitle = preg_replace('/^the /', '', $title) ?? $title;
        if (strlen($shortTitle) >= 12 && str_contains($q, $shortTitle)) {
            return true;
        }

        return false;
    }

    public static function seed(): void
    {
        if (! class_exists(RuleSource::class)) {
            return;
        }

        foreach (self::catalog() as $row) {
            RuleSource::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'title' => $row['title'],
                    'year' => $row['year'],
                    'tsr_number' => $row['tsr_number'],
                    'era' => $row['era'],
                    'enabled' => $row['enabled'],
                    'citation_only' => $row['citation_only'],
                    'notes' => $row['notes'],
                ]
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function databaseByCode(): array
    {
        if (! class_exists(RuleSource::class) || ! class_exists(Schema::class)) {
            return [];
        }

        try {
            if (! Schema::hasTable('rule_sources')) {
                return [];
            }

            $out = [];
            foreach (RuleSource::query()->get() as $model) {
                $out[(string) $model->code] = $model->toArray();
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
