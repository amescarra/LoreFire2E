<?php

namespace App\Support;

/**
 * Minimal creature labels for Oracle math only: AC / HD / THAC0 / dmg.
 * No ecology or lore prose. Prefer campaign NPC rows when present.
 */
class Adnd2eCreatureStubs
{
    /**
     * @return array{name: string, ac: int, hd: string, thac0: int, dmg: string}|null
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
     * @return array{name: string, ac: int, hd: string, thac0: int, dmg: string}|null
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
     * @param  array{name: string, ac: int, hd: string, thac0: int, dmg: string}  $row
     */
    public static function formatRow(array $row): string
    {
        return $row['name'].' stub: AC '.$row['ac'].', HD '.$row['hd'].', THAC0 '.$row['thac0'].', dmg '.$row['dmg'].' (Adnd2eCreatureStubs)';
    }

    private static function norm(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;
    }

    /**
     * @return list<array{name: string, aliases: list<string>, ac: int, hd: string, thac0: int, dmg: string}>
     */
    private static function rows(): array
    {
        return [
            ['name' => 'Kobold', 'aliases' => [], 'ac' => 7, 'hd' => '1/2', 'thac0' => 20, 'dmg' => '1d4'],
            ['name' => 'Goblin', 'aliases' => [], 'ac' => 6, 'hd' => '1-1', 'thac0' => 20, 'dmg' => '1d6'],
            ['name' => 'Orc', 'aliases' => [], 'ac' => 6, 'hd' => '1', 'thac0' => 19, 'dmg' => '1d8'],
            ['name' => 'Hobgoblin', 'aliases' => [], 'ac' => 5, 'hd' => '1+1', 'thac0' => 19, 'dmg' => '1d8'],
            ['name' => 'Gnoll', 'aliases' => [], 'ac' => 5, 'hd' => '2', 'thac0' => 19, 'dmg' => '2d4'],
            ['name' => 'Skeleton', 'aliases' => [], 'ac' => 7, 'hd' => '1', 'thac0' => 19, 'dmg' => '1d6'],
            ['name' => 'Zombie', 'aliases' => [], 'ac' => 8, 'hd' => '2', 'thac0' => 19, 'dmg' => '1d8'],
            ['name' => 'Wolf', 'aliases' => [], 'ac' => 7, 'hd' => '2+2', 'thac0' => 19, 'dmg' => '2d4'],
            ['name' => 'Giant rat', 'aliases' => ['giant rats'], 'ac' => 7, 'hd' => '1/4', 'thac0' => 20, 'dmg' => '1d3'],
            ['name' => 'Ogre', 'aliases' => [], 'ac' => 5, 'hd' => '4+1', 'thac0' => 17, 'dmg' => '1d10'],
            ['name' => 'Bugbear', 'aliases' => [], 'ac' => 5, 'hd' => '3+1', 'thac0' => 17, 'dmg' => '2d4'],
            ['name' => 'Owlbear', 'aliases' => [], 'ac' => 5, 'hd' => '5+2', 'thac0' => 15, 'dmg' => '1d6/1d6/2d6'],
            ['name' => 'Troll', 'aliases' => [], 'ac' => 4, 'hd' => '6+6', 'thac0' => 13, 'dmg' => '1d4+1/1d4+1/1d8+4'],
        ];
    }
}
