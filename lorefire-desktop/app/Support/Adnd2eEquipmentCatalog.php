<?php

namespace App\Support;

/**
 * Structured 2E gear headers only: name, type, AC or damage dice,
 * speed, weight, magical bonus. Artifact stubs are name + tags.
 * No flavor paragraphs.
 */
class Adnd2eEquipmentCatalog
{
    /**
     * @return array{name: string, type: string, ac: ?int, sm: ?string, l: ?string, speed: ?int, weight: float, bonus: int}|null
     */
    public static function find(string $name): ?array
    {
        $want = self::norm($name);
        if ($want === '') {
            return null;
        }
        foreach (self::rows() as $row) {
            if (self::norm($row['name']) === $want) {
                return $row;
            }
            foreach ($row['aliases'] as $alias) {
                if (self::norm($alias) === $want) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Longest catalog name or alias mentioned in free text.
     *
     * @return array{name: string, type: string, ac: ?int, sm: ?string, l: ?string, speed: ?int, weight: float, bonus: int}|null
     */
    public static function searchInText(string $text): ?array
    {
        $best = null;
        $bestLen = 0;
        foreach (self::rows() as $row) {
            foreach (array_merge([$row['name']], $row['aliases']) as $needle) {
                $len = strlen($needle);
                if ($len <= $bestLen) {
                    continue;
                }
                if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $text)) {
                    $best = $row;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    /**
     * @return array{name: string, tags: list<string>, bonus: ?int}|null
     */
    public static function findArtifact(string $name): ?array
    {
        $want = self::norm($name);
        if ($want === '') {
            return null;
        }
        foreach (self::artifactRows() as $row) {
            if (self::norm($row['name']) === $want) {
                return $row;
            }
            foreach ($row['aliases'] as $alias) {
                if (self::norm($alias) === $want) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @return array{name: string, tags: list<string>, bonus: ?int}|null
     */
    public static function searchArtifactInText(string $text): ?array
    {
        $best = null;
        $bestLen = 0;
        foreach (self::artifactRows() as $row) {
            foreach (array_merge([$row['name']], $row['aliases']) as $needle) {
                $len = strlen($needle);
                if ($len <= $bestLen) {
                    continue;
                }
                if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $text)) {
                    $best = $row;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array{name: string, type: string, ac: ?int, sm: ?string, l: ?string, speed: ?int, weight: float, bonus: int}  $row
     */
    public static function formatRow(array $row): string
    {
        $line = $row['name'].': '.$row['type'];
        if ($row['type'] === 'armor' || $row['type'] === 'shield') {
            $line .= ' AC '.$row['ac'];
        }
        if ($row['type'] === 'weapon') {
            $line .= ' SM '.$row['sm'].' / L '.$row['l'].' / speed '.$row['speed'];
        }
        $line .= '; weight '.$row['weight'];
        if ($row['bonus'] !== 0) {
            $line .= '; bonus '.Adnd2e::formatSigned($row['bonus']);
        }

        return $line.' (Adnd2eEquipmentCatalog)';
    }

    /**
     * @param  array{name: string, tags: list<string>, bonus: ?int}  $row
     */
    public static function formatArtifact(array $row): string
    {
        $line = $row['name'].': '.implode(', ', $row['tags']);
        if ($row['bonus'] !== null) {
            $line .= '; bonus '.Adnd2e::formatSigned($row['bonus']);
        }

        return $line.' (Adnd2eEquipmentCatalog::artifact)';
    }

    private static function norm(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/^(the|a|an)\s+/', '', $name) ?? $name;

        return preg_replace('/[^a-z0-9+]+/', '', $name) ?? $name;
    }

    /**
     * @param  list<string>  $aliases
     * @return array{name: string, aliases: list<string>, type: string, ac: ?int, sm: ?string, l: ?string, speed: ?int, weight: float, bonus: int}
     */
    private static function item(
        string $name,
        string $type,
        float $weight,
        int $bonus = 0,
        ?int $ac = null,
        ?string $sm = null,
        ?string $l = null,
        ?int $speed = null,
        array $aliases = [],
    ): array {
        return [
            'name' => $name,
            'aliases' => $aliases,
            'type' => $type,
            'ac' => $ac,
            'sm' => $sm,
            'l' => $l,
            'speed' => $speed,
            'weight' => $weight,
            'bonus' => $bonus,
        ];
    }

    /**
     * @return list<array{name: string, aliases: list<string>, type: string, ac: ?int, sm: ?string, l: ?string, speed: ?int, weight: float, bonus: int}>
     */
    private static function rows(): array
    {
        $armorWeights = [
            'None' => 0.0,
            'Shield only' => 10.0,
            'Padded' => 10.0,
            'Leather' => 15.0,
            'Studded leather' => 25.0,
            'Ring mail' => 30.0,
            'Scale mail' => 40.0,
            'Hide' => 30.0,
            'Brigandine' => 35.0,
            'Chain mail' => 40.0,
            'Splint mail' => 40.0,
            'Banded mail' => 35.0,
            'Bronze plate' => 45.0,
            'Plate mail' => 50.0,
            'Field plate' => 60.0,
            'Full plate' => 70.0,
        ];

        $weaponWeights = [
            'Dagger' => 1.0,
            'Dart' => 0.5,
            'Short sword' => 3.0,
            'Hand axe' => 5.0,
            'Warhammer' => 6.0,
            'Javelin' => 2.0,
            'Quarterstaff' => 4.0,
            'Club' => 3.0,
            'Long sword' => 4.0,
            'Spear' => 5.0,
            'Mace' => 8.0,
            'Sling' => 0.5,
            'Bastard sword' => 10.0,
            'Flail' => 15.0,
            'Morning star' => 12.0,
            'Battle axe' => 7.0,
            'Short bow' => 2.0,
            'Crossbow, light' => 7.0,
            'Long bow' => 3.0,
            'Lance' => 15.0,
            'Halberd' => 15.0,
            'Two-handed sword' => 15.0,
            'Crossbow, heavy' => 14.0,
        ];

        $rows = [];
        foreach (Adnd2e::armorCatalog() as $row) {
            $type = $row['name'] === 'Shield only' ? 'shield' : 'armor';
            $rows[] = self::item(
                $row['name'],
                $type,
                $armorWeights[$row['name']] ?? 0.0,
                0,
                $row['ac'],
            );
        }
        foreach (Adnd2e::weaponCatalog() as $row) {
            $rows[] = self::item(
                $row['name'],
                'weapon',
                $weaponWeights[$row['name']] ?? 0.0,
                0,
                null,
                $row['sm'],
                $row['l'],
                $row['speed'],
            );
        }

        $magic = [
            self::item('Leather +1', 'armor', 15.0, 1, 7, aliases: ['leather armor +1']),
            self::item('Chain mail +1', 'armor', 40.0, 1, 4, aliases: ['chain +1']),
            self::item('Chain mail +2', 'armor', 40.0, 2, 3, aliases: ['chain +2']),
            self::item('Plate mail +1', 'armor', 50.0, 1, 2, aliases: ['plate +1', 'plate armor +1']),
            self::item('Plate mail +2', 'armor', 50.0, 2, 1, aliases: ['plate +2']),
            self::item('Plate mail +3', 'armor', 50.0, 3, 0, aliases: ['plate +3']),
            self::item('Full plate +1', 'armor', 70.0, 1, 0, aliases: ['full plate armor +1']),
            self::item('Shield +1', 'shield', 10.0, 1, 8, aliases: ['magic shield +1']),
            self::item('Dagger +1', 'weapon', 1.0, 1, null, '1d4', '1d3', 2),
            self::item('Short sword +1', 'weapon', 3.0, 1, null, '1d6', '1d8', 3),
            self::item('Long sword +1', 'weapon', 4.0, 1, null, '1d8', '1d12', 5, ['longsword +1']),
            self::item('Long sword +2', 'weapon', 4.0, 2, null, '1d8', '1d12', 5, ['longsword +2']),
            self::item('Long sword +3', 'weapon', 4.0, 3, null, '1d8', '1d12', 5, ['longsword +3']),
            self::item('Battle axe +2', 'weapon', 7.0, 2, null, '1d8', '1d8', 7),
            self::item('Warhammer +1', 'weapon', 6.0, 1, null, '1d4+1', '1d4', 4),
            self::item('Long bow +1', 'weapon', 3.0, 1, null, '1d6', '1d6', 8),
        ];

        return array_merge($rows, $magic);
    }

    /**
     * @return list<array{name: string, aliases: list<string>, tags: list<string>, bonus: ?int}>
     */
    private static function artifactRows(): array
    {
        return [
            [
                'name' => 'Hand of Vecna',
                'aliases' => [],
                'tags' => ['undead-hand', 'caster +2'],
                'bonus' => 2,
            ],
            [
                'name' => 'Eye of Vecna',
                'aliases' => [],
                'tags' => ['undead-eye', 'divination +2'],
                'bonus' => 2,
            ],
            [
                'name' => 'Sword of Kas',
                'aliases' => [],
                'tags' => ['weapon', '+6 hit/dmg'],
                'bonus' => 6,
            ],
            [
                'name' => 'Axe of the Dwarvish Lords',
                'aliases' => ['axe of the dwarvish lords'],
                'tags' => ['weapon', '+3 hit/dmg', 'thrown'],
                'bonus' => 3,
            ],
            [
                'name' => 'Wand of Orcus',
                'aliases' => [],
                'tags' => ['wand', 'undead'],
                'bonus' => null,
            ],
            [
                'name' => 'Rod of Seven Parts',
                'aliases' => [],
                'tags' => ['rod', 'assemble'],
                'bonus' => null,
            ],
            [
                'name' => 'Invulnerable Coat of Arnd',
                'aliases' => [],
                'tags' => ['armor', 'AC 0'],
                'bonus' => 5,
            ],
            [
                'name' => 'Hammer of Thunderbolts',
                'aliases' => [],
                'tags' => ['weapon', '+3 hit/dmg', 'giant'],
                'bonus' => 3,
            ],
            [
                'name' => 'Sphere of Annihilation',
                'aliases' => [],
                'tags' => ['annihilate'],
                'bonus' => null,
            ],
            [
                'name' => 'Book of Infinite Spells',
                'aliases' => [],
                'tags' => ['wizard-book'],
                'bonus' => null,
            ],
        ];
    }
}
