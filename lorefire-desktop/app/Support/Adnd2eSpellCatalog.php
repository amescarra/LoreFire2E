<?php

namespace App\Support;

/**
 * Structured 2E spell headers only: name, class, level, school/sphere,
 * components, time, range, duration, optional material names.
 * No effect prose.
 */
class Adnd2eSpellCatalog
{
    /**
     * @return array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}|null
     */
    public static function find(string $name): ?array
    {
        return self::findBest($name);
    }

    /**
     * Prefer a class match, then a spell-level match, when the same name
     * exists more than once (Hold Person is Mage L3 and Cleric L2).
     *
     * @return array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}|null
     */
    public static function findBest(string $name, ?string $class = null, ?int $level = null): ?array
    {
        $want = self::norm($name);
        if ($want === '') {
            return null;
        }

        $matches = [];
        foreach (self::rows() as $row) {
            if (self::norm($row['name']) === $want) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        $class = $class !== null && trim($class) !== '' ? Adnd2e::normalizeClass($class) : null;
        $best = $matches[0];
        $bestScore = -1;
        foreach ($matches as $row) {
            $score = 0;
            if ($class !== null && in_array($class, $row['classes'], true)) {
                $score += 2;
            }
            if ($level !== null && $row['level'] === $level) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Header codes plus optional parenthetical catalog material names.
     *
     * @param  array{components: string, materials: list<string>}  $row
     */
    public static function componentsWithMaterials(array $row, ?string $existingComponents = null): string
    {
        $codes = trim((string) ($existingComponents ?? ''));
        if ($codes === '') {
            $codes = trim((string) ($row['components'] ?? ''));
        }
        $materials = array_values(array_filter(
            $row['materials'] ?? [],
            fn ($name) => trim((string) $name) !== '',
        ));
        if ($materials === []) {
            return $codes;
        }

        return trim($codes.' ('.implode(', ', $materials).')');
    }

    /**
     * Longest catalog name mentioned in free text.
     *
     * @return array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}|null
     */
    public static function searchInText(string $text): ?array
    {
        $hits = self::searchAllInText($text);
        if ($hits === []) {
            return null;
        }

        $bestLen = 0;
        $best = $hits[0];
        foreach ($hits as $row) {
            $len = strlen($row['name']);
            if ($len > $bestLen) {
                $best = $row;
                $bestLen = $len;
            }
        }

        return $best;
    }

    /**
     * All catalog rows whose name appears in free text (same name may exist per class).
     *
     * @return list<array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}>
     */
    public static function searchAllInText(string $text): array
    {
        $hits = [];
        foreach (self::rows() as $row) {
            if (preg_match('/\b'.preg_quote($row['name'], '/').'\b/i', $text)) {
                $hits[] = $row;
            }
        }

        return $hits;
    }

    /**
     * @return list<array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}>
     */
    public static function forClass(string $class, ?int $level = null): array
    {
        $class = Adnd2e::normalizeClass($class);
        $out = [];
        foreach (self::rows() as $row) {
            if (! in_array($class, $row['classes'], true)) {
                continue;
            }
            if ($level !== null && $row['level'] !== $level) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}  $row
     */
    public static function formatRow(array $row): string
    {
        $line = $row['name'].': '.implode('/', $row['classes']).' L'.$row['level'].' '.$row['tag']
            .'; '.$row['components']
            .'; time '.$row['casting_time']
            .'; range '.$row['range']
            .'; duration '.$row['duration'];
        if ($row['materials'] !== []) {
            $line .= '; M names: '.implode(', ', $row['materials']);
        }

        return $line.' (Adnd2eSpellCatalog)';
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (array $row) => $row['name'], self::rows());
    }

    private static function norm(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/^(the|a|an)\s+/', '', $name) ?? $name;

        return preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;
    }

    /**
     * @param  list<string>  $classes
     * @param  list<string>  $materials
     * @return array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}
     */
    private static function row(
        string $name,
        array $classes,
        int $level,
        string $tag,
        string $components,
        string $time,
        string $range,
        string $duration,
        array $materials = [],
    ): array {
        return [
            'name' => $name,
            'classes' => $classes,
            'level' => $level,
            'tag' => $tag,
            'components' => $components,
            'casting_time' => $time,
            'range' => $range,
            'duration' => $duration,
            'materials' => $materials,
        ];
    }

    /**
     * @return list<array{name: string, classes: list<string>, level: int, tag: string, components: string, casting_time: string, range: string, duration: string, materials: list<string>}>
     */
    private static function rows(): array
    {
        $w = ['Mage'];
        $wb = ['Mage', 'Bard'];
        $c = ['Cleric'];
        $cp = ['Cleric', 'Paladin'];
        $d = ['Druid'];
        $dr = ['Druid', 'Ranger'];

        return [
            self::row('Armor', $w, 1, 'abjuration', 'V, S, M', '1 rd', 'Touch', 'Special', ['leather']),
            self::row('Burning Hands', $w, 1, 'invocation', 'V, S', '1', '0', 'Instantaneous'),
            self::row('Charm Person', $wb, 1, 'enchantment', 'V, S', '1', '120 yds', 'Special'),
            self::row('Detect Magic', $wb, 1, 'divination', 'V, S', '1', '0', '2 rds/level'),
            self::row('Friends', $wb, 1, 'enchantment', 'V, S, M', '1', '0', '1d4 rds + 1 rd/level', ['rose petals']),
            self::row('Grease', $w, 1, 'invocation', 'V, S, M', '1', '10 yds', '3 rds + 1 rd/level', ['butter']),
            self::row('Identify', $wb, 1, 'divination', 'V, S, M', 'Special', '0', '1 rd/level', ['pearl', 'owl feather', 'wine']),
            self::row('Light', $wb, 1, 'alteration', 'V, M', '1', '60 yds', '1 turn/level', ['firefly']),
            self::row('Magic Missile', $w, 1, 'invocation', 'V, S', '1', '60 yds + 10 yds/level', 'Instantaneous'),
            self::row('Read Magic', $w, 1, 'divination', 'V, S, M', '1 rd', '0', '2 rds/level', ['crystal']),
            self::row('Shield', $w, 1, 'abjuration', 'V, S', '1', '0', '5 rds/level'),
            self::row('Sleep', $wb, 1, 'enchantment', 'V, S, M', '1', '30 yds', '5 rds/level', ['sand']),
            self::row('Spider Climb', $w, 1, 'alteration', 'V, S, M', '1', 'Touch', '3 rds + 1 rd/level', ['bitumen', 'spider']),
            self::row('Unseen Servant', $w, 1, 'conjuration', 'V, S, M', '1', '0', '1 hr + 1 turn/level', ['string', 'wood']),
            self::row('Detect Invisibility', $w, 2, 'divination', 'V, S, M', '2', '10 yds/level', '5 rds/level', ['talc', 'powdered silver']),
            self::row('Invisibility', $wb, 2, 'illusion', 'V, S, M', '2', 'Touch', 'Special', ['eyelash', 'gum arabic']),
            self::row('Knock', $w, 2, 'alteration', 'V', '1', '60 yds', 'Special'),
            self::row('Levitate', $w, 2, 'alteration', 'V, S, M', '2', '20 yds/level', '1 turn/level', ['leather loop']),
            self::row('Mirror Image', $wb, 2, 'illusion', 'V, S', '2', '0', '3 rds/level'),
            self::row('Stinking Cloud', $w, 2, 'invocation', 'V, S, M', '2', '30 yds', '1 rd/level', ['rotten egg']),
            self::row('Strength', $w, 2, 'alteration', 'V, S, M', '1 turn', 'Touch', '1 hr/level', ['hair']),
            self::row('Web', $w, 2, 'invocation', 'V, S, M', '2', '5 yds/level', '2 turns/level', ['spider web']),
            self::row('Blink', $w, 3, 'alteration', 'V, S', '1', '0', '1 rd/level'),
            self::row('Dispel Magic', array_merge($w, $c), 3, 'abjuration', 'V, S', '3', '120 yds', 'Instantaneous'),
            self::row('Fireball', $w, 3, 'invocation', 'V, S, M', '3', '10 yds + 10 yds/level', 'Instantaneous', ['bat guano', 'sulfur']),
            self::row('Fly', $w, 3, 'alteration', 'V, S, M', '3', 'Touch', '1 turn/level + 1d6 turns', ['wing feather']),
            self::row('Haste', $w, 3, 'alteration', 'V, S, M', '3', '60 yds', '3 rds + 1 rd/level', ['licorice']),
            self::row('Hold Person', $w, 3, 'enchantment', 'V, S, M', '3', '120 yds', '2 rds/level', ['iron']),
            self::row('Lightning Bolt', $w, 3, 'invocation', 'V, S, M', '3', '40 yds + 10 yds/level', 'Instantaneous', ['fur', 'amber']),
            self::row('Slow', $w, 3, 'alteration', 'V, S, M', '3', '90 yds + 10 yds/level', '3 rds + 1 rd/level', ['treacle']),
            self::row('Suggestion', $wb, 3, 'enchantment', 'V, M', '3', '30 yds', '1 hr + 1 hr/level', ['snake tongue', 'honeycomb']),
            self::row('Water Breathing', $w, 3, 'alteration', 'V, S, M', '3', 'Touch', '1 hr/level + 1d4 hrs', ['reed']),
            self::row('Charm Monster', $w, 4, 'enchantment', 'V, S', '4', '60 yds', 'Special'),
            self::row('Confusion', $w, 4, 'enchantment', 'V, S, M', '4', '120 yds', '2 rds + 1 rd/level', ['nuts']),
            self::row('Dimension Door', $w, 4, 'alteration', 'V', '1', '0', 'Instantaneous'),
            self::row('Ice Storm', $w, 4, 'invocation', 'V, S, M', '4', '10 yds/level', 'Special', ['dust', 'water']),
            self::row('Minor Globe of Invulnerability', $w, 4, 'abjuration', 'V, S, M', '4', '0', '1 rd/level', ['glass']),
            self::row('Polymorph Other', $w, 4, 'alteration', 'V, S, M', '4', '5 yds/level', 'Permanent', ['cocoon']),
            self::row('Polymorph Self', $w, 4, 'alteration', 'V, S', '4', '0', '2 turns/level'),
            self::row('Stoneskin', $w, 4, 'abjuration', 'V, S, M', '1', 'Touch', 'Special', ['granite', 'diamond dust']),
            self::row('Wall of Fire', $w, 4, 'invocation', 'V, S, M', '4', '60 yds', 'Special', ['phosphorus']),
            self::row('Animate Dead', array_merge($w, $c), 5, 'necromancy', 'V, S, M', '5 rds', '10 yds', 'Permanent', ['bone']),
            self::row('Cloudkill', $w, 5, 'invocation', 'V, S', '5', '10 yds', '1 rd/level'),
            self::row('Cone of Cold', $w, 5, 'invocation', 'V, S, M', '5', '0', 'Instantaneous', ['crystal']),
            self::row('Feeblemind', $w, 5, 'enchantment', 'V, S, M', '5', '10 yds/level', 'Permanent', ['clay', 'dung']),
            self::row('Hold Monster', $w, 5, 'enchantment', 'V, S, M', '5', '5 yds/level', '1 rd/level', ['iron']),
            self::row('Passwall', $w, 5, 'alteration', 'V, S, M', '5', '30 yds', '1 hr + 1 turn/level', ['sesame']),
            self::row('Teleport', $w, 5, 'alteration', 'V', '2', 'Touch', 'Instantaneous'),
            self::row('Wall of Stone', $w, 5, 'invocation', 'V, S, M', '5', '5 yds/level', 'Permanent', ['granite']),
            self::row('Anti-Magic Shell', $w, 6, 'abjuration', 'V, S', '1', '0', '1 turn/level'),
            self::row('Chain Lightning', $w, 6, 'invocation', 'V, S, M', '5', '40 yds + 5 yds/level', 'Instantaneous', ['fur', 'amber', 'glass']),
            self::row('Death Spell', $w, 6, 'necromancy', 'V, S, M', '6', '10 yds/level', 'Instantaneous', ['black pearl']),
            self::row('Disintegrate', $w, 6, 'alteration', 'V, S, M', '6', '5 yds/level', 'Instantaneous', ['lodestone']),
            self::row('Globe of Invulnerability', $w, 6, 'abjuration', 'V, S, M', '1 rd', '0', '1 rd/level', ['glass']),
            self::row('Stone to Flesh', $w, 6, 'alteration', 'V, S, M', '6', '10 yds/level', 'Permanent', ['earth', 'blood']),
            self::row('Finger of Death', $w, 7, 'necromancy', 'V, S', '5', '60 yds', 'Permanent'),
            self::row('Limited Wish', $w, 7, 'conjuration', 'V', 'Special', 'Special', 'Special'),
            self::row('Power Word, Stun', $w, 7, 'conjuration', 'V', '1', '5 yds/level', 'Special'),
            self::row('Teleport Without Error', $w, 7, 'alteration', 'V', '1', 'Touch', 'Instantaneous'),
            self::row('Incendiary Cloud', $w, 8, 'alteration', 'V, S, M', '2', '30 yds', '4 rds + 1d6 rds', ['ash', 'dust']),
            self::row('Mass Charm', $w, 8, 'enchantment', 'V', '8', '5 yds/level', 'Special'),
            self::row('Mind Blank', $w, 8, 'abjuration', 'V, S', '1', '30 yds', '1 day'),
            self::row('Permanency', $w, 8, 'alteration', 'V, S, M', '2 rds', 'Special', 'Permanent', ['drop of blood']),
            self::row('Power Word, Blind', $w, 8, 'conjuration', 'V', '1', '5 yds/level', 'Special'),
            self::row('Gate', array_merge($w, $c), 9, 'conjuration', 'V, S', '9', '30 yds', 'Special'),
            self::row('Meteor Swarm', $w, 9, 'invocation', 'V, S', '9', '40 yds + 10 yds/level', 'Instantaneous'),
            self::row('Power Word, Kill', $w, 9, 'conjuration', 'V', '1', '5 yds/2 levels', 'Permanent'),
            self::row('Shape Change', $w, 9, 'alteration', 'V, S', '9', '0', '1 turn/level'),
            self::row('Time Stop', $w, 9, 'alteration', 'V', '9', '0', 'Special'),
            self::row('Wish', $w, 9, 'conjuration', 'V', 'Special', 'Special', 'Special'),

            self::row('Bless', $cp, 1, 'All', 'V, S, M', '1 rd', '60 yds', '6 rds', ['holy water']),
            self::row('Command', $c, 1, 'Charm', 'V', '1', '30 yds', '1 rd'),
            self::row('Cure Light Wounds', $cp, 1, 'Healing', 'V, S', '5', 'Touch', 'Permanent'),
            self::row('Detect Evil', $cp, 1, 'Divination', 'V, S, M', '1 rd', '0', '1 turn + 5 rds/level', ['holy symbol']),
            self::row('Protection from Evil', $cp, 1, 'Protection', 'V, S, M', '4', 'Touch', '3 rds/level', ['holy water', 'iron']),
            self::row('Sanctuary', $c, 1, 'Protection', 'V, S, M', '4', 'Touch', '2 rds + 1 rd/level', ['holy symbol']),
            self::row('Find Traps', $c, 2, 'Divination', 'V, S', '5', '30 yds', '3 turns'),
            self::row('Hold Person', $c, 2, 'Charm', 'V, S, F', '5', '120 yds', '2 rds/level', ['holy symbol']),
            self::row('Know Alignment', $c, 2, 'Divination', 'V, S', '1 rd', '10 yds', '1 turn'),
            self::row('Silence, 15\' Radius', $c, 2, 'Guardian', 'V, S', '5', '120 yds', '2 rds/level'),
            self::row('Slow Poison', $cp, 2, 'Healing', 'V, S, M', '1', 'Touch', '1 hr/level', ['holy symbol']),
            self::row('Spiritual Hammer', $c, 2, 'Combat', 'V, S, M', '5', '10 yds/level', '3 rds + 1 rd/level', ['warhammer']),
            self::row('Prayer', $c, 3, 'Combat', 'V, S, M', '6', '0', '1 rd/level', ['holy symbol', 'silver']),
            self::row('Remove Curse', $c, 3, 'Protection', 'V, S', '6', 'Touch', 'Permanent'),
            self::row('Speak with Dead', $c, 3, 'Divination', 'V, S, M', '1 turn', '1', 'Special', ['holy symbol']),
            self::row('Cure Serious Wounds', $cp, 4, 'Healing', 'V, S', '7', 'Touch', 'Permanent'),
            self::row('Neutralize Poison', array_merge($cp, $d), 4, 'Healing', 'V, S', '7', 'Touch', 'Permanent'),
            self::row('Protection from Evil, 10\' Radius', $c, 4, 'Protection', 'V, S, M', '7', 'Touch', '1 turn/level', ['holy water']),
            self::row('Cure Critical Wounds', $c, 5, 'Healing', 'V, S', '8', 'Touch', 'Permanent'),
            self::row('Flame Strike', $c, 5, 'Combat', 'V, S, M', '8', '60 yds', 'Instantaneous', ['holy symbol']),
            self::row('Raise Dead', $c, 5, 'Necromantic', 'V, S', '1 rd', '30 yds', 'Permanent'),
            self::row('True Seeing', $c, 5, 'Divination', 'V, S, M', '8', 'Touch', '1 rd/level', ['ointment']),
            self::row('Blade Barrier', $c, 6, 'Guardian', 'V, S', '9', '30 yds', '3 rds/level'),
            self::row('Heal', $c, 6, 'Healing', 'V, S', '1 rd', 'Touch', 'Permanent'),
            self::row('Word of Recall', $c, 6, 'Summoning', 'V', '1', '0', 'Special'),
            self::row('Holy Word', $c, 7, 'Combat', 'V', '1', '0', 'Special'),
            self::row('Restoration', $c, 7, 'Necromantic', 'V, S', '3 rds', 'Touch', 'Permanent'),
            self::row('Resurrection', $c, 7, 'Necromantic', 'V, S, M', '1 turn', 'Touch', 'Permanent', ['holy water', 'holy symbol']),

            self::row('Animal Friendship', $dr, 1, 'Animal', 'V, S, M', '1 hr', 'Touch', 'Permanent', ['food']),
            self::row('Entangle', $d, 1, 'Plant', 'V, S, M', '4', '80 yds', '1 turn', ['mistletoe']),
            self::row('Faerie Fire', $d, 1, 'Weather', 'V', '4', '80 yds', '4 rds/level'),
            self::row('Invisibility to Animals', $dr, 1, 'Animal', 'S, M', '4', 'Touch', '1 turn + 1 rd/level', ['holly']),
            self::row('Pass without Trace', $dr, 1, 'Plant', 'V, S, M', '1 rd', 'Touch', '1 turn/level', ['pine', 'mistletoe']),
            self::row('Shillelagh', $d, 1, 'Combat', 'V, S, M', '2', 'Touch', '1 rd/level', ['oak club', 'mistletoe']),
            self::row('Speak with Animals', $dr, 1, 'Animal', 'V, S', '5', '0', '2 rds/level'),
            self::row('Barkskin', $d, 2, 'Protection', 'V, S, M', '5', 'Touch', '4 rds + 1 rd/level', ['mistletoe']),
            self::row('Charm Person or Mammal', $d, 2, 'Animal', 'V, S', '5', '80 yds', 'Special'),
            self::row('Heat Metal', $d, 2, 'Elemental', 'V, S, M', '5', '40 yds', '7 rds', ['mistletoe']),
            self::row('Warp Wood', $d, 2, 'Plant', 'V, S', '5', '10 yds/level', 'Permanent'),
            self::row('Call Lightning', $d, 3, 'Weather', 'V, S', '1 turn', '360 yds', '1 turn/level'),
            self::row('Plant Growth', $d, 3, 'Plant', 'V, S, M', '1 rd', '160 yds', 'Permanent', ['mistletoe']),
            self::row('Stone Shape', $d, 3, 'Elemental', 'V, S, M', '1 rd', 'Touch', 'Permanent', ['clay']),
            self::row('Control Temperature, 10\' Radius', $d, 4, 'Weather', 'V, S, M', '7', '0', '4 turns + 1 turn/level', ['mistletoe']),
            self::row('Produce Fire', $d, 4, 'Elemental', 'V, S, M', '7', '40 yds', '1 rd', ['flint']),
            self::row('Transmute Rock to Mud', $d, 5, 'Elemental', 'V, S, M', '8', '160 yds', 'Special', ['clay', 'water']),
            self::row('Transport via Plants', $d, 6, 'Plant', 'V, S', '4', 'Touch', 'Special'),
            self::row('Weather Summoning', $d, 6, 'Weather', 'V, S, M', '1 turn', '0', 'Special', ['mistletoe']),
            self::row('Creeping Doom', $d, 7, 'Animal', 'V, S', '9', '0', '4 rds/level'),
            self::row('Fire Storm', $d, 7, 'Elemental', 'V, S', '9', '160 yds', '1 rd'),
        ];
    }
}
