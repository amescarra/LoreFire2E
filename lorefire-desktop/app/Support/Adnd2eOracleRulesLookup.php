<?php

namespace App\Support;

use App\Models\Character;
use App\Models\CharacterSpell;
use Illuminate\Container\Container;
use Throwable;

/**
 * Classify Oracle questions and run App\Support\Adnd2e (plus sheet inspect)
 * so core rule/math answers are engine-backed rather than invented PHB text.
 */
class Adnd2eOracleRulesLookup
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{intent: 'rules'|'narrative', resolved: bool, facts: list<string>, markdown: string}
     */
    public static function lookup(string $question, array $context = []): array
    {
        $question = trim($question);
        if ($question === '' || ! self::looksLikeRulesQuery($question)) {
            return [
                'intent' => 'narrative',
                'resolved' => false,
                'facts' => [],
                'markdown' => '',
            ];
        }

        $facts = [];
        self::collectAbilityFacts($question, $context, $facts);
        self::collectThac0Facts($question, $context, $facts);
        self::collectToHitFacts($question, $facts);
        self::collectArmorFacts($question, $facts);
        self::collectWeaponFacts($question, $facts);
        self::collectEquipmentFacts($question, $facts);
        self::collectSpellCatalogFacts($question, $facts);
        self::collectCreatureFacts($question, $facts);
        self::collectNpcFacts($question, $context, $facts);
        self::collectSaveFacts($question, $context, $facts);
        self::collectMemorizationFacts($question, $context, $facts);
        self::collectSphereFacts($question, $context, $facts);
        self::collectEncumbranceFacts($question, $context, $facts);
        self::collectDualClassFacts($question, $facts);
        self::collectCombatMiscFacts($question, $facts);
        self::collectMaterialFacts($question, $context, $facts);
        self::collectCharacterSheetFacts($question, $context, $facts);

        $facts = array_values(array_unique($facts));
        $resolved = $facts !== [];
        $wantsOfficialText = self::asksForOfficialBookText($question);

        if ($wantsOfficialText && ! $resolved) {
            $resolved = false;
        }

        return [
            'intent' => 'rules',
            'resolved' => $resolved,
            'facts' => $facts,
            'markdown' => self::formatMarkdown($resolved, $facts, $wantsOfficialText),
        ];
    }

    public static function markdown(string $question, array $context = []): string
    {
        return self::lookup($question, $context)['markdown'];
    }

    /**
     * Player-facing structured answer when the engine can resolve a THAC0
     * (or other THAC0-table) question without calling an LLM.
     *
     * @param  array<string, mixed>  $context
     */
    public static function playerReply(string $question, array $context = []): ?string
    {
        $lookup = self::lookup($question, $context);
        if (! self::isDirectTableAnswer($question, $lookup)) {
            return null;
        }

        $lines = ['## Engine'];
        $lines[] = '';
        foreach ($lookup['facts'] as $fact) {
            $lines[] = '- '.$fact;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{intent?: string, resolved?: bool, facts?: list<string>, markdown?: string}  $lookup
     */
    public static function isDirectTableAnswer(string $question, array $lookup): bool
    {
        if (($lookup['intent'] ?? '') !== 'rules') {
            return false;
        }
        if (! ($lookup['resolved'] ?? false)) {
            return false;
        }
        if (($lookup['facts'] ?? []) === []) {
            return false;
        }

        return self::mentions($question, 'thac0|thaco');
    }

    public static function looksLikeRulesQuery(string $question): bool
    {
        $q = mb_strtolower($question);

        if (self::looksLikeNarrativeOnly($q)) {
            return false;
        }

        if (self::looksLikeCatalogRulesQuery($question)) {
            return true;
        }

        return (bool) preg_match(
            '/thac0|thaco|\barmor class\b|\bac\b|saving throw|saves?\s+vs|save against|vancian|memoriz|spell capacity|spell slots?|memorization slots?|material component|components? for|open doors?|bend bars?|lift gates?|dual-?class|begin a new class|resume (the )?original|missile|reaction adj|defensive adj|ability (score|table|adj)|exceptional strength|\b18\s*\/\s*(00|\d{1,2})\b|hit dice|hit die|initiative|weapon speed|weapon damage|damage dice|speed factor|needed to hit|number needed|to-hit|\bthaco\b|non-?weapon proficiency|weapon proficiency|movement rate|move rate|encumbrance|weight allow|max press|carried|vitality|death threshold|overnight rest|system shock|resurrection|chance to learn|bonus spells|spell failure|henchmen|loyalty|priest spheres?|major sphere|minor sphere|specialist|saving-throw|descending|unarmored|leather|studded|chain mail|plate mail|full plate|field plate|scale mail|ring mail|padded|brigandine|splint|banded|\bstr(?:ength)?\b|\bdex(?:terity)?\b|\bcon(?:stitution)?\b|\bint(?:elligence)?\b|\bwis(?:dom)?\b|\bcha(?:risma)?\b|spell text|phb|dungeon master.?s guide|\bdmg\b|complete (wizard|priest|fighter|thief|psionic)|\bspells\b|casting time|spell level|what level is|\+\s*[1-5]\b|artifact|stat stub/i',
            $question
        );
    }

    private static function looksLikeNarrativeOnly(string $q): bool
    {
        $narrative = (bool) preg_match(
            '/\b(summarize|recap|backstory|session notes?|who (is|was|did)|what happened|origin story|campaign notes|personality|npc)\b/',
            $q
        );
        if (! $narrative) {
            return false;
        }

        return ! (bool) preg_match(
            '/thac0|thaco|\bac\b|saving throw|memoriz|dual-?class|missile|open doors?|bend bars?|\b18\s*\/|ability|dexterity|strength|vancian|material component|hit dice|\bhd\b|\bdmg\b|stat.block/i',
            $q
        );
    }

    private static function looksLikeCatalogRulesQuery(string $question): bool
    {
        if (Adnd2eEquipmentCatalog::searchArtifactInText($question) !== null) {
            return true;
        }
        if (Adnd2eEquipmentCatalog::searchInText($question) !== null
            && self::mentions($question, 'weight|weigh|\+ *[1-5]|magical|magic (armor|weapon|sword|plate)|ac|damage|speed')) {
            return true;
        }
        if (Adnd2eCreatureStubs::searchInText($question) !== null
            && self::mentions($question, 'thac0|thaco|\bac\b|hit dice|\bhd\b|\bdmg\b|damage')) {
            return true;
        }

        return self::wantsSpellCatalog($question);
    }

    private static function asksForOfficialBookText(string $question): bool
    {
        return (bool) preg_match(
            '/spell text|official wording|quote (the )?(rules|book|phb|dmg)|phb page|complete (wizard|priest|fighter|thief|psionic).{0,20}handbook|\bdmg\b.{0,20}(text|wording|page)/i',
            $question
        );
    }

    /**
     * @param  list<string>  $facts
     */
    private static function formatMarkdown(bool $resolved, array $facts, bool $wantsOfficialText): string
    {
        $lines = [
            '## Engine lookup (authoritative)',
            '',
            'These values come from `App\Support\Adnd2e` and related 2E helpers (SpellMaterialComponents inspect on sheet records). Use them for numeric/table answers. Do not invent PHB/DMG/Complete Handbook prose, page numbers, or extra table entries.',
            '',
        ];

        if ($resolved) {
            foreach ($facts as $fact) {
                $lines[] = '- '.$fact;
            }
            $lines[] = '';
            $lines[] = 'If the player asked for something not listed here (official spell text, unpublished tables), say the Lorefire 2E engine does not have that.';
        } else {
            $lines[] = 'The Lorefire 2E engine could not resolve a numeric or table answer for this question.';
            $lines[] = 'Say you do not have that in the engine. Do not invent official spell text, PHB pages, or handbook tables.';
            if ($wantsOfficialText) {
                $lines[] = 'This app does not ingest PHB, DMG, or Complete Handbooks.';
            }
            $lines[] = 'Campaign notes and character backstory may still inform story rulings.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectAbilityFacts(string $question, array $context, array &$facts): void
    {
        $pairs = self::parseAbilityScores($question);

        $classHint = self::parseClass($question) ?? 'Fighter';
        $wantColumn = self::parseAbilityColumn($question);

        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            foreach (self::abilityKeys() as $ability => $aliases) {
                if (! self::characterAbilityIsRelevant($question, $ability, $aliases, $wantColumn)) {
                    continue;
                }
                if (! isset($character[$ability]) || $character[$ability] === '' || $character[$ability] === null) {
                    continue;
                }
                $exc = $ability === 'strength' ? ($character['exceptional_strength'] ?? null) : null;
                $pairs[] = [
                    'ability' => $ability,
                    'score' => (int) $character[$ability],
                    'exceptional' => is_string($exc) || is_int($exc) ? (string) $exc : null,
                    'class' => (string) ($character['class'] ?? 'Fighter'),
                    'from_sheet' => (string) $character['name'],
                ];
            }
        }

        foreach ($pairs as $pair) {
            $ability = $pair['ability'];
            $score = $pair['score'];
            $exceptional = $pair['exceptional'] ?? null;
            $class = $pair['class'] ?? $classHint;
            $label = Adnd2e::formatAbilityScore($ability, $score, $exceptional);
            $prefix = isset($pair['from_sheet']) ? $pair['from_sheet'].' ' : '';
            $lines = Adnd2e::abilityAdjustmentLines($ability, $score, $exceptional, $class);

            if ($wantColumn !== null) {
                foreach ($lines as $line) {
                    if (self::columnMatches($line['label'], $wantColumn)) {
                        $facts[] = $prefix.strtoupper(self::abilityAbbrev($ability)).' '.$label.' '.$line['label'].': '.$line['value'].' (Adnd2e::abilityAdjustmentLines)';
                    }
                }
            }

            if ($ability === 'dexterity') {
                $row = Adnd2e::dexterityAdjustments($score);
                if ($wantColumn === 'missile' || ($wantColumn === null && self::mentions($question, 'missile'))) {
                    $facts[] = $prefix.'DEX '.$label.' missile: '.Adnd2e::formatSigned($row['missile']).' (Adnd2e::dexterityAdjustments)';
                }
            }

            if ($ability === 'strength' && ($wantColumn === 'open' || self::mentions($question, 'open door'))) {
                $row = Adnd2e::strengthAdjustments($score, $exceptional);
                $facts[] = $prefix.'STR '.$label.' open doors: '.$row['open_doors'].' (Adnd2e::strengthAdjustments)';
            }
            if ($ability === 'strength' && ($wantColumn === 'bend' || self::mentions($question, 'bend bar') || self::mentions($question, 'lift gate'))) {
                $row = Adnd2e::strengthAdjustments($score, $exceptional);
                $facts[] = $prefix.'STR '.$label.' bend bars/lift gates: '.$row['bend_bars'].'% (Adnd2e::strengthAdjustments)';
            }

            if ($lines !== [] && $wantColumn === null && ! self::mentions($question, 'missile|open door|bend bar')) {
                $compact = implode(', ', array_map(fn (array $line) => $line['label'].' '.$line['value'], $lines));
                $facts[] = $prefix.strtoupper(self::abilityAbbrev($ability)).' '.$label.' table: '.$compact.' (Adnd2e::abilityAdjustmentLines)';
            }
        }

        if ($pairs === [] && self::mentions($question, 'ability (score|table|adj)|exceptional strength')) {
            $dex = Adnd2e::dexterityAdjustments(17);
            $str = Adnd2e::strengthAdjustments(18, '01');
            $facts[] = 'Example DEX 17 missile: '.Adnd2e::formatSigned($dex['missile']).' (Adnd2e::dexterityAdjustments)';
            $facts[] = 'Example STR 18/01 open doors: '.$str['open_doors'].' (Adnd2e::strengthAdjustments)';
        }
    }

    /**
     * @return list<array{ability: string, score: int, exceptional: ?string, class?: string, from_sheet?: string}>
     */
    private static function parseAbilityScores(string $question): array
    {
        $pairs = [];

        if (preg_match_all('/\b(?:str(?:ength)?\s*)?18\s*\/\s*(00|\d{1,2})\b/i', $question, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[] = [
                    'ability' => 'strength',
                    'score' => 18,
                    'exceptional' => $match[1],
                ];
            }
        }

        foreach (self::abilityKeys() as $ability => $aliases) {
            $alias = implode('|', array_map(fn (string $a) => preg_quote($a, '/'), $aliases));
            if (preg_match_all('/\b(?:'.$alias.')\s*(?:score\s*)?(?:of\s*|is\s*)?(\d{1,2})\b/i', $question, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $pairs[] = [
                        'ability' => $ability,
                        'score' => (int) $match[1],
                        'exceptional' => null,
                    ];
                }
            }
            if (preg_match_all('/\b(\d{1,2})\s+(?:'.$alias.')\b/i', $question, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $pairs[] = [
                        'ability' => $ability,
                        'score' => (int) $match[1],
                        'exceptional' => null,
                    ];
                }
            }
        }

        $hasExceptional18 = false;
        foreach ($pairs as $pair) {
            if ($pair['ability'] === 'strength' && $pair['score'] === 18 && ($pair['exceptional'] ?? null) !== null) {
                $hasExceptional18 = true;
                break;
            }
        }
        if ($hasExceptional18) {
            $pairs = array_values(array_filter(
                $pairs,
                fn (array $pair) => ! ($pair['ability'] === 'strength' && $pair['score'] === 18 && ($pair['exceptional'] ?? null) === null)
            ));
        }

        return $pairs;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function abilityKeys(): array
    {
        return [
            'strength' => ['strength', 'str'],
            'dexterity' => ['dexterity', 'dex'],
            'constitution' => ['constitution', 'con'],
            'intelligence' => ['intelligence', 'int'],
            'wisdom' => ['wisdom', 'wis'],
            'charisma' => ['charisma', 'cha'],
        ];
    }

    private static function abilityAbbrev(string $ability): string
    {
        return match ($ability) {
            'strength' => 'STR',
            'dexterity' => 'DEX',
            'constitution' => 'CON',
            'intelligence' => 'INT',
            'wisdom' => 'WIS',
            'charisma' => 'CHA',
            default => $ability,
        };
    }

    private static function parseAbilityColumn(string $question): ?string
    {
        return match (true) {
            self::mentions($question, 'missile') => 'missile',
            self::mentions($question, 'reaction') => 'react',
            self::mentions($question, 'defensive|ac adj') => 'def',
            self::mentions($question, 'open door') => 'open',
            self::mentions($question, 'bend bar|lift gate') => 'bend',
            self::mentions($question, 'weight allow|wt allow|\bwt\b') => 'wt',
            self::mentions($question, 'max press|\bpress\b') => 'press',
            self::mentions($question, 'system shock|\bshock\b') => 'shock',
            self::mentions($question, 'resurrection|resurrect') => 'resurrect',
            self::mentions($question, 'poison') => 'poison',
            self::mentions($question, 'regen') => 'regen',
            self::mentions($question, 'language|\blangs?\b') => 'langs',
            self::mentions($question, 'chance to learn|\blearn\b') => 'learn',
            self::mentions($question, 'max spell level|spell lvl') => 'spell lvl',
            self::mentions($question, 'max spells|max/lvl') => 'max/lvl',
            self::mentions($question, 'magical defense|\bmd\b') => 'MD',
            self::mentions($question, 'bonus spells?') => 'bonus',
            self::mentions($question, 'spell failure|\bfail\b') => 'fail',
            self::mentions($question, 'hench') => 'hench',
            self::mentions($question, 'loyalty') => 'loyalty',
            self::mentions($question, '\bhit\b') && self::mentions($question, 'str') => 'hit',
            self::mentions($question, 'damage|\bdmg\b') && ! self::mentions($question, 'weapon') => 'dmg',
            default => null,
        };
    }

    private static function columnMatches(string $label, string $want): bool
    {
        $label = mb_strtolower($label);
        $want = mb_strtolower($want);

        return $label === $want
            || ($want === 'bend' && ($label === 'bb' || str_contains($label, 'bend')))
            || ($want === 'open' && str_contains($label, 'open'))
            || ($want === 'missile' && str_contains($label, 'missile'))
            || ($want === 'react' && str_contains($label, 'react'))
            || ($want === 'def' && (str_contains($label, 'def') || $label === 'ac'))
            || ($want === 'wt' && ($label === 'wt' || str_contains($label, 'weight')))
            || ($want === 'md' && $label === 'md')
            || ($want === 'spell lvl' && str_contains($label, 'spell'))
            || ($want === 'max/lvl' && str_contains($label, 'max'));
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectThac0Facts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'thac0|thaco|to-hit|to hit')) {
            return;
        }

        $class = self::parseClass($question);
        $levels = self::parseLevels($question, $class);

        if ($class !== null && $levels === []) {
            $levels = [1, 5, 10];
        }

        if ($class !== null) {
            foreach ($levels as $level) {
                $facts[] = $class.' '.$level.' THAC0: '.Adnd2e::thac0($class, $level).' (Adnd2e::thac0)';
            }
            if (self::mentions($question, 'progression|by level|1\s*[–-]\s*20|all levels') || $levels === []) {
                $prog = Adnd2e::thac0Progression($class);
                $parts = [];
                foreach ($prog as $level => $value) {
                    $parts[] = $level.'='.$value;
                }
                $facts[] = $class.' THAC0 1–20: '.implode(', ', $parts).' (Adnd2e::thac0Progression)';
            }
        } elseif (Adnd2eCreatureStubs::searchInText($question) === null
            && ! self::npcNameMentioned($question, $context)) {
            foreach ([
                'Fighter' => [1, 5, 10],
                'Paladin' => [1, 5],
                'Ranger' => [1, 5],
                'Cleric' => [1, 4],
                'Druid' => [1, 4],
                'Thief' => [1, 5],
                'Bard' => [1, 5],
                'Psionicist' => [1, 5],
                'Mage' => [1, 6],
            ] as $sampleClass => $sampleLevels) {
                $parts = [];
                foreach ($sampleLevels as $level) {
                    $parts[] = $level.'='.Adnd2e::thac0($sampleClass, $level);
                }
                $facts[] = $sampleClass.' THAC0 by level ('.implode(', ', $parts).') (Adnd2e::thac0)';
            }
        }

        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            $entries = self::classEntriesFromContext($character);
            $engine = Adnd2e::combinedThac0($entries);
            $stored = $character['thac0'] ?? null;
            $name = (string) $character['name'];
            $facts[] = $name.' engine THAC0: '.$engine.' (Adnd2e::combinedThac0)';
            if ($stored !== null && $stored !== '') {
                $facts[] = $name.' stored sheet THAC0: '.$stored;
            }
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectToHitFacts(string $question, array &$facts): void
    {
        $thac0 = null;
        $ac = null;
        if (preg_match('/thac0\s*(?:of\s*)?(\d{1,2})/i', $question, $match)) {
            $thac0 = (int) $match[1];
        }
        if (preg_match('/(?:\bac\b|armor class)\s*(-?\d{1,2})/i', $question, $match)) {
            $ac = (int) $match[1];
        }

        if ($thac0 !== null && $ac !== null) {
            $needed = Adnd2e::numberNeededToHit($thac0, $ac);
            $facts[] = 'THAC0 '.$thac0.' vs descending AC '.$ac.' needs '.$needed.' on d20 (Adnd2e::numberNeededToHit)';
            if (preg_match('/\broll(?:ed|ing)?\s+(\d{1,2})\b/i', $question, $rollMatch)) {
                $resolved = Adnd2e::resolveAttack($thac0, $ac, (int) $rollMatch[1]);
                $facts[] = 'd20 '.$resolved['roll'].' vs needed '.$resolved['needed'].': '.($resolved['hit'] ? 'hit' : 'miss').' (Adnd2e::resolveAttack; 1 miss / 20 hit)';
            }
        } elseif (self::mentions($question, 'needed to hit|number needed|thac0.+armor class|armor class.+thac0|descending')) {
            $facts[] = 'Number needed on d20 = THAC0 minus descending AC (Adnd2e::numberNeededToHit). Example: THAC0 20 vs AC 10 needs '.Adnd2e::numberNeededToHit(20, 10).'; vs AC 0 needs '.Adnd2e::numberNeededToHit(20, 0).'. This app treats 1 as a miss and 20 as a hit (Adnd2e::resolveAttack).';
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectSaveFacts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'sav(e|ing)|paralyzation|petrification|breath weapon|rod, staff')) {
            return;
        }

        $cats = [];
        foreach (Adnd2e::SAVE_CATEGORIES as $key => $label) {
            $cats[] = $key.' = '.$label;
        }
        $facts[] = 'Save categories (roll d20 >= target): '.implode('; ', $cats).' (Adnd2e::SAVE_CATEGORIES)';

        $class = self::parseClass($question);
        $levels = self::parseLevels($question, $class);
        $category = self::parseSaveCategory($question);
        if ($class !== null) {
            $useLevels = $levels === [] ? [1] : $levels;
            foreach ($useLevels as $level) {
                $row = Adnd2e::savingThrows($class, $level);
                if ($category !== null && isset($row[$category])) {
                    $facts[] = $class.' '.$level.' '.$category.': '.$row[$category].' (Adnd2e::savingThrows)';
                } else {
                    $parts = [];
                    foreach ($row as $key => $value) {
                        $parts[] = $key.' '.$value;
                    }
                    $facts[] = $class.' '.$level.' saves: '.implode(', ', $parts).' (Adnd2e::savingThrows)';
                }
            }
            if (self::mentions($question, 'matrix|progression|by level|bands?') || $levels === []) {
                foreach (Adnd2e::savingThrowBands($class) as $band) {
                    $parts = [];
                    foreach ($band['saves'] as $key => $value) {
                        if ($category !== null && $key !== $category) {
                            continue;
                        }
                        $parts[] = $key.' '.$value;
                    }
                    $facts[] = $class.' '.$band['from'].'–'.$band['to'].': '.implode(', ', $parts).' (Adnd2e::savingThrowBands)';
                }
            }
        } elseif (self::mentions($question, 'matrix|by (class )?group|warrior|priest|rogue|wizard')) {
            foreach (['Fighter' => 'warrior', 'Cleric' => 'priest', 'Thief' => 'rogue', 'Mage' => 'wizard'] as $sampleClass => $group) {
                $band = Adnd2e::savingThrowBands($sampleClass)[0];
                $parts = [];
                foreach ($band['saves'] as $key => $value) {
                    $parts[] = $key.' '.$value;
                }
                $facts[] = $group.' '.$band['from'].'–'.$band['to'].': '.implode(', ', $parts).' (Adnd2e::savingThrowBands)';
            }
        }

        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            $row = Adnd2e::combinedSavingThrows(self::classEntriesFromContext($character));
            $parts = [];
            foreach ($row as $key => $value) {
                $parts[] = $key.' '.$value;
            }
            $facts[] = $character['name'].' engine saves: '.implode(', ', $parts).' (Adnd2e::combinedSavingThrows)';
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectMemorizationFacts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'memoriz|vancian|spell capacity|spell slots?|memorization slots?|how many .{0,40}spells|bonus spells')) {
            return;
        }
        if (self::mentions($question, 'bonus spells')
            && self::mentions($question, 'wis')
            && ! self::mentions($question, 'memoriz|vancian|spell capacity|spell slots?|how many|mage|wizard|cleric|priest')) {
            return;
        }

        $class = self::parseClass($question) ?? (self::mentions($question, 'priest|cleric') ? 'Cleric' : 'Mage');
        $levels = self::parseLevels($question, $class);
        $level = $levels[0] ?? 1;
        $wisdom = 10;
        if (preg_match('/wis(?:dom)?\s*(?:of\s*)?(\d{1,2})/i', $question, $match)) {
            $wisdom = (int) $match[1];
        }
        $subclass = null;
        foreach (Adnd2e::SPECIALIST_SCHOOLS as $school) {
            if (preg_match('/\b'.preg_quote($school, '/').'\b/i', $question)) {
                $subclass = $school;
                break;
            }
        }

        $cap = Adnd2e::memorizationCapacity($class, $level, $wisdom, $subclass);
        $parts = [];
        foreach ($cap as $spellLevel => $count) {
            $parts[] = 'L'.$spellLevel.'='.$count;
        }
        $who = $subclass ?: $class;
        $facts[] = $who.' '.$level.' memorization: '.($parts === [] ? 'none' : implode(', ', $parts)).' (Adnd2e::memorizationCapacity)';
        $facts[] = 'Vancian copies: a known spell may be memorized up to '.Adnd2e::MAX_TIMES_MEMORIZED.' times; casting burns one copy (Adnd2e::burnMemorizedInstance).';

        if (self::mentions($question, 'progression|by level') || count($levels) > 1) {
            $sample = $levels !== [] ? $levels : [1, 5, 9, 12];
            foreach ($sample as $sampleLevel) {
                if ($sampleLevel === $level && count($levels) <= 1) {
                    continue;
                }
                $row = Adnd2e::memorizationCapacity($class, $sampleLevel, $wisdom, $subclass);
                $rowParts = [];
                foreach ($row as $spellLevel => $count) {
                    $rowParts[] = 'L'.$spellLevel.'='.$count;
                }
                $facts[] = $who.' '.$sampleLevel.' memorization: '.($rowParts === [] ? 'none' : implode(', ', $rowParts)).' (Adnd2e::memorizationCapacity)';
            }
        }

        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            $wis = isset($character['wisdom']) ? (int) $character['wisdom'] : $wisdom;
            $merged = Adnd2e::combinedMemorization(
                self::classEntriesFromContext($character),
                $wis,
                isset($character['subclass']) ? (string) $character['subclass'] : null
            );
            $sheetParts = [];
            foreach ($merged as $spellLevel => $count) {
                $sheetParts[] = 'L'.$spellLevel.'='.$count;
            }
            $facts[] = $character['name'].' engine memorization: '.($sheetParts === [] ? 'none' : implode(', ', $sheetParts)).' (Adnd2e::combinedMemorization)';
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectArmorFacts(string $question, array &$facts): void
    {
        if (preg_match('/thac0\s*(?:of\s*)?(\d{1,2})/i', $question) && preg_match('/(?:\bac\b|armor class)\s*(-?\d{1,2})/i', $question)) {
            return;
        }

        if (self::parseMagicBonus($question) !== null) {
            return;
        }

        $wantsArmor = self::mentions($question, 'unarmored|unarmoured|base ac|leather|studded|padded|chain mail|plate mail|full plate|field plate|scale mail|ring mail|splint|banded|brigandine|hide armor|shield only|armor (ac|class)|ac of');
        $armor = self::parseArmor($question);
        if (! $wantsArmor && $armor === null) {
            return;
        }

        $shield = self::mentions($question, '\bshield\b') && ! self::mentions($question, 'shield only');

        if ($armor !== null) {
            $row = Adnd2e::armorStats($armor);
            if ($row !== null) {
                $ac = Adnd2e::descendingArmorClass($armor, $shield);
                $facts[] = $row['name'].' base AC: '.$row['ac'].' (Adnd2e::armorBaseAc)';
                if ($shield && $ac !== null) {
                    $facts[] = $row['name'].' + shield AC: '.$ac.' (Adnd2e::descendingArmorClass; shield '.Adnd2e::SHIELD_AC_BONUS.')';
                }
            }
        } elseif (self::mentions($question, 'armor (ac|table|list)|base ac')) {
            $parts = [];
            foreach (Adnd2e::armorCatalog() as $row) {
                $parts[] = $row['name'].' '.$row['ac'];
            }
            $facts[] = 'Armor base AC: '.implode(', ', $parts).' (Adnd2e::armorCatalog)';
            $facts[] = 'Shield AC bonus: '.Adnd2e::SHIELD_AC_BONUS.' (Adnd2e::SHIELD_AC_BONUS)';
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectWeaponFacts(string $question, array &$facts): void
    {
        if (self::parseMagicBonus($question) !== null) {
            return;
        }

        $wantsWeapon = self::mentions($question, 'weapon speed|weapon damage|damage dice|speed factor');
        $weapon = self::parseWeapon($question);
        $namedCombat = $weapon !== null && self::mentions($question, 'damage|speed|dice|\bweapon\b');
        if (! $wantsWeapon && ! $namedCombat) {
            return;
        }

        if ($weapon !== null) {
            $row = Adnd2e::weaponStats($weapon);
            if ($row !== null) {
                $facts[] = $row['name'].' SM '.$row['sm'].' / L '.$row['l'].' / speed '.$row['speed'].' (Adnd2e::weaponStats)';
            } else {
                $facts[] = 'No weapon entry for "'.$weapon.'" in Adnd2e::weaponStats.';
            }

            return;
        }

        $parts = [];
        foreach (array_slice(Adnd2e::weaponCatalog(), 0, 8) as $row) {
            $parts[] = $row['name'].' '.$row['sm'].'/'.$row['l'].' sf'.$row['speed'];
        }
        $facts[] = 'Weapon sample (SM/L/speed): '.implode('; ', $parts).' (Adnd2e::weaponCatalog)';
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectEquipmentFacts(string $question, array &$facts): void
    {
        if (self::asksForOfficialBookText($question)) {
            return;
        }

        $artifact = Adnd2eEquipmentCatalog::searchArtifactInText($question);
        if ($artifact !== null) {
            $facts[] = Adnd2eEquipmentCatalog::formatArtifact($artifact);

            return;
        }

        $row = Adnd2eEquipmentCatalog::searchInText($question);
        if ($row === null) {
            return;
        }

        $wantsGear = ($row['bonus'] ?? 0) !== 0
            || self::mentions($question, 'weight|weigh|\+ *[1-5]|magical|magic (armor|weapon|sword|plate)');
        if (! $wantsGear) {
            return;
        }

        $facts[] = Adnd2eEquipmentCatalog::formatRow($row);
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectSpellCatalogFacts(string $question, array &$facts): void
    {
        if (! self::wantsSpellCatalog($question)) {
            return;
        }

        $hits = self::spellRowsForQuestion($question);
        if ($hits !== []) {
            foreach ($hits as $row) {
                $facts[] = Adnd2eSpellCatalog::formatRow($row);
            }

            return;
        }

        $class = self::parseClass($question);
        if ($class === null || ! self::mentions($question, 'spells')) {
            return;
        }

        $spellLevel = self::parseSpellLevel($question);
        $rows = Adnd2eSpellCatalog::forClass($class, $spellLevel);
        if ($rows === []) {
            $facts[] = $class.' has no header rows in Adnd2eSpellCatalog'
                .($spellLevel !== null ? ' at L'.$spellLevel : '').'.';

            return;
        }

        $names = array_map(fn (array $row) => $row['name'], $rows);
        $label = $class.($spellLevel !== null ? ' L'.$spellLevel : '').' names: '.implode(', ', $names);
        $facts[] = $label.' (Adnd2eSpellCatalog::forClass)';
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectCreatureFacts(string $question, array &$facts): void
    {
        if (self::asksForOfficialBookText($question)) {
            return;
        }
        if (! self::mentions($question, 'thac0|thaco|\bac\b|hit dice|\bhd\b|\bdmg\b|damage|stat stub')) {
            return;
        }

        $row = Adnd2eCreatureStubs::searchInText($question);
        if ($row === null) {
            return;
        }

        $facts[] = Adnd2eCreatureStubs::formatRow($row);
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectNpcFacts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'thac0|thaco|\bac\b|hit dice|\bhd\b|\bdmg\b|damage|stat.block|stat stub')) {
            return;
        }

        foreach (self::npcsFromContext($context) as $npc) {
            $name = (string) ($npc['name'] ?? '');
            if ($name === '' || ! self::mentionsName($question, $name)) {
                continue;
            }
            $facts[] = self::formatNpcEngineLine($npc);
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectSphereFacts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'sphere')) {
            return;
        }

        $class = self::parseClass($question);
        if ($class === null) {
            $class = self::mentions($question, 'druid') ? 'Druid' : 'Cleric';
        }
        $access = Adnd2e::priestSpheres($class);
        $facts[] = $class.' major: '.($access['major'] === [] ? 'none' : implode(', ', $access['major'])).' (Adnd2e::priestSpheres)';
        $facts[] = $class.' minor: '.($access['minor'] === [] ? 'none' : implode(', ', $access['minor'])).' (Adnd2e::priestSpheres)';
        $facts[] = 'Sphere tags only. No spell names or handbook text.';

        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            $stored = $character['priest_spheres'] ?? null;
            if (is_array($stored)) {
                $major = implode(', ', $stored['major'] ?? []);
                $minor = implode(', ', $stored['minor'] ?? []);
                $facts[] = $character['name'].' sheet spheres major: '.($major !== '' ? $major : 'none').'; minor: '.($minor !== '' ? $minor : 'none');
            }
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectEncumbranceFacts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'encumbrance|weight allow|max press|carried|movement rate|move rate|encumber')) {
            return;
        }

        $race = self::parseRace($question) ?? 'Human';
        $facts[] = $race.' movement rate: '.Adnd2e::movementRate($race).' (Adnd2e::movementRate)';

        $strength = 10;
        $exceptional = null;
        foreach (self::parseAbilityScores($question) as $pair) {
            if ($pair['ability'] === 'strength') {
                $strength = $pair['score'];
                $exceptional = $pair['exceptional'] ?? null;
                break;
            }
        }
        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            if (isset($character['strength'])) {
                $strength = (int) $character['strength'];
                $exceptional = isset($character['exceptional_strength']) ? (string) $character['exceptional_strength'] : $exceptional;
            }
            if (! empty($character['race'])) {
                $race = (string) $character['race'];
            }
        }

        $wantsLoad = self::mentions($question, 'encumbrance|weight allow|max press|carried|encumber');
        if ($wantsLoad || self::parseAbilityScores($question) !== []) {
            $t = Adnd2e::encumbranceThresholds($strength, $exceptional);
            $label = Adnd2e::formatAbilityScore('strength', $strength, $exceptional);
            $facts[] = 'STR '.$label.' encumbrance lb: none '.$t['none'].', light '.$t['light'].', moderate '.$t['moderate'].', heavy '.$t['heavy'].', severe '.$t['severe'].' (Adnd2e::encumbranceThresholds)';
            $facts[] = 'STR '.$label.' weight allow '.$t['weight_allow'].', max press '.$t['max_press'].' (Adnd2e::strengthAdjustments)';

            $carried = null;
            if (preg_match('/(?:carried|carrying|load(?:ed)?|weighs?)\s+(\d{1,4})\s*(?:lb|lbs|pounds?)?/i', $question, $match)) {
                $carried = (int) $match[1];
            } elseif (preg_match('/(\d{1,4})\s*(?:lb|lbs|pounds)/i', $question, $match)) {
                $carried = (int) $match[1];
            }
            if ($carried !== null) {
                $load = Adnd2e::movementAtLoad($race, $carried, $strength, $exceptional);
                $facts[] = $race.' carrying '.$carried.' lb: '.$load['category'].', MV '.$load['movement'].' (base '.$load['base'].') (Adnd2e::movementAtLoad)';
            } else {
                foreach (['none', 'light', 'moderate', 'heavy', 'severe'] as $category) {
                    $facts[] = $race.' '.$category.' MV: '.Adnd2e::movementAtEncumbrance($race, $category).' (Adnd2e::movementAtEncumbrance)';
                }
            }
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectDualClassFacts(string $question, array &$facts): void
    {
        if (! self::mentions($question, 'dual-?class|begin a new class|resume (the )?original|house switch')) {
            return;
        }

        $min = Adnd2e::HOUSE_DUAL_MIN_ORIGINAL_LEVEL;
        $resume = Adnd2e::HOUSE_DUAL_RESUME_NEW_LEVEL;
        $facts[] = 'House dual-class: original class must be '.$min.'th before a new class may begin (Adnd2e::canBeginNewClass / HOUSE_DUAL_MIN_ORIGINAL_LEVEL).';
        $facts[] = 'Resume the original class when the new class is '.$resume.'th (Adnd2e::canResumeOriginalClass / HOUSE_DUAL_RESUME_NEW_LEVEL). Do not apply PHB dual-class XP penalties.';

        $levels = self::parseLevels($question, self::parseClass($question));
        if ($levels !== []) {
            $level = $levels[0];
            $facts[] = 'canBeginNewClass('.$level.'): '.(Adnd2e::canBeginNewClass($level) ? 'yes' : 'no');
            $facts[] = 'canResumeOriginalClass('.$level.'): '.(Adnd2e::canResumeOriginalClass($level) ? 'yes' : 'no');
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectCombatMiscFacts(string $question, array &$facts): void
    {
        if (self::mentions($question, 'hit dice|hit die')) {
            $class = self::parseClass($question);
            if ($class !== null) {
                $facts[] = $class.' hit die: '.Adnd2e::hitDie($class).' (Adnd2e::hitDie)';
            } else {
                $facts[] = 'Hit dice by group: warrior '.Adnd2e::hitDie('Fighter').', priest '.Adnd2e::hitDie('Cleric').', rogue '.Adnd2e::hitDie('Thief').', wizard '.Adnd2e::hitDie('Mage').' (Adnd2e::hitDie)';
            }
        }

        if (self::mentions($question, 'initiative')) {
            $dex = 10;
            if (preg_match('/dex(?:terity)?\s*(?:of\s*)?(\d{1,2})/i', $question, $match)) {
                $dex = (int) $match[1];
            }
            $d10 = 5;
            if (preg_match('/\bd10\s*(?:of\s*|roll(?:ed|ing)?\s*)?(\d{1,2})\b/i', $question, $match)) {
                $d10 = (int) $match[1];
            }
            $row = Adnd2e::resolveInitiative($d10, $dex);
            $facts[] = 'Initiative is d10 (lower first). DEX '.$dex.' reaction '.Adnd2e::formatSigned(Adnd2e::dexterityAdjustments($dex)['reaction']).'; example d10 '.$d10.' total '.$row['total'].' (Adnd2e::resolveInitiative).';
        }

        if (self::mentions($question, 'overnight rest|natural healing')) {
            $facts[] = 'Overnight rest only: recover 1 hit point if above '.Adnd2e::DEATH_THRESHOLD.', rememorize, reset daily class abilities (Adnd2e::overnightRest).';
        }

        if (self::mentions($question, 'vitality|death threshold|unconscious|dying')) {
            $facts[] = 'Vitality: 0 unconscious; negative dying; dead at '.Adnd2e::DEATH_THRESHOLD.' (Adnd2e::vitalityState).';
        }

        if (self::mentions($question, 'weapon proficiency|non-?weapon proficiency')) {
            $class = self::parseClass($question) ?? 'Fighter';
            $wpn = Adnd2e::weaponProficiencySlots($class);
            $nwp = Adnd2e::nonweaponProficiencySlots($class);
            $facts[] = $class.' weapon proficiency slots: initial '.$wpn['initial'].', gain every '.$wpn['gain_every'].' (Adnd2e::weaponProficiencySlots)';
            $facts[] = $class.' non-weapon proficiency slots: initial '.$nwp['initial'].', gain every '.$nwp['gain_every'].' (Adnd2e::nonweaponProficiencySlots)';
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectMaterialFacts(string $question, array $context, array &$facts): void
    {
        if (! self::mentions($question, 'material|component|focus|foci')) {
            return;
        }

        $spellName = self::parseSpellName($question);
        $characterName = self::parseMentionedCharacterName($question, $context);
        $catalogRow = Adnd2eSpellCatalog::searchInText($question);

        if ($spellName === null) {
            $facts[] = 'Material/focus availability is SpellMaterialComponents::inspect on a sheet spell record. This engine does not contain PHB component lists. Name a character and a spell stored on their sheet.';

            return;
        }

        $character = self::findCharacterByName($characterName);
        if ($character === null && $characterName !== null) {
            $facts[] = 'No sheet character named "'.$characterName.'" was found for SpellMaterialComponents::inspect. The engine does not invent PHB components for '.$spellName.'.';

            return;
        }
        if ($character === null) {
            if ($catalogRow !== null && ! self::asksForOfficialBookText($question)) {
                return;
            }
            $facts[] = 'SpellMaterialComponents::inspect needs a sheet character. No matching character was named. The engine does not invent PHB components for '.$spellName.'.';

            return;
        }

        $spell = $character->spells->first(
            fn (CharacterSpell $spell) => strcasecmp((string) $spell->name, $spellName) === 0
        );
        if ($spell === null) {
            $facts[] = $character->name.' has no sheet spell named "'.$spellName.'". SpellMaterialComponents::inspect cannot answer; the engine does not ingest PHB spell text.';

            return;
        }

        $plan = SpellMaterialComponents::inspect($character, $spell);
        $sheetReqs = SpellMaterialComponents::parse($spell->components, $spell->description);
        $reqs = SpellMaterialComponents::requirementsFor($spell, $character);
        if ($reqs === []) {
            $facts[] = $character->name.' / '.$spell->name.': no named material or focus items on the sheet record or Adnd2eSpellCatalog. Codes such as V/S/M alone do not invent PHB items.';
        } else {
            $parts = [];
            foreach ($reqs as $req) {
                $kind = $req['focus'] ? 'focus' : 'material';
                $parts[] = $req['name'].' ×'.$req['quantity'].' ('.$kind.')';
            }
            $source = $sheetReqs === [] ? 'Adnd2eSpellCatalog' : 'sheet (SpellMaterialComponents::parse)';
            $facts[] = $character->name.' / '.$spell->name.' '.$source.' requirements: '.implode('; ', $parts);
        }

        if ($plan['ok']) {
            $facts[] = $character->name.' can supply those sheet requirements right now (SpellMaterialComponents::inspect ok).';
        } else {
            $facts[] = $plan['error'] ?? ($character->name.' is missing sheet components for '.$spell->name);
        }
    }

    /**
     * @param  list<string>  $facts
     */
    private static function collectCharacterSheetFacts(string $question, array $context, array &$facts): void
    {
        foreach (self::charactersFromContext($context) as $character) {
            if (! self::mentionsName($question, (string) ($character['name'] ?? ''))) {
                continue;
            }
            if (isset($character['armor_class']) && $character['armor_class'] !== '' && $character['armor_class'] !== null
                && self::mentions($question, '\bac\b|armor class')) {
                $facts[] = $character['name'].' stored descending AC: '.$character['armor_class'];
            }
            if (isset($character['current_hp']) && self::mentions($question, '\bhp\b|hit points|vitality')) {
                $hp = (int) $character['current_hp'];
                $facts[] = $character['name'].' HP '.$hp.'/'.($character['max_hp'] ?? '?').' vitality '.Adnd2e::vitalityState($hp).' (Adnd2e::vitalityState)';
            }
        }
    }

    private static function parseSaveCategory(string $question): ?string
    {
        return match (true) {
            self::mentions($question, 'paralyz|poison|death magic') => 'paralyzation',
            self::mentions($question, 'rod|staff|wand') => 'rod',
            self::mentions($question, 'petrif|polymorph') => 'petrification',
            self::mentions($question, 'breath') => 'breath',
            self::mentions($question, 'vs spells?|save.{0,16}spell|throws? vs spell') => 'spell',
            default => null,
        };
    }

    private static function parseArmor(string $question): ?string
    {
        $needles = [
            'shield only', 'full plate', 'field plate', 'bronze plate', 'plate mail',
            'studded leather', 'studded', 'chain mail', 'ring mail', 'scale mail',
            'splint mail', 'banded mail', 'brigandine', 'hide armor', 'padded',
            'leather', 'unarmored', 'unarmoured', 'no armor',
        ];
        foreach ($needles as $needle) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $question)) {
                return $needle;
            }
        }

        return null;
    }

    private static function parseWeapon(string $question): ?string
    {
        $needles = [
            'two-handed sword', 'two handed sword', 'short sword', 'long sword', 'bastard sword',
            'hand axe', 'battle axe', 'short bow', 'long bow', 'light crossbow', 'crossbow, light',
            'heavy crossbow', 'crossbow, heavy', 'morning star', 'quarterstaff', 'warhammer',
            'halberd', 'javelin', 'dagger', 'dart', 'spear', 'mace', 'sling', 'flail',
            'lance', 'club', 'staff',
        ];
        foreach ($needles as $needle) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $question)) {
                return $needle;
            }
        }

        return null;
    }

    private static function parseClass(string $question): ?string
    {
        $aliases = [
            'psionicist' => 'Psionicist',
            'psionic' => 'Psionicist',
            'psion' => 'Psionicist',
            'paladin' => 'Paladin',
            'ranger' => 'Ranger',
            'warrior' => 'Fighter',
            'fighter' => 'Fighter',
            'illusionist' => 'Mage',
            'wizard' => 'Mage',
            'mage' => 'Mage',
            'warlock' => 'Mage',
            'sorcerer' => 'Mage',
            'cleric' => 'Cleric',
            'priest' => 'Cleric',
            'druid' => 'Druid',
            'thief' => 'Thief',
            'rogue' => 'Thief',
            'bard' => 'Bard',
        ];
        foreach ($aliases as $needle => $class) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $question)) {
                return $class;
            }
        }

        return null;
    }

    private static function classLevelNeedles(string $class): string
    {
        return match ($class) {
            'Fighter' => 'fighter|warrior',
            'Paladin' => 'paladin',
            'Ranger' => 'ranger',
            'Mage' => 'mage|wizard|illusionist|warlock|sorcerer',
            'Cleric' => 'cleric|priest',
            'Druid' => 'druid',
            'Thief' => 'thief|rogue',
            'Bard' => 'bard',
            'Psionicist' => 'psionicist|psionic|psion',
            default => preg_quote($class, '/'),
        };
    }

    private static function parseRace(string $question): ?string
    {
        foreach (Adnd2e::RACES as $race) {
            if (preg_match('/\b'.preg_quote($race, '/').'\b/i', $question)) {
                return $race;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function parseLevels(string $question, ?string $class): array
    {
        $levels = [];
        if (preg_match_all('/level\s+(\d{1,2})/i', $question, $matches)) {
            $levels = array_merge($levels, array_map('intval', $matches[1]));
        }
        if (preg_match_all('/(\d{1,2})(?:st|nd|rd|th)[-\s]*level/i', $question, $matches)) {
            $levels = array_merge($levels, array_map('intval', $matches[1]));
        }
        if (preg_match_all('/\b(?:lvl|lv)\.?\s*(\d{1,2})\b/i', $question, $matches)) {
            $levels = array_merge($levels, array_map('intval', $matches[1]));
        }
        if ($class !== null) {
            $needles = self::classLevelNeedles($class);
            if (preg_match_all('/\b(?:'.$needles.')s?\s+(?:at\s+)?(\d{1,2})\b/i', $question, $matches)) {
                $levels = array_merge($levels, array_map('intval', $matches[1]));
            }
            if (preg_match_all('/\b(\d{1,2})\s+(?:'.$needles.')\b/i', $question, $matches)) {
                $levels = array_merge($levels, array_map('intval', $matches[1]));
            }
        }
        if (preg_match('/(?:thac0|saves?).{0,40}?(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{1,2})/i', $question, $match)) {
            $levels = array_merge($levels, [(int) $match[1], (int) $match[2], (int) $match[3]]);
        }

        $levels = array_values(array_unique(array_filter($levels, fn (int $n) => $n >= 1 && $n <= 20)));

        return $levels;
    }

    private static function parseSpellName(string $question): ?string
    {
        $row = Adnd2eSpellCatalog::searchInText($question);
        if ($row !== null) {
            return $row['name'];
        }
        if (preg_match('/["“\']([A-Za-z][A-Za-z \'-]{1,40})["”\']/', $question, $match)) {
            return trim($match[1]);
        }
        if (preg_match('/(?:spell|components? for|materials? for|cast(?:ing)?)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3})/', $question, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private static function wantsSpellCatalog(string $question): bool
    {
        if (self::asksForOfficialBookText($question)) {
            return false;
        }

        $hits = Adnd2eSpellCatalog::searchAllInText($question);
        if ($hits !== []) {
            $longest = 0;
            foreach ($hits as $row) {
                $longest = max($longest, strlen($row['name']));
            }
            $distinctive = $longest >= 8 || self::spellHitIsMultiWord($hits);
            if ($distinctive || self::mentions($question, 'spell|cast|components|casting time|school|sphere|duration|\brange\b|what level|spell level|level is')) {
                return true;
            }
        }

        return self::mentions($question, 'spells') && self::parseClass($question) !== null
            && ! self::mentions($question, 'memoriz|vancian|spell capacity|spell slots?|how many');
    }

    /**
     * @param  list<array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}>  $hits
     */
    private static function spellHitIsMultiWord(array $hits): bool
    {
        foreach ($hits as $row) {
            if (str_contains($row['name'], ' ') || str_contains($row['name'], ',')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}>
     */
    private static function spellRowsForQuestion(string $question): array
    {
        $hits = Adnd2eSpellCatalog::searchAllInText($question);
        if ($hits === []) {
            return [];
        }

        $bestLen = 0;
        foreach ($hits as $row) {
            $bestLen = max($bestLen, strlen($row['name']));
        }
        $hits = array_values(array_filter($hits, fn (array $row) => strlen($row['name']) === $bestLen));

        $class = self::parseClass($question);
        if ($class === null) {
            return $hits;
        }

        $forClass = array_values(array_filter(
            $hits,
            fn (array $row) => in_array($class, $row['classes'], true)
        ));

        return $forClass !== [] ? $forClass : $hits;
    }

    private static function parseSpellLevel(string $question): ?int
    {
        if (preg_match('/(\d{1,2})(?:st|nd|rd|th)?[-\s]+level(?:\s+\w+)?\s+spells/i', $question, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/spells?.{0,24}level\s+(\d{1,2})/i', $question, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/level\s+(\d{1,2})\s+spells/i', $question, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private static function parseMagicBonus(string $question): ?int
    {
        if (preg_match('/\+\s*([1-5])\b/', $question, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function npcNameMentioned(string $question, array $context): bool
    {
        foreach (self::npcsFromContext($context) as $npc) {
            if (self::mentionsName($question, (string) ($npc['name'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private static function npcsFromContext(array $context): array
    {
        $npcs = [];
        foreach ($context['campaigns'] ?? [] as $campaign) {
            if (! is_array($campaign)) {
                continue;
            }
            foreach ($campaign['npcs'] ?? [] as $npc) {
                if (is_array($npc)) {
                    $npcs[] = $npc;
                }
            }
        }

        return $npcs;
    }

    /**
     * @param  array<string, mixed>  $npc
     */
    private static function formatNpcEngineLine(array $npc): string
    {
        return Adnd2eOracleBriefing::formatNpcLine($npc).' (campaign NPC)';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function parseMentionedCharacterName(string $question, array $context): ?string
    {
        foreach (self::charactersFromContext($context) as $character) {
            $name = (string) ($character['name'] ?? '');
            if (self::mentionsName($question, $name)) {
                return $name;
            }
        }

        if (preg_match('/\b(?:does|can|has)\s+([A-Z][a-z]{2,20})\b/', $question, $match)) {
            return $match[1];
        }

        return null;
    }

    private static function findCharacterByName(?string $name): ?Character
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        try {
            $app = Container::getInstance();
            if ($app === null || ! $app->bound('db')) {
                return null;
            }

            return Character::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
                ->with(['spells', 'inventoryItems'])
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private static function charactersFromContext(array $context): array
    {
        $characters = [];
        foreach ($context['campaigns'] ?? [] as $campaign) {
            if (! is_array($campaign)) {
                continue;
            }
            foreach ($campaign['characters'] ?? [] as $character) {
                if (is_array($character)) {
                    $characters[] = $character;
                }
            }
        }

        return $characters;
    }

    /**
     * @param  array<string, mixed>  $character
     * @return array<int, array{class: string, level: int}>
     */
    private static function classEntriesFromContext(array $character): array
    {
        return Adnd2e::normalizeClassLevels(
            is_array($character['class_levels'] ?? null) ? $character['class_levels'] : null,
            (string) ($character['class'] ?? 'Fighter'),
            (int) ($character['level'] ?? 1),
            (string) ($character['class_path'] ?? 'single')
        );
    }

    /**
     * @param  list<string>  $aliases
     */
    private static function characterAbilityIsRelevant(string $question, string $ability, array $aliases, ?string $wantColumn): bool
    {
        if (self::mentionsAbility($question, $aliases)) {
            return true;
        }

        return match ($ability) {
            'dexterity' => in_array($wantColumn, ['missile', 'react', 'def'], true),
            'strength' => in_array($wantColumn, ['open', 'bend', 'hit', 'dmg', 'wt', 'press'], true),
            'constitution' => in_array($wantColumn, ['shock', 'resurrect', 'poison', 'regen'], true),
            'intelligence' => in_array($wantColumn, ['langs', 'learn', 'spell lvl', 'max/lvl'], true),
            'wisdom' => in_array($wantColumn, ['MD', 'bonus', 'fail'], true),
            'charisma' => in_array($wantColumn, ['hench', 'loyalty', 'react'], true),
            default => $wantColumn === null && self::mentions($question, 'ability'),
        };
    }

    /**
     * @param  list<string>  $aliases
     */
    private static function mentionsAbility(string $question, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (preg_match('/\b'.preg_quote($alias, '/').'\b/i', $question)) {
                return true;
            }
        }

        return false;
    }

    private static function mentionsName(string $question, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        return (bool) preg_match('/\b'.preg_quote($name, '/').'\b/i', $question);
    }

    private static function mentions(string $question, string $pattern): bool
    {
        return (bool) preg_match('#'.$pattern.'#i', $question);
    }
}
