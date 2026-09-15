<?php

namespace App\Support;

/**
 * AD&D 2nd Edition mechanical helpers.
 *
 * Tables encode published game mechanics (class groups, THAC0, saves,
 * ability adjustments, memorization counts). Wording is original — do not
 * paste rulebook prose here.
 */
class Adnd2e
{
    public const EDITION = 'adnd-2e';

    public const RACES = [
        'Human',
        'Dwarf',
        'Elf',
        'Gnome',
        'Half-Elf',
        'Halfling',
        'Half-Orc',
        'Other',
    ];

    public const CLASSES = [
        'Fighter',
        'Paladin',
        'Ranger',
        'Mage',
        'Cleric',
        'Druid',
        'Thief',
        'Bard',
        'Psionicist',
    ];

    /**
     * Compact class labels for list cards, show, and printable sheets.
     * Mage uses mixed-case "Wiz" (user-requested); others are 2–3 letter codes.
     */
    public const CLASS_ABBREVIATIONS = [
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

    /** House dual-class: original class must be this level before a new class may begin. */
    public const HOUSE_DUAL_MIN_ORIGINAL_LEVEL = 6;

    /**
     * This table switches at 6th. Resume is that switch level minus one (5th
     * in the new class). Do not use the original class's later level.
     */
    public const HOUSE_DUAL_RESUME_NEW_LEVEL = 5;

    /** Copies of one known spell that may be marked memorized (2E Vancian). */
    public const MAX_TIMES_MEMORIZED = 12;

    /**
     * Discipline name labels for the typed-power datalist only.
     * Not kits, specialist schools, or subclass suggestions.
     */
    public const PSIONIC_DISCIPLINES = [
        'Clairsentience',
        'Psychokinesis',
        'Psychometabolism',
        'Psychoportation',
        'Telepathy',
        'Metapsionics',
    ];

    public const SPECIALIST_SCHOOLS = [
        'Abjurer',
        'Conjurer',
        'Diviner',
        'Enchanter',
        'Illusionist',
        'Invoker',
        'Necromancer',
        'Transmuter',
    ];

    public const ALIGNMENTS = [
        'Lawful Good',
        'Neutral Good',
        'Chaotic Good',
        'Lawful Neutral',
        'True Neutral',
        'Chaotic Neutral',
        'Lawful Evil',
        'Neutral Evil',
        'Chaotic Evil',
    ];

    public const SAVE_CATEGORIES = [
        'paralyzation' => 'Paralyzation, Poison, or Death Magic',
        'rod' => 'Rod, Staff, or Wand',
        'petrification' => 'Petrification or Polymorph',
        'breath' => 'Breath Weapon',
        'spell' => 'Spell',
    ];

    public const PRIEST_SPHERES = [
        'All',
        'Animal',
        'Astral',
        'Charm',
        'Combat',
        'Creation',
        'Divination',
        'Elemental',
        'Guardian',
        'Healing',
        'Necromantic',
        'Plant',
        'Protection',
        'Summoning',
        'Sun',
        'Weather',
    ];

    public const WEAPON_PROFICIENCY_SUGGESTIONS = [
        'Long sword',
        'Short sword',
        'Bastard sword',
        'Two-handed sword',
        'Battle axe',
        'Hand axe',
        'Dagger',
        'Spear',
        'Halberd',
        'Morning star',
        'Mace',
        'Warhammer',
        'Club',
        'Quarterstaff',
        'Long bow',
        'Short bow',
        'Crossbow, light',
        'Crossbow, heavy',
        'Sling',
        'Dart',
        'Javelin',
        'Lance',
        'Flail',
    ];

    public const NONWEAPON_PROFICIENCY_SUGGESTIONS = [
        'Agriculture',
        'Animal Handling',
        'Animal Lore',
        'Animal Training',
        'Artistic Ability',
        'Astrology',
        'Blacksmithing',
        'Blind-fighting',
        'Brewing',
        'Carpentry',
        'Cooking',
        'Dancing',
        'Direction Sense',
        'Endurance',
        'Engineering',
        'Etiquette',
        'Fire-building',
        'Fishing',
        'Healing',
        'Heraldry',
        'Herbalism',
        'Hunting',
        'Jumping',
        'Languages, Ancient',
        'Languages, Modern',
        'Leatherworking',
        'Local History',
        'Mining',
        'Mountaineering',
        'Musical Instrument',
        'Navigation',
        'Pottery',
        'Reading/Writing',
        'Religion',
        'Riding, Land-based',
        'Rope Use',
        'Running',
        'Seamanship',
        'Set Snares',
        'Singing',
        'Spellcraft',
        'Stonemasonry',
        'Survival',
        'Swimming',
        'Tracking',
        'Weather Sense',
        'Weaving',
    ];

    public const CONDITIONS = [
        'Blinded',
        'Charmed',
        'Confused',
        'Cursed',
        'Diseased',
        'Feebleminded',
        'Held',
        'Invisible',
        'Paralyzed',
        'Petrified',
        'Poisoned',
        'Silenced',
        'Sleeping',
        'Slowed',
        'Hasted',
        'Unconscious',
        'Dying',
        'Dead',
        'Fear',
        'Berserk',
    ];

    public const DEATH_THRESHOLD = -10;

    /**
     * Map a class onto a PHB combat group used by this engine.
     *
     * Psionicist is its own handbook class at the table. This app does not add
     * a fifth THAC0/save table. Well-known 2E pattern: hit die is d6, and
     * THAC0 advances as a rogue. CPHB saving throws are unique; we reuse the
     * rogue save row as the thin PHB-group stand-in. No PSP engine.
     */
    public static function classGroup(string $class): string
    {
        $class = self::normalizeClass($class);

        return match ($class) {
            'Fighter', 'Paladin', 'Ranger' => 'warrior',
            'Cleric', 'Druid' => 'priest',
            'Thief', 'Bard', 'Psionicist' => 'rogue',
            default => 'wizard',
        };
    }

    public static function normalizeClass(string $class): string
    {
        $class = trim($class);

        if (in_array($class, self::SPECIALIST_SCHOOLS, true) || $class === 'Wizard' || $class === 'Illusionist') {
            return 'Mage';
        }

        return match ($class) {
            'Rogue' => 'Thief',
            'Priest' => 'Cleric',
            'Psion', 'Psionic', 'Psionics' => 'Psionicist',
            default => $class,
        };
    }

    /**
     * Map leftover 5e class names to a 2E class, or reject unknown leftovers.
     *
     * @return array{class: string, mapped: bool, rejected: bool, original: string}
     */
    public static function rewriteLegacyClass(string $class): array
    {
        $original = trim($class);
        $mapped = match (mb_strtolower($original)) {
            'warlock', 'sorcerer', 'wizard', 'artificer' => 'Mage',
            'rogue' => 'Thief',
            'barbarian', 'monk', 'blood hunter' => 'Fighter',
            'priest' => 'Cleric',
            'psion', 'psionic', 'psionics' => 'Psionicist',
            default => $original,
        };

        $normalized = self::normalizeClass($mapped);
        $known = in_array($normalized, self::CLASSES, true) || in_array($mapped, self::SPECIALIST_SCHOOLS, true);

        if (! $known) {
            return [
                'class' => 'Fighter',
                'mapped' => false,
                'rejected' => true,
                'original' => $original,
            ];
        }

        return [
            'class' => $normalized,
            'mapped' => $normalized !== $original && $mapped !== $original,
            'rejected' => false,
            'original' => $original,
        ];
    }

    /**
     * @param  array<int, mixed>|null  $classLevels
     * @return array<int, array{class: string, level: int, xp?: int}>
     */
    public static function normalizeClassLevels(?array $classLevels, string $class, int $level, string $path = 'single'): array
    {
        $entries = [];
        if (is_array($classLevels)) {
            foreach ($classLevels as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $name = trim((string) ($entry['class'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $rewritten = self::rewriteLegacyClass($name);
                $normalized = [
                    'class' => $rewritten['class'],
                    'level' => max(1, min(20, (int) ($entry['level'] ?? $level))),
                ];
                if (array_key_exists('xp', $entry) && $entry['xp'] !== null && $entry['xp'] !== '') {
                    $xp = (int) $entry['xp'];
                    if ($xp >= 0) {
                        $normalized['xp'] = $xp;
                    }
                }
                $entries[] = $normalized;
            }
        }

        if ($entries === []) {
            if (str_contains($class, '/')) {
                foreach (preg_split('/\s*\/\s*/', $class) ?: [] as $part) {
                    $rewritten = self::rewriteLegacyClass($part);
                    $entries[] = ['class' => $rewritten['class'], 'level' => max(1, $level)];
                }
                $path = count($entries) > 1 ? 'multi' : $path;
            } else {
                $rewritten = self::rewriteLegacyClass($class);
                $entries[] = ['class' => $rewritten['class'], 'level' => max(1, $level)];
            }
        }

        if ($path === 'single') {
            return array_slice($entries, 0, 1);
        }

        return array_values(array_slice($entries, 0, 3));
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     */
    public static function displayClassName(array $entries, string $path = 'single'): string
    {
        $names = array_map(fn (array $e) => $e['class'], $entries);
        if ($path === 'dual' && count($names) >= 2) {
            return $names[0].' → '.$names[1];
        }
        if (count($names) > 1) {
            return implode('/', $names);
        }

        return $names[0] ?? 'Fighter';
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     */
    public static function displayLevel(array $entries, string $path = 'single'): int
    {
        if ($entries === []) {
            return 1;
        }
        if ($path === 'dual') {
            return (int) ($entries[array_key_last($entries)]['level'] ?? 1);
        }

        return max(array_map(fn (array $e) => (int) $e['level'], $entries));
    }

    public static function classAbbreviation(string $class): string
    {
        $name = trim($class);
        if ($name === '') {
            return '?';
        }
        if (isset(self::CLASS_ABBREVIATIONS[$name])) {
            return self::CLASS_ABBREVIATIONS[$name];
        }

        $rewritten = self::rewriteLegacyClass($name);
        $normalized = (string) ($rewritten['class'] ?? $name);
        if (isset(self::CLASS_ABBREVIATIONS[$normalized])) {
            return self::CLASS_ABBREVIATIONS[$normalized];
        }
        if (in_array($name, self::SPECIALIST_SCHOOLS, true) || in_array($normalized, self::SPECIALIST_SCHOOLS, true)) {
            return 'Wiz';
        }

        $clean = preg_replace('/[^A-Za-z]/', '', $name) ?? '';
        $abbr = strtoupper(substr($clean, 0, 3));

        return $abbr !== '' ? $abbr : '?';
    }

    /**
     * Compact class/level line: "FR 11 / Wiz 12", "PSI 9 → FR 10", "CLR 10".
     *
     * @param  array<int, array{class: string, level: int, xp?: int}>  $entries
     */
    public static function formatClassLevelsLine(array $entries, string $path = 'single'): string
    {
        $parts = [];
        foreach ($entries as $entry) {
            $name = trim((string) ($entry['class'] ?? ''));
            if ($name === '') {
                continue;
            }
            $parts[] = self::classAbbreviation($name).' '.(int) ($entry['level'] ?? 1);
        }
        if ($parts === []) {
            return '';
        }
        if ($path === 'dual' && count($parts) >= 2) {
            return implode(' → ', $parts);
        }

        return implode(' / ', $parts);
    }

    public static function formatXpAmount(int $xp, bool $compact = false): string
    {
        if ($compact && $xp >= 10000 && $xp % 1000 === 0) {
            return ((int) ($xp / 1000)).'k';
        }

        return number_format($xp);
    }

    /**
     * Per-class XP line. Missing xp is "—" unless $omitMissing is true.
     *
     * @param  array<int, array{class: string, level: int, xp?: int|null}>  $entries
     */
    public static function formatClassXpLine(array $entries, string $path = 'single', bool $compact = true, bool $omitMissing = false): string
    {
        $parts = [];
        foreach ($entries as $entry) {
            $name = trim((string) ($entry['class'] ?? ''));
            if ($name === '') {
                continue;
            }
            $abbr = self::classAbbreviation($name);
            if (array_key_exists('xp', $entry) && $entry['xp'] !== null && $entry['xp'] !== '') {
                $parts[] = $abbr.' '.self::formatXpAmount((int) $entry['xp'], $compact);
            } elseif (! $omitMissing) {
                $parts[] = $abbr.' —';
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Sum per-class xp when any entry has it; otherwise the legacy total.
     *
     * @param  array<int, array{class: string, level: int, xp?: int}>  $entries
     */
    public static function derivedExperiencePoints(array $entries, mixed $legacyXp = 0): int
    {
        $sum = 0;
        $any = false;
        foreach ($entries as $entry) {
            if (array_key_exists('xp', $entry) && $entry['xp'] !== null && $entry['xp'] !== '') {
                $sum += max(0, (int) $entry['xp']);
                $any = true;
            }
        }

        return $any ? $sum : max(0, (int) $legacyXp);
    }

    /**
     * Copy a legacy experience_points total into class_levels when per-class
     * xp is missing. Does not invent splits:
     * - single: copy onto the only entry
     * - dual: copy onto the current (last) class
     * - multi: leave per-class xp empty
     *
     * @param  array<int, array{class: string, level: int, xp?: int}>  $entries
     * @return array<int, array{class: string, level: int, xp?: int}>
     */
    public static function backfillClassLevelsXp(array $entries, ?string $path, mixed $legacyXp): array
    {
        $hasXp = false;
        foreach ($entries as $entry) {
            if (array_key_exists('xp', $entry) && $entry['xp'] !== null && $entry['xp'] !== '') {
                $hasXp = true;
                break;
            }
        }
        $legacy = max(0, (int) $legacyXp);
        if ($hasXp || $legacy <= 0 || $entries === []) {
            return $entries;
        }

        $path = in_array($path, ['single', 'multi', 'dual'], true) ? $path : 'single';
        if ($path === 'multi') {
            return $entries;
        }

        $index = $path === 'dual' ? array_key_last($entries) : array_key_first($entries);
        $entries[$index]['xp'] = $legacy;

        return $entries;
    }

    /**
     * House dual-class (not PHB human-only dual-class).
     * A character may begin a new class only after the original is at least 6th.
     */
    public static function canBeginNewClass(int $originalLevel): bool
    {
        return $originalLevel >= self::HOUSE_DUAL_MIN_ORIGINAL_LEVEL;
    }

    /**
     * Resume the original class when the new class is 5th.
     * originalLevelAtSwitch is 6 on this table; resume is 6 − 1 = 5.
     * Do not pass the original class's current (later) level.
     */
    public static function canResumeOriginalClass(int $newLevel): bool
    {
        return $newLevel >= self::HOUSE_DUAL_RESUME_NEW_LEVEL;
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries  original first, new second
     */
    public static function dualResumeAllowed(array $entries): bool
    {
        if (count($entries) < 2) {
            return false;
        }

        return self::canResumeOriginalClass((int) $entries[1]['level']);
    }

    /**
     * True when a stored sheet is already dual with two class entries
     * (grandfather existing rows that violate the 6th-level start gate).
     *
     * @param  array<int, mixed>|null  $classLevels
     */
    public static function hasStoredDualSwitch(?string $path, ?array $classLevels, string $class = '', int $level = 1): bool
    {
        if ($path !== 'dual') {
            return false;
        }

        return count(self::normalizeClassLevels($classLevels, $class, $level, 'dual')) >= 2;
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     */
    public static function combinedThac0(array $entries): int
    {
        $values = array_map(fn (array $e) => self::thac0($e['class'], (int) $e['level']), $entries);

        return $values === [] ? 20 : min($values);
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     * @return array{paralyzation: int, rod: int, petrification: int, breath: int, spell: int}
     */
    public static function combinedSavingThrows(array $entries): array
    {
        $combined = [
            'paralyzation' => 20,
            'rod' => 20,
            'petrification' => 20,
            'breath' => 20,
            'spell' => 20,
        ];
        foreach ($entries as $entry) {
            $row = self::savingThrows($entry['class'], (int) $entry['level']);
            foreach ($combined as $key => $current) {
                $combined[$key] = min($current, $row[$key]);
            }
        }

        return $combined;
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     */
    public static function combinedHitDie(array $entries): string
    {
        $dice = [];
        foreach ($entries as $entry) {
            $die = self::hitDie($entry['class']);
            if (! in_array($die, $dice, true)) {
                $dice[] = $die;
            }
        }

        return $dice === [] ? 'd10' : implode('/', $dice);
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     * @return array<int, int>
     */
    public static function combinedMemorization(array $entries, int $wisdom = 10, ?string $subclass = null): array
    {
        $merged = [];
        foreach ($entries as $entry) {
            $merged = self::unionCapacity(
                $merged,
                self::memorizationCapacity($entry['class'], (int) $entry['level'], $wisdom, $subclass)
            );
        }

        return array_filter($merged, fn (int $n) => $n > 0);
    }

    public static function anyCaster(array $entries): bool
    {
        foreach ($entries as $entry) {
            $class = $entry['class'];
            $level = (int) $entry['level'];
            if (self::isWizard($class) || self::isPriest($class) || $class === 'Bard') {
                return true;
            }
            if ($class === 'Paladin' && $level >= 9) {
                return true;
            }
            if ($class === 'Ranger' && $level >= 8) {
                return true;
            }
        }

        return false;
    }

    /**
     * Published weapon speed factors (initiative modifier). Names only.
     */
    public static function weaponSpeed(?string $weapon): ?int
    {
        if ($weapon === null || trim($weapon) === '') {
            return null;
        }

        $key = mb_strtolower(trim($weapon));
        $key = preg_replace('/^(a|an|the)\s+/', '', $key) ?? $key;

        return match (true) {
            str_contains($key, 'dagger') || str_contains($key, 'dart') => 2,
            str_contains($key, 'short sword') => 3,
            str_contains($key, 'hand axe') || str_contains($key, 'club') || str_contains($key, 'staff') || str_contains($key, 'warhammer') || str_contains($key, 'javelin') => 4,
            str_contains($key, 'long sword') || str_contains($key, 'spear') || str_contains($key, 'mace') || str_contains($key, 'sling') => 5,
            str_contains($key, 'bastard') || str_contains($key, 'flail') || str_contains($key, 'morning') => 6,
            str_contains($key, 'battle axe') || str_contains($key, 'short bow') || str_contains($key, 'light crossbow') => 7,
            str_contains($key, 'long bow') || str_contains($key, 'lance') => 8,
            str_contains($key, 'halberd') => 9,
            str_contains($key, 'two-handed') || str_contains($key, 'two handed') || str_contains($key, 'heavy crossbow') => 10,
            default => null,
        };
    }

    /**
     * Racial-handbook kit names that match this race and class list.
     *
     * @param  array<int, mixed>  $entries
     * @return list<string>
     */
    public static function suggestedRacialKits(string $race, array $entries): array
    {
        return Adnd2eRacialKits::suggestedNames($race, $entries);
    }

    /**
     * Specialist schools (if a mage is present) plus eligible racial kits.
     * Discipline names are not kits.
     *
     * @param  array<int, mixed>  $entries
     * @return list<string>
     */
    public static function suggestedSubclassOptions(string $race, array $entries): array
    {
        return Adnd2eRacialKits::subclassSuggestions($race, $entries);
    }

    /**
     * True when any class entry rewrites to Psionicist (Psion / Psionics / …).
     *
     * @param  array<int, mixed>|null  $classLevels
     */
    public static function hasPsionicist(?array $classLevels, string $class = '', int $level = 1, string $path = 'single'): bool
    {
        return Adnd2eRacialKits::hasPsionicist(
            self::normalizeClassLevels($classLevels, $class, $level, $path)
        );
    }

    public static function isSpecialist(string $class, ?string $subclass = null): bool
    {
        if (in_array($class, self::SPECIALIST_SCHOOLS, true)) {
            return true;
        }

        return $subclass !== null && in_array($subclass, self::SPECIALIST_SCHOOLS, true);
    }

    public static function specialistSchool(string $class, ?string $subclass = null): ?string
    {
        if (in_array($class, self::SPECIALIST_SCHOOLS, true)) {
            return $class;
        }

        if ($subclass !== null && in_array($subclass, self::SPECIALIST_SCHOOLS, true)) {
            return $subclass;
        }

        return null;
    }

    public static function isWizard(string $class): bool
    {
        return self::classGroup($class) === 'wizard';
    }

    public static function isPriest(string $class): bool
    {
        return self::classGroup($class) === 'priest';
    }

    public static function hitDie(string $class): string
    {
        return match (self::classGroup($class)) {
            'warrior' => 'd10',
            'priest' => 'd8',
            'rogue' => 'd6',
            default => 'd4',
        };
    }

    public static function movementRate(string $race): int
    {
        return match ($race) {
            'Dwarf', 'Gnome', 'Halfling' => 6,
            default => 12,
        };
    }

    /**
     * Base THAC0 for class and level (lower is better).
     */
    public static function thac0(string $class, int $level): int
    {
        $level = max(1, min(20, $level));
        $group = self::classGroup($class);

        return match ($group) {
            'warrior' => 21 - $level,
            'priest' => match (true) {
                $level <= 3 => 20,
                $level <= 6 => 18,
                $level <= 9 => 16,
                $level <= 12 => 14,
                $level <= 15 => 12,
                $level <= 18 => 10,
                default => 8,
            },
            'rogue' => match (true) {
                $level <= 4 => 20,
                $level <= 8 => 19,
                $level <= 12 => 16,
                $level <= 16 => 14,
                default => 12,
            },
            default => match (true) {
                $level <= 5 => 20,
                $level <= 10 => 19,
                $level <= 15 => 16,
                default => 14,
            },
        };
    }

    /**
     * Five 2E saving-throw targets (roll this number or higher on d20).
     *
     * @return array{paralyzation: int, rod: int, petrification: int, breath: int, spell: int}
     */
    public static function savingThrows(string $class, int $level): array
    {
        $level = max(1, min(20, $level));
        $group = self::classGroup($class);

        $row = match ($group) {
            'warrior' => match (true) {
                $level <= 2 => [14, 16, 15, 17, 17],
                $level <= 4 => [13, 15, 14, 16, 16],
                $level <= 6 => [11, 13, 12, 13, 14],
                $level <= 8 => [10, 12, 11, 12, 13],
                $level <= 10 => [8, 10, 9, 9, 11],
                $level <= 12 => [7, 9, 8, 8, 10],
                $level <= 14 => [5, 7, 6, 5, 8],
                $level <= 16 => [4, 6, 5, 4, 7],
                default => [3, 5, 4, 4, 6],
            },
            'priest' => match (true) {
                $level <= 3 => [10, 14, 13, 16, 15],
                $level <= 6 => [9, 13, 12, 15, 14],
                $level <= 9 => [7, 11, 10, 13, 12],
                $level <= 12 => [6, 10, 9, 12, 11],
                $level <= 15 => [5, 9, 8, 11, 10],
                $level <= 18 => [4, 8, 7, 10, 9],
                default => [2, 6, 5, 8, 7],
            },
            'rogue' => match (true) {
                $level <= 4 => [13, 14, 12, 16, 15],
                $level <= 8 => [12, 12, 11, 15, 13],
                $level <= 12 => [11, 10, 10, 14, 11],
                $level <= 16 => [10, 8, 9, 13, 9],
                default => [9, 6, 8, 12, 7],
            },
            default => match (true) {
                $level <= 5 => [14, 11, 13, 15, 12],
                $level <= 10 => [13, 9, 11, 13, 10],
                $level <= 15 => [11, 7, 9, 11, 8],
                default => [10, 5, 7, 9, 6],
            },
        };

        return [
            'paralyzation' => $row[0],
            'rod' => $row[1],
            'petrification' => $row[2],
            'breath' => $row[3],
            'spell' => $row[4],
        ];
    }

    /**
     * Strength adjustments, including exceptional strength (18/01–00).
     *
     * Hit and damage match the existing combat columns. Weight allow, max
     * press, open doors, and bend bars/lift gates fill the remaining 2E table.
     *
     * @return array{hit: int, damage: int, weight_allow: int, max_press: int, open_doors: string, bend_bars: int}
     */
    public static function strengthAdjustments(int $score, ?string $exceptional = null): array
    {
        if ($score <= 1) {
            return self::strengthRow(-5, -4, 1, 3, '1', 0);
        }
        if ($score === 2) {
            return self::strengthRow(-3, -2, 1, 5, '1', 0);
        }
        if ($score === 3) {
            return self::strengthRow(-3, -1, 5, 10, '2', 0);
        }
        if ($score <= 5) {
            return self::strengthRow(-2, -1, 10, 25, '3', 0);
        }
        if ($score <= 7) {
            return self::strengthRow(-1, 0, 20, 55, '4', 0);
        }
        if ($score <= 9) {
            return self::strengthRow(0, 0, 35, 90, '5', 1);
        }
        if ($score <= 11) {
            return self::strengthRow(0, 0, 40, 115, '6', 2);
        }
        if ($score <= 13) {
            return self::strengthRow(0, 0, 45, 140, '7', 4);
        }
        if ($score <= 15) {
            return self::strengthRow(0, 0, 55, 170, '8', 7);
        }
        if ($score === 16) {
            return self::strengthRow(0, 1, 70, 195, '9', 10);
        }
        if ($score === 17) {
            return self::strengthRow(1, 1, 85, 220, '10', 13);
        }
        if ($score === 18) {
            $exc = self::normalizeExceptional($exceptional);
            if ($exc === null) {
                return self::strengthRow(1, 2, 110, 255, '11', 16);
            }
            if ($exc <= 50) {
                return self::strengthRow(1, 3, 135, 280, '12', 20);
            }
            if ($exc <= 75) {
                return self::strengthRow(2, 3, 160, 305, '13', 25);
            }
            if ($exc <= 90) {
                return self::strengthRow(2, 4, 185, 330, '14', 30);
            }
            if ($exc <= 99) {
                return self::strengthRow(3, 5, 235, 380, '15 (3)', 35);
            }

            return self::strengthRow(3, 6, 335, 480, '16 (6)', 40);
        }
        if ($score === 19) {
            return self::strengthRow(3, 7, 485, 640, '16 (8)', 50);
        }
        if ($score === 20) {
            return self::strengthRow(3, 8, 535, 700, '17 (10)', 60);
        }

        return self::strengthRow(4, 9, 635, 810, '17 (12)', 70);
    }

    /**
     * @return array{hit: int, damage: int, weight_allow: int, max_press: int, open_doors: string, bend_bars: int}
     */
    private static function strengthRow(int $hit, int $damage, int $weight, int $press, string $open, int $bend): array
    {
        return [
            'hit' => $hit,
            'damage' => $damage,
            'weight_allow' => $weight,
            'max_press' => $press,
            'open_doors' => $open,
            'bend_bars' => $bend,
        ];
    }

    /**
     * Dexterity reaction / missile / defensive (AC) adjustments.
     * Defensive is applied to descending AC (negative is better).
     *
     * @return array{reaction: int, missile: int, defensive: int}
     */
    public static function dexterityAdjustments(int $score): array
    {
        return match (true) {
            $score <= 1 => ['reaction' => -6, 'missile' => -6, 'defensive' => 5],
            $score === 2 => ['reaction' => -4, 'missile' => -4, 'defensive' => 5],
            $score === 3 => ['reaction' => -3, 'missile' => -3, 'defensive' => 4],
            $score <= 5 => ['reaction' => -2, 'missile' => -2, 'defensive' => 3],
            $score === 6 => ['reaction' => -1, 'missile' => -1, 'defensive' => 2],
            $score <= 14 => ['reaction' => 0, 'missile' => 0, 'defensive' => 0],
            $score === 15 => ['reaction' => 0, 'missile' => 0, 'defensive' => -1],
            $score === 16 => ['reaction' => 1, 'missile' => 1, 'defensive' => -2],
            $score === 17 => ['reaction' => 2, 'missile' => 2, 'defensive' => -3],
            $score === 18 => ['reaction' => 2, 'missile' => 2, 'defensive' => -4],
            $score === 19 => ['reaction' => 3, 'missile' => 3, 'defensive' => -4],
            default => ['reaction' => 3, 'missile' => 3, 'defensive' => -5],
        };
    }

    /**
     * Constitution hit-point adjustment. Warriors use the higher column at 17+.
     */
    public static function constitutionHpAdjustment(int $score, string $class = 'Fighter'): int
    {
        $warrior = self::classGroup($class) === 'warrior';

        return match (true) {
            $score <= 1 => -3,
            $score === 2, $score === 3 => -2,
            $score <= 6 => -1,
            $score <= 14 => 0,
            $score === 15 => 1,
            $score === 16 => 2,
            $score === 17 => $warrior ? 3 : 2,
            $score === 18 => $warrior ? 4 : 2,
            $score === 19 => $warrior ? 5 : 2,
            default => $warrior ? 5 : 2,
        };
    }

    /**
     * Constitution table: HP adj plus shock, resurrection, poison, regen.
     *
     * @return array{hp: int, system_shock: int, resurrection: int, poison_save: int, regeneration: string|null}
     */
    public static function constitutionAdjustments(int $score, string $class = 'Fighter'): array
    {
        [$shock, $resurrection, $poison, $regen] = match (true) {
            $score <= 1 => [25, 30, 0, null],
            $score === 2 => [30, 35, 0, null],
            $score === 3 => [35, 40, 0, null],
            $score === 4 => [40, 45, 0, null],
            $score === 5 => [45, 50, 0, null],
            $score === 6 => [50, 55, 0, null],
            $score === 7 => [55, 60, 0, null],
            $score === 8 => [60, 65, 0, null],
            $score === 9 => [65, 70, 0, null],
            $score === 10 => [70, 75, 0, null],
            $score === 11 => [75, 80, 0, null],
            $score === 12 => [80, 85, 0, null],
            $score === 13 => [85, 90, 0, null],
            $score === 14 => [88, 92, 0, null],
            $score === 15 => [90, 94, 0, null],
            $score === 16 => [95, 96, 0, null],
            $score === 17 => [97, 98, 0, null],
            $score === 18 => [99, 100, 0, null],
            $score === 19 => [99, 100, 1, null],
            $score === 20 => [99, 100, 1, '1/6 turns'],
            $score === 21 => [99, 100, 2, '1/5 turns'],
            $score === 22 => [99, 100, 2, '1/4 turns'],
            $score === 23 => [99, 100, 3, '1/3 turns'],
            $score === 24 => [99, 100, 3, '1/2 turns'],
            default => [100, 100, 4, '1/1 turn'],
        };

        return [
            'hp' => self::constitutionHpAdjustment($score, $class),
            'system_shock' => $shock,
            'resurrection' => $resurrection,
            'poison_save' => $poison,
            'regeneration' => $regen,
        ];
    }

    /**
     * Wisdom magical-defense adjustment (applied to mental/spell saves).
     */
    public static function wisdomMagicalDefense(int $score): int
    {
        return match (true) {
            $score <= 1 => -6,
            $score === 2 => -4,
            $score === 3 => -3,
            $score <= 5 => -2,
            $score <= 7 => -1,
            $score <= 14 => 0,
            $score === 15 => 1,
            $score === 16 => 2,
            $score === 17 => 3,
            default => 4,
        };
    }

    /**
     * Bonus priest spells by wisdom, keyed by spell level.
     *
     * @return array<int, int>
     */
    public static function wisdomBonusSpells(int $score): array
    {
        return match (true) {
            $score <= 12 => [],
            $score === 13 => [1 => 1],
            $score === 14 => [1 => 2],
            $score === 15 => [1 => 2, 2 => 1],
            $score === 16 => [1 => 2, 2 => 2],
            $score === 17 => [1 => 2, 2 => 2, 3 => 1],
            default => [1 => 2, 2 => 2, 3 => 1, 4 => 1],
        };
    }

    /**
     * Chance of priest spell failure by wisdom.
     */
    public static function wisdomSpellFailure(int $score): int
    {
        return match (true) {
            $score <= 1 => 80,
            $score === 2 => 60,
            $score === 3 => 50,
            $score === 4 => 45,
            $score === 5 => 40,
            $score === 6 => 35,
            $score === 7 => 30,
            $score === 8 => 25,
            $score === 9 => 20,
            $score === 10 => 15,
            $score === 11 => 10,
            $score === 12 => 5,
            default => 0,
        };
    }

    /**
     * Charisma reaction / henchmen / loyalty adjustments.
     *
     * @return array{max_henchmen: int, loyalty: int, reaction: int}
     */
    public static function charismaAdjustments(int $score): array
    {
        return match (true) {
            $score <= 2 => ['max_henchmen' => 1, 'loyalty' => -8, 'reaction' => -7],
            $score === 3 => ['max_henchmen' => 1, 'loyalty' => -6, 'reaction' => -5],
            $score <= 5 => ['max_henchmen' => 2, 'loyalty' => -4, 'reaction' => -3],
            $score <= 7 => ['max_henchmen' => 3, 'loyalty' => -2, 'reaction' => -1],
            $score <= 11 => ['max_henchmen' => 4, 'loyalty' => 0, 'reaction' => 0],
            $score === 12 => ['max_henchmen' => 5, 'loyalty' => 0, 'reaction' => 0],
            $score === 13 => ['max_henchmen' => 5, 'loyalty' => 0, 'reaction' => 1],
            $score === 14 => ['max_henchmen' => 6, 'loyalty' => 1, 'reaction' => 2],
            $score === 15 => ['max_henchmen' => 7, 'loyalty' => 3, 'reaction' => 3],
            $score === 16 => ['max_henchmen' => 8, 'loyalty' => 4, 'reaction' => 5],
            $score === 17 => ['max_henchmen' => 10, 'loyalty' => 6, 'reaction' => 6],
            default => ['max_henchmen' => 15, 'loyalty' => 8, 'reaction' => 7],
        };
    }

    /**
     * Intelligence: languages, max wizard spell level, chance to learn, max spells/level.
     *
     * @return array{languages: int, max_spell_level: int|null, chance_to_learn: int|null, max_spells_per_level: int|null}
     */
    public static function intelligenceLimits(int $score): array
    {
        return match (true) {
            $score <= 8 => ['languages' => 1, 'max_spell_level' => null, 'chance_to_learn' => null, 'max_spells_per_level' => null],
            $score === 9 => ['languages' => 2, 'max_spell_level' => 4, 'chance_to_learn' => 35, 'max_spells_per_level' => 6],
            $score === 10 => ['languages' => 2, 'max_spell_level' => 5, 'chance_to_learn' => 40, 'max_spells_per_level' => 7],
            $score === 11 => ['languages' => 2, 'max_spell_level' => 5, 'chance_to_learn' => 45, 'max_spells_per_level' => 7],
            $score === 12 => ['languages' => 3, 'max_spell_level' => 6, 'chance_to_learn' => 50, 'max_spells_per_level' => 7],
            $score === 13 => ['languages' => 3, 'max_spell_level' => 6, 'chance_to_learn' => 55, 'max_spells_per_level' => 9],
            $score === 14 => ['languages' => 4, 'max_spell_level' => 7, 'chance_to_learn' => 60, 'max_spells_per_level' => 9],
            $score === 15 => ['languages' => 4, 'max_spell_level' => 7, 'chance_to_learn' => 65, 'max_spells_per_level' => 11],
            $score === 16 => ['languages' => 5, 'max_spell_level' => 8, 'chance_to_learn' => 70, 'max_spells_per_level' => 11],
            $score === 17 => ['languages' => 5, 'max_spell_level' => 8, 'chance_to_learn' => 75, 'max_spells_per_level' => 14],
            default => ['languages' => 7, 'max_spell_level' => 9, 'chance_to_learn' => 85, 'max_spells_per_level' => 18],
        };
    }

    /**
     * Compact signed label for a primary combat-facing adjustment.
     */
    public static function primaryAdjustment(string $ability, int $score, ?string $exceptional = null, string $class = 'Fighter'): int
    {
        return match ($ability) {
            'strength' => self::strengthAdjustments($score, $exceptional)['hit'],
            'dexterity' => self::dexterityAdjustments($score)['missile'],
            'constitution' => self::constitutionHpAdjustment($score, $class),
            'intelligence' => self::intelligenceLimits($score)['languages'] - 2,
            'wisdom' => self::wisdomMagicalDefense($score),
            'charisma' => self::charismaAdjustments($score)['reaction'],
            default => 0,
        };
    }

    /**
     * Short label for the number returned by primaryAdjustment().
     */
    public static function primaryAdjustmentLabel(string $ability): string
    {
        return match ($ability) {
            'strength' => 'hit',
            'dexterity' => 'missile',
            'constitution' => 'HP',
            'intelligence' => 'lang',
            'wisdom' => 'MD',
            'charisma' => 'react',
            default => 'mod',
        };
    }

    /**
     * Display score, including exceptional strength as 18/67.
     */
    public static function formatAbilityScore(string $ability, int $score, ?string $exceptional = null): string
    {
        if ($ability !== 'strength' || $score !== 18) {
            return (string) $score;
        }

        $raw = $exceptional === null ? '' : strtoupper(trim($exceptional));
        if ($raw === '') {
            return '18';
        }
        if ($raw === '00' || $raw === '100') {
            return '18/00';
        }
        if (! preg_match('/^\d{1,3}$/', $raw)) {
            return '18';
        }

        return '18/'.str_pad($raw, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Compact labeled 2E table columns for one ability.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function abilityAdjustmentLines(string $ability, int $score, ?string $exceptional = null, string $class = 'Fighter'): array
    {
        return match ($ability) {
            'strength' => self::strengthAdjustmentLines($score, $exceptional),
            'dexterity' => self::dexterityAdjustmentLines($score),
            'constitution' => self::constitutionAdjustmentLines($score, $class),
            'intelligence' => self::intelligenceAdjustmentLines($score),
            'wisdom' => self::wisdomAdjustmentLines($score),
            'charisma' => self::charismaAdjustmentLines($score),
            default => [],
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function strengthAdjustmentLines(int $score, ?string $exceptional): array
    {
        $row = self::strengthAdjustments($score, $exceptional);

        return [
            ['label' => 'hit', 'value' => self::formatSigned($row['hit'])],
            ['label' => 'dmg', 'value' => self::formatSigned($row['damage'])],
            ['label' => 'wt', 'value' => (string) $row['weight_allow']],
            ['label' => 'press', 'value' => (string) $row['max_press']],
            ['label' => 'open', 'value' => $row['open_doors']],
            ['label' => 'BB', 'value' => $row['bend_bars'].'%'],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function dexterityAdjustmentLines(int $score): array
    {
        $row = self::dexterityAdjustments($score);

        return [
            ['label' => 'react', 'value' => self::formatSigned($row['reaction'])],
            ['label' => 'missile', 'value' => self::formatSigned($row['missile'])],
            ['label' => 'def', 'value' => self::formatSigned($row['defensive'])],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function constitutionAdjustmentLines(int $score, string $class): array
    {
        $row = self::constitutionAdjustments($score, $class);
        $lines = [
            ['label' => 'HP', 'value' => self::formatSigned($row['hp'])],
            ['label' => 'shock', 'value' => $row['system_shock'].'%'],
            ['label' => 'resurrect', 'value' => $row['resurrection'].'%'],
            ['label' => 'poison', 'value' => self::formatSigned($row['poison_save'])],
        ];
        if ($row['regeneration'] !== null) {
            $lines[] = ['label' => 'regen', 'value' => $row['regeneration']];
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function intelligenceAdjustmentLines(int $score): array
    {
        $row = self::intelligenceLimits($score);
        $lines = [
            ['label' => 'langs', 'value' => (string) $row['languages']],
        ];
        if ($row['max_spell_level'] !== null) {
            $lines[] = ['label' => 'spell lvl', 'value' => (string) $row['max_spell_level']];
        }
        if ($row['chance_to_learn'] !== null) {
            $lines[] = ['label' => 'learn', 'value' => $row['chance_to_learn'].'%'];
        }
        if ($row['max_spells_per_level'] !== null) {
            $lines[] = ['label' => 'max/lvl', 'value' => (string) $row['max_spells_per_level']];
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function wisdomAdjustmentLines(int $score): array
    {
        return [
            ['label' => 'MD', 'value' => self::formatSigned(self::wisdomMagicalDefense($score))],
            ['label' => 'bonus', 'value' => self::formatWisdomBonusSpells(self::wisdomBonusSpells($score))],
            ['label' => 'fail', 'value' => self::wisdomSpellFailure($score).'%'],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function charismaAdjustmentLines(int $score): array
    {
        $row = self::charismaAdjustments($score);

        return [
            ['label' => 'hench', 'value' => (string) $row['max_henchmen']],
            ['label' => 'loyalty', 'value' => self::formatSigned($row['loyalty'])],
            ['label' => 'react', 'value' => self::formatSigned($row['reaction'])],
        ];
    }

    /**
     * @param  array<int, int>  $bonus
     */
    public static function formatWisdomBonusSpells(array $bonus): string
    {
        if ($bonus === []) {
            return '—';
        }

        $ordinal = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', 6 => '6th', 7 => '7th'];
        $parts = [];
        foreach ($bonus as $level => $count) {
            $parts[] = ($ordinal[$level] ?? $level.'th').'×'.$count;
        }

        return implode(', ', $parts);
    }

    /**
     * Memorization capacity by class/level. Keys are spell levels 1–9.
     *
     * @return array<int, int>
     */
    public static function memorizationCapacity(string $class, int $level, int $wisdom = 10, ?string $subclass = null): array
    {
        $class = self::normalizeClass($class);
        $level = max(1, min(20, $level));
        $base = [];

        if (self::isWizard($class) || in_array($class, self::SPECIALIST_SCHOOLS, true)) {
            $base = self::wizardCapacity($level);
            if (self::isSpecialist($class, $subclass)) {
                foreach ($base as $spellLevel => $count) {
                    if ($count > 0) {
                        $base[$spellLevel] = $count + 1;
                    }
                }
            }
        } elseif ($class === 'Cleric' || $class === 'Druid') {
            $base = self::priestCapacity($level);
            $base = self::mergeCapacity($base, self::wisdomBonusSpells($wisdom));
        } elseif ($class === 'Paladin' && $level >= 9) {
            $base = self::paladinCapacity($level);
            $base = self::mergeCapacity($base, self::wisdomBonusSpells($wisdom));
        } elseif ($class === 'Ranger' && $level >= 8) {
            $base = self::rangerPriestCapacity($level);
            $base = self::mergeCapacity($base, self::wisdomBonusSpells($wisdom));
        } elseif ($class === 'Bard') {
            $base = self::bardCapacity($level);
        }

        return array_filter($base, fn (int $n) => $n > 0);
    }

    /**
     * Number needed on d20 to hit descending AC with the given THAC0.
     */
    public static function numberNeededToHit(int $thac0, int $armorClass): int
    {
        return $thac0 - $armorClass;
    }

    /**
     * Resolve a d20 attack vs descending AC.
     *
     * Convention used by this app: 1 always misses, 20 always hits.
     *
     * @return array{hit: bool, needed: int, roll: int, automatic: bool}
     */
    public static function resolveAttack(int $thac0, int $armorClass, int $roll): array
    {
        $needed = self::numberNeededToHit($thac0, $armorClass);
        $automatic = $roll === 1 || $roll === 20;
        $hit = $roll === 20 || ($roll !== 1 && $roll >= $needed);

        return [
            'hit' => $hit,
            'needed' => $needed,
            'roll' => $roll,
            'automatic' => $automatic,
        ];
    }

    /**
     * 2E surprise / combat initiative: d10, lower is better.
     * Dexterity reaction adjustment is subtracted (a bonus makes you act sooner).
     *
     * @return array{roll: int, modifier: int, total: int}
     */
    public static function resolveInitiative(int $d10, int $dexterity, int $otherModifiers = 0): array
    {
        $reaction = self::dexterityAdjustments($dexterity)['reaction'];
        $total = $d10 - $reaction + $otherModifiers;

        return [
            'roll' => $d10,
            'modifier' => -$reaction + $otherModifiers,
            'total' => $total,
        ];
    }

    public static function vitalityState(int $currentHp): string
    {
        if ($currentHp <= self::DEATH_THRESHOLD) {
            return 'dead';
        }
        if ($currentHp < 0) {
            return 'dying';
        }
        if ($currentHp === 0) {
            return 'unconscious';
        }

        return 'ok';
    }

    /**
     * Overnight rest: recover 1 hit point (natural healing) and rememorize.
     *
     * @param  array<string, mixed>  $classFeatures
     * @return array{current_hp: int, memorization_used: null, class_features: array<string, mixed>}
     */
    public static function overnightRest(int $currentHp, int $maxHp, string $class, int $level, array $classFeatures = []): array
    {
        $healed = min($maxHp, max(self::DEATH_THRESHOLD, $currentHp) + 1);
        if ($currentHp > 0) {
            $healed = min($maxHp, $currentHp + 1);
        }

        $cf = $classFeatures;
        $normalized = self::normalizeClass($class);

        if ($normalized === 'Paladin') {
            $max = $cf['lay_on_hands_max'] ?? ($level * 2);
            $cf['lay_on_hands_max'] = $max;
            $cf['lay_on_hands_current'] = $max;
            $cf['detect_evil_ready'] = true;
        }

        if ($normalized === 'Cleric' || $normalized === 'Paladin') {
            $cf['turn_undead_ready'] = true;
        }

        if ($normalized === 'Ranger') {
            $cf['tracking_ready'] = true;
        }

        if ($normalized === 'Bard') {
            $cf['influence_ready'] = true;
            $cf['legend_lore_ready'] = true;
        }

        return [
            'current_hp' => $healed,
            'memorization_used' => null,
            'class_features' => $cf,
        ];
    }

    /**
     * @return array{is_cast: bool, times_cast: int}
     */
    public static function rememorizeSpellFields(): array
    {
        return [
            'is_cast' => false,
            'times_cast' => 0,
        ];
    }

    public static function timesMemorizedFromInput(?int $timesMemorized, mixed $isPrepared = false): int
    {
        if ($timesMemorized !== null) {
            return max(0, min(self::MAX_TIMES_MEMORIZED, $timesMemorized));
        }

        return filter_var($isPrepared, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    public static function effectiveTimesMemorized(int $timesMemorized, bool $isPrepared): int
    {
        if ($timesMemorized > 0) {
            return $timesMemorized;
        }

        return $isPrepared ? 1 : 0;
    }

    public static function remainingMemorized(int $timesMemorized, int $timesCast): int
    {
        return max(0, $timesMemorized - $timesCast);
    }

    /**
     * @return array{times_memorized: int, times_cast: int, is_prepared: bool, is_cast: bool}
     */
    public static function spellVancianFlags(int $timesMemorized, int $timesCast): array
    {
        $timesMemorized = max(0, min(self::MAX_TIMES_MEMORIZED, $timesMemorized));
        $timesCast = max(0, min($timesMemorized, $timesCast));

        return [
            'times_memorized' => $timesMemorized,
            'times_cast' => $timesCast,
            'is_prepared' => $timesMemorized > 0,
            'is_cast' => $timesMemorized > 0 && $timesCast >= $timesMemorized,
        ];
    }

    /**
     * @return array{times_memorized: int, times_cast: int, is_prepared: bool, is_cast: bool}
     */
    public static function burnMemorizedInstance(int $timesMemorized, int $timesCast): array
    {
        if ($timesMemorized < 1 || $timesCast >= $timesMemorized) {
            return self::spellVancianFlags($timesMemorized, $timesCast);
        }

        return self::spellVancianFlags($timesMemorized, $timesCast + 1);
    }

    /**
     * @return array{times_memorized: int, times_cast: int, is_prepared: bool, is_cast: bool}
     */
    public static function restoreMemorizedInstance(int $timesMemorized, int $timesCast): array
    {
        return self::spellVancianFlags($timesMemorized, max(0, $timesCast - 1));
    }

    /**
     * Copies fill slots, not distinct spell names.
     *
     * @param  array<int, array<string, mixed>>  $spells
     */
    public static function memorizedCopyTotal(array $spells): int
    {
        $sum = 0;
        foreach ($spells as $spell) {
            $sum += self::effectiveTimesMemorized(
                (int) ($spell['times_memorized'] ?? 0),
                (bool) ($spell['is_prepared'] ?? false),
            );
        }

        return $sum;
    }

    /**
     * @param  array<string|int, mixed>|null  $memorization
     */
    public static function slotCapacityAtLevel(?array $memorization, int $level): int
    {
        if ($memorization === null || $memorization === []) {
            return 0;
        }

        $raw = $memorization[(string) $level] ?? $memorization[$level] ?? 0;

        return (int) $raw;
    }

    /**
     * Default field bag when creating a character.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(
        string $class,
        int $level,
        string $race,
        int $wisdom = 10,
        ?string $subclass = null,
    ): array {
        $entries = self::normalizeClassLevels(null, $class, $level, 'single');

        return self::defaultsForEntries($entries, $race, $wisdom, $subclass, 'single');
    }

    /**
     * @param  array<int, array{class: string, level: int}>  $entries
     * @return array<string, mixed>
     */
    public static function defaultsForEntries(
        array $entries,
        string $race,
        int $wisdom = 10,
        ?string $subclass = null,
        string $path = 'single',
    ): array {
        $capacity = self::combinedMemorization($entries, $wisdom, $subclass);
        $slots = [];
        foreach ($capacity as $spellLevel => $count) {
            $slots[(string) $spellLevel] = $count;
        }

        $primary = $entries[0]['class'] ?? 'Fighter';
        $spheres = null;
        foreach ($entries as $entry) {
            if (self::normalizeClass($entry['class']) === 'Cleric') {
                $spheres = ['major' => ['All', 'Healing'], 'minor' => ['Divination']];
            } elseif (self::normalizeClass($entry['class']) === 'Druid') {
                $spheres = ['major' => ['All', 'Animal', 'Elemental', 'Healing', 'Plant', 'Weather'], 'minor' => []];
                break;
            }
        }

        $casterAbility = null;
        foreach ($entries as $entry) {
            if (self::isWizard($entry['class']) || self::normalizeClass($entry['class']) === 'Bard') {
                $casterAbility = 'intelligence';
                break;
            }
            if (self::isPriest($entry['class']) || in_array(self::normalizeClass($entry['class']), ['Paladin', 'Ranger'], true)) {
                $casterAbility = 'wisdom';
            }
        }

        return [
            'class' => self::displayClassName($entries, $path),
            'level' => self::displayLevel($entries, $path),
            'class_path' => $path,
            'class_levels' => $entries,
            'thac0' => self::combinedThac0($entries),
            'speed' => self::movementRate($race),
            'hit_die' => self::combinedHitDie($entries),
            'armor_class' => 10,
            'saving_throws' => self::combinedSavingThrows($entries),
            'memorization' => $slots === [] ? null : $slots,
            'memorization_used' => null,
            'priest_spheres' => $spheres,
            'spellcasting_ability' => $casterAbility,
        ];
    }

    /**
     * @return array{initial: int, gain_every: int}
     */
    public static function weaponProficiencySlots(string $class): array
    {
        return match (self::classGroup($class)) {
            'warrior' => ['initial' => 4, 'gain_every' => 3],
            'wizard' => ['initial' => 1, 'gain_every' => 6],
            default => ['initial' => 2, 'gain_every' => 4],
        };
    }

    /**
     * @return array{initial: int, gain_every: int}
     */
    public static function nonweaponProficiencySlots(string $class): array
    {
        return match (self::classGroup($class)) {
            'warrior' => ['initial' => 3, 'gain_every' => 3],
            'wizard' => ['initial' => 4, 'gain_every' => 3],
            'priest' => ['initial' => 4, 'gain_every' => 3],
            default => ['initial' => 3, 'gain_every' => 4],
        };
    }

    public static function formatSigned(int $n): string
    {
        return $n >= 0 ? '+'.$n : (string) $n;
    }

    private static function normalizeExceptional(?string $exceptional): ?int
    {
        if ($exceptional === null || $exceptional === '') {
            return null;
        }
        $exceptional = strtoupper(trim($exceptional));
        if ($exceptional === '00' || $exceptional === '100') {
            return 100;
        }
        if (! preg_match('/^\d{1,3}$/', $exceptional)) {
            return null;
        }

        return (int) $exceptional;
    }

    /**
     * @return array<int, int>
     */
    private static function wizardCapacity(int $level): array
    {
        return match ($level) {
            1 => [1 => 1],
            2 => [1 => 2],
            3 => [1 => 2, 2 => 1],
            4 => [1 => 3, 2 => 2],
            5 => [1 => 4, 2 => 2, 3 => 1],
            6 => [1 => 4, 2 => 2, 3 => 2],
            7 => [1 => 4, 3 => 2, 2 => 3, 4 => 1],
            8 => [1 => 4, 2 => 3, 3 => 3, 4 => 2],
            9 => [1 => 4, 2 => 3, 3 => 3, 4 => 2, 5 => 1],
            10 => [1 => 4, 2 => 4, 3 => 3, 4 => 2, 5 => 2],
            11 => [1 => 4, 2 => 4, 3 => 4, 4 => 3, 5 => 3],
            12 => [1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 1],
            13 => [1 => 5, 2 => 5, 3 => 5, 4 => 4, 5 => 4, 6 => 2],
            14 => [1 => 5, 2 => 5, 3 => 5, 4 => 4, 5 => 4, 6 => 2, 7 => 1],
            15 => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 2, 7 => 1],
            16 => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 3, 7 => 2, 8 => 1],
            17 => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 3, 7 => 3, 8 => 2],
            18 => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 3, 7 => 3, 8 => 2, 9 => 1],
            19 => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 3, 7 => 3, 8 => 3, 9 => 1],
            default => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 4, 7 => 3, 8 => 3, 9 => 2],
        };
    }

    /**
     * @return array<int, int>
     */
    private static function priestCapacity(int $level): array
    {
        return match ($level) {
            1 => [1 => 1],
            2 => [1 => 2],
            3 => [1 => 2, 2 => 1],
            4 => [1 => 3, 2 => 2],
            5 => [1 => 3, 2 => 3, 3 => 1],
            6 => [1 => 3, 2 => 3, 3 => 2],
            7 => [1 => 3, 2 => 3, 3 => 2, 4 => 1],
            8 => [1 => 3, 2 => 3, 3 => 3, 4 => 2],
            9 => [1 => 4, 2 => 4, 3 => 3, 4 => 2, 5 => 1],
            10 => [1 => 4, 2 => 4, 3 => 3, 4 => 3, 5 => 2],
            11 => [1 => 5, 2 => 4, 3 => 4, 4 => 3, 5 => 2, 6 => 1],
            12 => [1 => 6, 2 => 5, 3 => 5, 4 => 3, 5 => 2, 6 => 2],
            13 => [1 => 6, 2 => 6, 3 => 6, 4 => 4, 5 => 2, 6 => 2],
            14 => [1 => 6, 2 => 6, 3 => 6, 4 => 5, 5 => 3, 6 => 2, 7 => 1],
            15 => [1 => 6, 2 => 6, 3 => 6, 4 => 6, 5 => 4, 6 => 2, 7 => 1],
            16 => [1 => 7, 2 => 7, 3 => 7, 4 => 6, 5 => 4, 6 => 3, 7 => 1],
            17 => [1 => 7, 2 => 7, 3 => 7, 4 => 7, 5 => 5, 6 => 3, 7 => 2],
            18 => [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 6, 6 => 4, 7 => 2],
            19 => [1 => 9, 2 => 9, 3 => 8, 4 => 8, 5 => 6, 6 => 4, 7 => 2],
            default => [1 => 9, 2 => 9, 3 => 9, 4 => 8, 5 => 7, 6 => 5, 7 => 2],
        };
    }

    /**
     * Paladin priest spells begin at 9th level.
     *
     * @return array<int, int>
     */
    private static function paladinCapacity(int $level): array
    {
        $caster = max(1, $level - 8);

        return match (true) {
            $caster === 1 => [1 => 1],
            $caster === 2 => [1 => 2],
            $caster === 3 => [1 => 2, 2 => 1],
            $caster === 4 => [1 => 2, 2 => 2],
            $caster === 5 => [1 => 2, 2 => 2, 3 => 1],
            $caster === 6 => [1 => 3, 2 => 2, 3 => 1],
            $caster === 7 => [1 => 3, 2 => 2, 3 => 1, 4 => 1],
            $caster === 8 => [1 => 3, 2 => 3, 3 => 2, 4 => 1],
            $caster === 9 => [1 => 3, 2 => 3, 3 => 3, 4 => 2],
            $caster === 10 => [1 => 3, 2 => 3, 3 => 3, 4 => 2],
            default => [1 => 3, 2 => 3, 3 => 3, 4 => 3],
        };
    }

    /**
     * Ranger priest spells begin at 8th level.
     *
     * @return array<int, int>
     */
    private static function rangerPriestCapacity(int $level): array
    {
        $caster = max(1, $level - 7);

        return match (true) {
            $caster === 1 => [1 => 1],
            $caster === 2 => [1 => 2],
            $caster === 3 => [1 => 2, 2 => 1],
            $caster === 4 => [1 => 2, 2 => 2],
            $caster === 5 => [1 => 2, 2 => 2, 3 => 1],
            default => [1 => 3, 2 => 3, 3 => 1],
        };
    }

    /**
     * @return array<int, int>
     */
    private static function bardCapacity(int $level): array
    {
        return match ($level) {
            1 => [],
            2 => [1 => 1],
            3 => [1 => 2],
            4 => [1 => 2, 2 => 1],
            5 => [1 => 3, 2 => 1],
            6 => [1 => 3, 2 => 2],
            7 => [1 => 3, 2 => 2, 3 => 1],
            8 => [1 => 3, 2 => 3, 3 => 1],
            9 => [1 => 3, 2 => 3, 3 => 2],
            10 => [1 => 3, 2 => 3, 3 => 2, 4 => 1],
            11 => [1 => 3, 2 => 3, 3 => 3, 4 => 1],
            12 => [1 => 3, 2 => 3, 3 => 3, 4 => 2],
            13 => [1 => 3, 2 => 3, 3 => 3, 4 => 2, 5 => 1],
            14 => [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 1],
            15 => [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 2],
            16 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1],
            default => [1 => 4, 2 => 4, 3 => 3, 4 => 3, 5 => 3, 6 => 2],
        };
    }

    /**
     * @param  array<int, int>  $base
     * @param  array<int, int>  $bonus
     * @return array<int, int>
     */
    private static function mergeCapacity(array $base, array $bonus): array
    {
        foreach ($bonus as $level => $count) {
            if (! isset($base[$level]) || $base[$level] === 0) {
                continue;
            }
            $base[$level] += $count;
        }

        return $base;
    }

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     * @return array<int, int>
     */
    private static function unionCapacity(array $a, array $b): array
    {
        foreach ($b as $level => $count) {
            $a[$level] = ($a[$level] ?? 0) + $count;
        }

        return $a;
    }
}
