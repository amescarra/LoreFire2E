<?php

namespace App\Support;

use App\Models\Character;
use App\Models\CharacterSpell;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Parse named material/focus items from a spell's own records and spend
 * matching inventory on cast. Does not invent PHB component lists.
 */
class SpellMaterialComponents
{
    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'v', 's', 'm', 'f', 'g',
        'verbal', 'somatic', 'material', 'materials',
        'focus', 'foci', 'component', 'components',
        'none', 'special', 'nil', 'n/a', 'na',
        'see below', 'varies', 'optional',
        'not consumed', 'not expended', 'reusable',
    ];

    /**
     * @return list<array{name: string, quantity: int, consumed: bool, focus: bool}>
     */
    public static function parse(?string $components, ?string $description = null): array
    {
        $reqs = [];
        $codes = self::componentCodes((string) $components);

        foreach (self::clausesFromComponents((string) $components, $codes) as $clause) {
            self::mergeClause($reqs, $clause, $codes);
        }
        foreach (self::clausesFromNotes((string) $description) as $clause) {
            self::mergeClause($reqs, $clause, $codes);
        }

        return array_values($reqs);
    }

    /**
     * Check inventory and, if complete, burn one Vancian copy and spend
     * expendable materials. Foci are required when named but never consumed.
     *
     * @return array{ok: bool, changed: bool, error: ?string}
     */
    public static function tryBurnOneCopy(Character $character, CharacterSpell $spell): array
    {
        $times = Adnd2e::effectiveTimesMemorized((int) $spell->times_memorized, (bool) $spell->is_prepared);
        if ($times < 1) {
            return ['ok' => true, 'changed' => false, 'error' => null];
        }

        $flags = Adnd2e::burnMemorizedInstance($times, (int) $spell->times_cast);
        if ((int) $flags['times_cast'] === (int) $spell->times_cast) {
            return ['ok' => true, 'changed' => false, 'error' => null];
        }

        $plan = self::inspect($character, $spell);
        if (! $plan['ok']) {
            return ['ok' => false, 'changed' => false, 'error' => $plan['error']];
        }

        DB::transaction(function () use ($character, $spell, $flags, $plan) {
            self::applySpends($character, $plan['spends']);
            $spell->update($flags);
            $spell->refresh();
        });

        return ['ok' => true, 'changed' => true, 'error' => null];
    }

    /**
     * @return array{ok: bool, error: ?string, spends: array<int, int>, missing: list<string>}
     */
    public static function inspect(Character $character, CharacterSpell $spell): array
    {
        $character->loadMissing('inventoryItems');
        $reqs = self::parse($spell->components, $spell->description);
        if ($reqs === []) {
            return ['ok' => true, 'error' => null, 'spends' => [], 'missing' => []];
        }

        $items = $character->inventoryItems;
        $remaining = [];
        foreach ($items as $item) {
            $remaining[(int) $item->id] = (int) $item->quantity;
        }

        $spends = [];
        $missing = [];
        $missingFoci = [];

        foreach ($reqs as $req) {
            $needed = max(1, (int) $req['quantity']);
            $match = self::matchInventory($items, $req['name'], $remaining, $needed, (bool) $req['focus']);
            if ($match === null) {
                if ($req['focus']) {
                    $missingFoci[] = $req['name'];
                } else {
                    $missing[] = $req['name'];
                }

                continue;
            }

            if (! $req['focus']) {
                $id = (int) $match->id;
                $remaining[$id] = ($remaining[$id] ?? 0) - $needed;
                $spends[$id] = ($spends[$id] ?? 0) + $needed;
            }
        }

        if ($missing === [] && $missingFoci === []) {
            return ['ok' => true, 'error' => null, 'spends' => $spends, 'missing' => []];
        }

        return [
            'ok' => false,
            'error' => self::errorMessage((string) $spell->name, $missing, $missingFoci),
            'spends' => [],
            'missing' => array_merge($missing, $missingFoci),
        ];
    }

    /**
     * @param  array<int, int>  $spends  item id => quantity to remove
     */
    public static function applySpends(Character $character, array $spends): void
    {
        if ($spends === []) {
            return;
        }

        $character->loadMissing('inventoryItems');
        foreach ($spends as $id => $qty) {
            $item = $character->inventoryItems->firstWhere('id', (int) $id);
            if (! $item) {
                $item = InventoryItem::query()
                    ->where('character_id', $character->id)
                    ->whereKey((int) $id)
                    ->first();
            }
            if (! $item) {
                continue;
            }

            $next = (int) $item->quantity - (int) $qty;
            if ($next <= 0) {
                $item->delete();
            } else {
                $item->update(['quantity' => $next]);
            }
        }

        $character->unsetRelation('inventoryItems');
        $character->load('inventoryItems');
    }

    /**
     * Case-insensitive name match, then contains either direction.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryItem>  $items
     * @param  array<int, int>  $remaining
     */
    public static function matchInventory(
        $items,
        string $name,
        array $remaining,
        int $needed = 1,
        bool $presenceOnly = false,
    ): ?InventoryItem {
        $terms = self::searchTerms($name);
        $best = null;
        $bestScore = 0;

        foreach ($items as $item) {
            $id = (int) $item->id;
            $have = $remaining[$id] ?? (int) $item->quantity;
            if ($presenceOnly) {
                if ($have < 1) {
                    continue;
                }
            } elseif ($have < $needed) {
                continue;
            }

            $score = self::nameScore((string) $item->name, $terms);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * @param  list<string>  $terms
     */
    public static function nameScore(string $itemName, array $terms): int
    {
        $hay = strtolower(trim($itemName));
        if ($hay === '') {
            return 0;
        }

        $best = 0;
        foreach ($terms as $term) {
            $needle = strtolower(trim($term));
            if ($needle === '') {
                continue;
            }
            if (strcasecmp($hay, $needle) === 0) {
                $best = max($best, 1000);

                continue;
            }
            if (strlen($needle) < 3) {
                continue;
            }
            if (str_contains($hay, $needle)) {
                $best = max($best, 400 - min(200, strlen($hay) - strlen($needle)));

                continue;
            }
            if (strlen($hay) >= 3 && str_contains($needle, $hay)) {
                $best = max($best, 200 - min(100, strlen($needle) - strlen($hay)));
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    public static function searchTerms(string $name): array
    {
        $base = self::normalizeName($name);
        $terms = [];
        if ($base !== '') {
            $terms[] = $base;
        }

        $stripped = preg_replace(
            '/^(?:(?:a|an|the)\s+)?(?:pinch|bit|handful|drop|dash|sprinkle|piece|powder|vial|flask)e?s?\s+of\s+/i',
            '',
            $base,
        );
        $stripped = trim((string) $stripped);
        if ($stripped !== '' && strcasecmp($stripped, $base) !== 0) {
            $terms[] = $stripped;
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param  array<string, array{name: string, quantity: int, consumed: bool, focus: bool}>  $reqs
     * @param  array{text: string, focus: bool}  $clause
     * @param  list<string>  $codes
     */
    protected static function mergeClause(array &$reqs, array $clause, array $codes): void
    {
        $hasM = in_array('M', $codes, true);
        $hasF = in_array('F', $codes, true) || in_array('G', $codes, true);

        foreach (self::splitItems($clause['text']) as $raw) {
            $parsed = self::parseItem($raw);
            if ($parsed === null) {
                continue;
            }

            $isFocus = $clause['focus']
                || (bool) preg_match('/\bfocus\b|\bnot (?:consumed|expended)\b/i', $raw)
                || (($hasF || $clause['focus']) && (bool) preg_match('/\bholy symbol\b/i', $parsed['name']));

            if (! $isFocus && $clause['focus']) {
                $isFocus = true;
            }
            if (! $isFocus && ! $hasM && $hasF) {
                $isFocus = true;
            }

            $key = strtolower($parsed['name']);
            if (isset($reqs[$key])) {
                $reqs[$key]['quantity'] = max($reqs[$key]['quantity'], $parsed['quantity']);
                $reqs[$key]['focus'] = $reqs[$key]['focus'] || $isFocus;
                $reqs[$key]['consumed'] = ! $reqs[$key]['focus'];

                continue;
            }

            $reqs[$key] = [
                'name' => $parsed['name'],
                'quantity' => $parsed['quantity'],
                'consumed' => ! $isFocus,
                'focus' => $isFocus,
            ];
        }
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{text: string, focus: bool}>
     */
    protected static function clausesFromComponents(string $text, array $codes): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $clauses = self::labeledClauses($text);
        $clauses = array_merge($clauses, self::parentheticalClauses($text));

        $remainder = self::stripLeadingCodes($text);
        $remainder = trim((string) preg_replace('/\([^)]*\)/', ' ', $remainder));
        $remainder = trim($remainder, " \t:-–—.");
        if ($remainder !== '' && ! self::isOnlyCodes($remainder)) {
            $hasM = in_array('M', $codes, true);
            $hasF = in_array('F', $codes, true) || in_array('G', $codes, true);
            $clauses[] = [
                'text' => $remainder,
                'focus' => ! $hasM && $hasF,
            ];
        }

        return $clauses;
    }

    /**
     * Notes only contribute labeled / component-style lines, never free prose.
     *
     * @return list<array{text: string, focus: bool}>
     */
    protected static function clausesFromNotes(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $clauses = self::labeledClauses($text);

        $noteCodes = self::componentCodes($text);
        if ($noteCodes !== []) {
            $clauses = array_merge($clauses, self::clausesFromComponents($text, $noteCodes));
        } else {
            $clauses = array_merge($clauses, self::parentheticalClauses($text, true));
        }

        return $clauses;
    }

    /**
     * @return list<array{text: string, focus: bool}>
     */
    protected static function labeledClauses(string $text): array
    {
        $clauses = [];
        if (! preg_match_all(
            '/\b(material(?:s| components?)?|focus(?:es)?)\s*:\s*(.+?)(?:\.(?:\s|$)|$)/i',
            $text,
            $matches,
            PREG_SET_ORDER,
        )) {
            return $clauses;
        }

        foreach ($matches as $match) {
            $label = strtolower($match[1]);
            $clauses[] = [
                'text' => trim($match[2]),
                'focus' => str_starts_with($label, 'focus'),
            ];
        }

        return $clauses;
    }

    /**
     * @return list<array{text: string, focus: bool}>
     */
    protected static function parentheticalClauses(string $text, bool $requireCue = false): array
    {
        $clauses = [];
        if (! preg_match_all('/(.{0,48})\(([^)]+)\)/', $text, $matches, PREG_SET_ORDER)) {
            return $clauses;
        }

        foreach ($matches as $match) {
            $before = strtolower($match[1]);
            $inner = trim($match[2]);
            if ($inner === '' || preg_match('/^(?:not (?:consumed|expended)|reusable|focus)$/i', $inner)) {
                continue;
            }
            if ($requireCue && ! preg_match('/\b(?:material|focus|[mfgs])\b/i', $before.$inner)) {
                continue;
            }

            $letter = self::lastComponentLetter($before);
            $beforeHasM = (bool) preg_match('/\bM\b/i', $before);
            $innerFocus = (bool) preg_match('/\bfocus\b|\bnot (?:consumed|expended)\b/i', $inner);
            $letterIsFocus = ($letter === 'F' || $letter === 'G') && ! $beforeHasM;
            $clauses[] = [
                'text' => $inner,
                'focus' => $innerFocus || $letterIsFocus,
            ];
        }

        return $clauses;
    }

    /**
     * Leading V/S/M/F/G tokens only — never the first letter of Material/Focus.
     *
     * @return list<string>
     */
    protected static function componentCodes(string $text): array
    {
        if (! preg_match('/^\s*([VSMFG](?:\s*[,\/]\s*[VSMFG])*)\b(?![a-z])/i', $text, $match)) {
            return [];
        }
        preg_match_all('/[VSMFG]/i', $match[1], $letters);

        return array_values(array_unique(array_map('strtoupper', $letters[0] ?? [])));
    }

    protected static function stripLeadingCodes(string $text): string
    {
        $stripped = preg_replace('/^\s*[VSMFG](?:\s*[,\/]\s*[VSMFG])*\b(?![a-z])/i', '', $text);

        return trim((string) $stripped);
    }

    protected static function isOnlyCodes(string $text): bool
    {
        $compact = strtolower(trim($text));
        $compact = (string) preg_replace('/[\s,\/]+/', '', $compact);

        return $compact !== '' && (bool) preg_match('/^[vsmfg]+$/', $compact);
    }

    protected static function lastComponentLetter(string $before): ?string
    {
        if (! preg_match_all('/\b([VSMFG])\b/i', $before, $matches)) {
            return null;
        }
        $letters = $matches[1] ?? [];

        return $letters === [] ? null : strtoupper((string) end($letters));
    }

    /**
     * @return list<string>
     */
    protected static function splitItems(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;)\s*/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (
                preg_match('/^(.+?)\s+and\s+(.+)$/i', $part, $match)
                && ! preg_match('/\b(worth|value|at least)\b/i', $part)
                && str_word_count($match[1]) <= 4
                && str_word_count($match[2]) <= 4
            ) {
                $out[] = trim($match[1]);
                $out[] = trim($match[2]);
            } else {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, quantity: int}|null
     */
    protected static function parseItem(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^\s*focus(?:es)?\s*[:\-–—]\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*\((?:not (?:consumed|expended)|reusable|focus)\)\s*$/i', '', $text) ?? $text;
        $text = preg_replace('/\b(?:worth|valued at|of at least|with a value of)\b.*$/i', '', $text) ?? $text;
        $text = trim($text, " \t.:-–—");

        $quantity = 1;
        if (preg_match('/^(\d+)\s*[x×]\s+(.+)$/i', $text, $match)
            || preg_match('/^(\d+)\s+(?!gp|sp|cp|pp|gold|silver)(.+)$/i', $text, $match)
        ) {
            $quantity = max(1, (int) $match[1]);
            $text = trim($match[2]);
        }

        $name = self::normalizeName($text);
        if ($name === '' || in_array($name, self::STOPWORDS, true)) {
            return null;
        }

        return ['name' => $name, 'quantity' => $quantity];
    }

    protected static function normalizeName(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/^(?:a|an|the)\s+/i', '', $text) ?? $text;

        return trim($text, " \t.:-–—");
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $missingFoci
     */
    public static function errorMessage(string $spellName, array $missing, array $missingFoci): string
    {
        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing '.self::englishList($missing);
        }
        if ($missingFoci !== []) {
            $label = count($missingFoci) === 1 ? 'missing focus' : 'missing foci';
            $parts[] = $label.' ('.self::englishList($missingFoci).')';
        }

        $name = trim($spellName) !== '' ? $spellName : 'spell';

        return 'Cannot cast '.$name.': '.implode('; ', $parts).'.';
    }

    /**
     * @param  list<string>  $items
     */
    protected static function englishList(array $items): string
    {
        $items = array_values(array_filter($items, fn ($item) => trim((string) $item) !== ''));
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0].' and '.$items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items).', and '.$last;
    }
}
