<?php

namespace App\Support;

use App\Models\Character;
use App\Models\GameSession;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Shared apply path for live transcript extract, end-of-session extract, and Oracle.
 * Vancian burns/restores use the same helpers as the sheet toggles.
 */
class SessionSheetUpdates
{
    /**
     * @return Collection<int, Character>
     */
    public static function participants(GameSession $session): Collection
    {
        $ids = $session->participant_character_ids ?? [];
        $query = Character::query()
            ->where('campaign_id', $session->campaign_id)
            ->with(['spells', 'inventoryItems']);

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /**
     * Extract + apply new text. Returns true when at least one sheet field changed.
     */
    public static function applyFromText(GameSession $session, string $text, ?string $sourceId = null): bool
    {
        $characters = self::participants($session);
        $extracted = IncrementalSheetExtractor::extract($text, $characters, $sourceId);

        return self::apply($session, $extracted['actions'], $extracted['line_hashes']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<int, string>  $lineHashes
     */
    public static function apply(GameSession $session, array $actions, array $lineHashes = []): bool
    {
        $session->refresh();
        $seen = collect($session->sheet_update_hashes ?? [])->filter()->values()->all();
        $applied = false;
        $newHashes = $seen;
        $appliedFingerprints = array_fill_keys($seen, true);

        foreach ($lineHashes as $hash) {
            if ($hash !== '' && ! in_array($hash, $newHashes, true)) {
                $newHashes[] = $hash;
            }
        }

        $characters = self::participants($session)->keyBy('id');

        foreach ($actions as $action) {
            $fingerprint = (string) ($action['fingerprint'] ?? '');
            if ($fingerprint !== '' && isset($appliedFingerprints[$fingerprint])) {
                continue;
            }

            $ok = self::applyAction($action, $characters);
            if ($ok) {
                $applied = true;
                if ($fingerprint !== '') {
                    $appliedFingerprints[$fingerprint] = true;
                    if (! in_array($fingerprint, $newHashes, true)) {
                        $newHashes[] = $fingerprint;
                    }
                }
            }
        }

        $session->update(['sheet_update_hashes' => array_values($newHashes)]);

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  Collection<int, Character>  $characters
     */
    protected static function applyAction(array $action, Collection $characters): bool
    {
        $type = (string) ($action['type'] ?? '');

        if ($type === 'rest') {
            $id = $action['character_id'] ?? null;
            $targets = $id ? $characters->where('id', $id) : $characters;
            $changed = false;
            foreach ($targets as $character) {
                $changed = self::restCharacter($character) || $changed;
            }

            return $changed;
        }

        $character = $characters->get($action['character_id'] ?? null);
        if (! $character) {
            return false;
        }

        return match ($type) {
            'spell_cast' => self::burnSpell($character, $action),
            'spell_restore' => self::restoreSpell($character, $action),
            'spell_memorize' => self::memorizeSpell($character, $action),
            'spell_learn' => self::learnSpell($character, $action),
            'hp_set' => self::setHp($character, (int) $action['value']),
            'hp_delta' => self::deltaHp($character, (int) $action['delta']),
            'gold_set' => self::setGold($character, (int) $action['value']),
            'gold_delta' => self::deltaGold($character, (int) $action['delta']),
            'xp_set' => self::setXp($character, (int) $action['value']),
            'xp_delta' => self::deltaXp($character, (int) $action['delta']),
            'inventory_gain' => self::gainItem($character, (string) ($action['item_name'] ?? '')),
            'inventory_lose' => self::loseItem($character, (string) ($action['item_name'] ?? '')),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected static function burnSpell(Character $character, array $action): bool
    {
        $spell = self::findSpell($character, $action);
        if (! $spell) {
            Log::info('SessionSheetUpdates: ignoring unknown spell', [
                'character' => $character->name,
                'spell' => $action['spell_name'] ?? null,
            ]);

            return false;
        }

        $times = Adnd2e::effectiveTimesMemorized((int) $spell->times_memorized, (bool) $spell->is_prepared);
        if ($times < 1) {
            return false;
        }

        $copies = max(1, (int) ($action['copies'] ?? 1));
        $changed = false;
        for ($i = 0; $i < $copies; $i++) {
            $result = SpellMaterialComponents::tryBurnOneCopy($character, $spell);
            if (! $result['ok']) {
                Log::info('SessionSheetUpdates: blocked spell cast — missing components', [
                    'character' => $character->name,
                    'spell' => $spell->name,
                    'error' => $result['error'],
                ]);
                break;
            }
            if (! $result['changed']) {
                break;
            }
            $changed = true;
        }

        if ($changed) {
            self::syncMemorizationUsed($character);
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected static function restoreSpell(Character $character, array $action): bool
    {
        $spell = self::findSpell($character, $action);
        if (! $spell) {
            return false;
        }

        $times = Adnd2e::effectiveTimesMemorized((int) $spell->times_memorized, (bool) $spell->is_prepared);
        if ($times < 1) {
            return false;
        }

        $copies = max(1, (int) ($action['copies'] ?? 1));
        $changed = false;
        for ($i = 0; $i < $copies; $i++) {
            $flags = Adnd2e::restoreMemorizedInstance($times, (int) $spell->times_cast);
            if ((int) $flags['times_cast'] === (int) $spell->times_cast) {
                break;
            }
            $spell->update($flags);
            $spell->refresh();
            $changed = true;
        }

        if ($changed) {
            self::syncMemorizationUsed($character);
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected static function memorizeSpell(Character $character, array $action): bool
    {
        $spell = self::findSpell($character, $action);
        if (! $spell) {
            return false;
        }

        $current = Adnd2e::effectiveTimesMemorized((int) $spell->times_memorized, (bool) $spell->is_prepared);
        $add = max(1, (int) ($action['copies'] ?? 1));
        $next = min(Adnd2e::MAX_TIMES_MEMORIZED, $current + $add);
        if ($next === $current) {
            return false;
        }

        $spell->update(Adnd2e::spellVancianFlags($next, (int) $spell->times_cast));
        self::syncMemorizationUsed($character);

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected static function learnSpell(Character $character, array $action): bool
    {
        $name = trim((string) ($action['spell_name'] ?? ''));
        $level = (int) ($action['level'] ?? 0);
        if ($name === '' || $level < 1 || $level > 9) {
            return false;
        }

        $existing = self::findSpell($character, $action);
        if ($existing) {
            return false;
        }

        $character->spells()->create(SpellMaterialComponents::enrichPayloadFromCatalog([
            'name' => $name,
            'level' => $level,
            'times_memorized' => 0,
            'times_cast' => 0,
        ], $character));

        return true;
    }

    protected static function restCharacter(Character $character): bool
    {
        $result = Adnd2e::overnightRest(
            (int) $character->current_hp,
            (int) $character->max_hp,
            (string) $character->class,
            (int) $character->level,
            $character->class_features ?? [],
        );
        $character->update($result);
        $character->spells()->update(Adnd2e::rememorizeSpellFields());
        self::syncMemorizationUsed($character->fresh(['spells']));

        return true;
    }

    protected static function setHp(Character $character, int $value): bool
    {
        $next = Adnd2e::clampCurrentHp($value, (int) $character->max_hp);
        if ($next === (int) $character->current_hp) {
            return false;
        }
        $character->update(['current_hp' => $next]);

        return true;
    }

    protected static function deltaHp(Character $character, int $delta): bool
    {
        if ($delta === 0) {
            return false;
        }
        $next = Adnd2e::clampCurrentHp(
            (int) $character->current_hp + $delta,
            (int) $character->max_hp,
        );
        if ($next === (int) $character->current_hp) {
            return false;
        }
        $character->update(['current_hp' => $next]);

        return true;
    }

    protected static function setGold(Character $character, int $value): bool
    {
        $next = max(0, $value);
        if ($next === (int) $character->gold) {
            return false;
        }
        $character->update(['gold' => $next]);

        return true;
    }

    protected static function deltaGold(Character $character, int $delta): bool
    {
        if ($delta === 0) {
            return false;
        }
        $next = max(0, (int) $character->gold + $delta);
        if ($next === (int) $character->gold) {
            return false;
        }
        $character->update(['gold' => $next]);

        return true;
    }

    protected static function setXp(Character $character, int $value): bool
    {
        return self::applyExperiencePoints($character, max(0, $value));
    }

    protected static function deltaXp(Character $character, int $delta): bool
    {
        if ($delta === 0) {
            return false;
        }

        return self::applyExperiencePoints($character, max(0, (int) $character->experience_points + $delta));
    }

    /**
     * Write the sheet total. Single/dual also sync the current class_levels xp
     * entry. Multi-class leaves per-class xp alone (no invented split).
     */
    protected static function applyExperiencePoints(Character $character, int $next): bool
    {
        $next = max(0, $next);
        $path = (string) ($character->class_path ?? 'single');
        $entries = $character->classEntries();

        if ($path !== 'multi' && $entries !== []) {
            $index = $path === 'dual' ? array_key_last($entries) : array_key_first($entries);
            $entries[$index]['xp'] = $next;
        }

        $payload = [
            'experience_points' => $next,
            'class_levels' => $entries,
        ];
        if ($next === (int) $character->experience_points && $entries === ($character->class_levels ?? [])) {
            return false;
        }
        $character->update($payload);

        return true;
    }

    protected static function gainItem(Character $character, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $existing = $character->inventoryItems
            ->first(fn (InventoryItem $item) => strcasecmp((string) $item->name, $name) === 0);

        if ($existing) {
            $existing->update(['quantity' => (int) $existing->quantity + 1]);

            return true;
        }

        $character->inventoryItems()->create([
            'name' => $name,
            'quantity' => 1,
        ]);

        return true;
    }

    protected static function loseItem(Character $character, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $existing = $character->inventoryItems
            ->first(fn (InventoryItem $item) => strcasecmp((string) $item->name, $name) === 0);

        if (! $existing) {
            return false;
        }

        $qty = (int) $existing->quantity;
        if ($qty <= 1) {
            $existing->delete();
        } else {
            $existing->update(['quantity' => $qty - 1]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected static function findSpell(Character $character, array $action): mixed
    {
        $character->loadMissing('spells');
        $id = $action['spell_id'] ?? null;
        if ($id) {
            $byId = $character->spells->firstWhere('id', $id);
            if ($byId) {
                return $byId;
            }
        }

        $name = trim((string) ($action['spell_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $level = $action['level'] ?? null;
        $matches = $character->spells->filter(
            fn ($spell) => strcasecmp((string) $spell->name, $name) === 0
        );
        if ($level !== null && $matches->count() > 1) {
            $atLevel = $matches->first(fn ($spell) => (int) $spell->level === (int) $level);
            if ($atLevel) {
                return $atLevel;
            }
        }

        return $matches->first();
    }

    public static function syncMemorizationUsed(Character $character): void
    {
        $slots = is_array($character->memorization) ? $character->memorization : [];
        $character->load('spells');
        $used = [];

        foreach ($character->spells as $spell) {
            $level = (string) $spell->level;
            $used[$level] = ($used[$level] ?? 0) + (int) $spell->times_cast;
        }

        if ($slots !== []) {
            foreach ($slots as $level => $max) {
                $key = (string) $level;
                $cap = (int) $max;
                $used[$key] = min($cap > 0 ? $cap : ($used[$key] ?? 0), $used[$key] ?? 0);
            }
        }

        $character->update(['memorization_used' => $used === [] ? null : $used]);
    }

    /**
     * Map end-of-session LLM character_updates onto the same action list.
     * Named spell copies take precedence over a raw memorization_used blob.
     *
     * @param  array<int, array<string, mixed>>  $updates
     * @param  Collection<int, Character>  $characters
     * @return array<int, array<string, mixed>>
     */
    public static function actionsFromExtractPayload(array $updates, Collection $characters, string $batchHash): array
    {
        $actions = [];

        foreach ($updates as $update) {
            $name = trim((string) ($update['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $character = $characters->first(
                fn (Character $c) => strcasecmp((string) $c->name, $name) === 0
            );
            if (! $character) {
                continue;
            }

            $base = $character->id.':'.$batchHash.':'.IncrementalSheetExtractor::lineHash($name);

            if (array_key_exists('current_hp', $update) && $update['current_hp'] !== null) {
                $actions[] = [
                    'type' => 'hp_set',
                    'character_id' => $character->id,
                    'value' => (int) $update['current_hp'],
                    'fingerprint' => 'hp_set:'.$base.':'.$update['current_hp'],
                ];
            }
            if (array_key_exists('gold', $update) && $update['gold'] !== null) {
                $actions[] = [
                    'type' => 'gold_set',
                    'character_id' => $character->id,
                    'value' => (int) $update['gold'],
                    'fingerprint' => 'gold_set:'.$base.':'.$update['gold'],
                ];
            }
            if (array_key_exists('experience_points', $update) && $update['experience_points'] !== null) {
                $actions[] = [
                    'type' => 'xp_set',
                    'character_id' => $character->id,
                    'value' => (int) $update['experience_points'],
                    'fingerprint' => 'xp_set:'.$base.':'.$update['experience_points'],
                ];
            }
            if (! empty($update['rest'])) {
                $actions[] = [
                    'type' => 'rest',
                    'character_id' => $character->id,
                    'fingerprint' => 'rest:'.$base,
                ];
            }

            foreach ($update['spells_cast'] ?? [] as $cast) {
                $spellName = trim((string) ($cast['name'] ?? ''));
                if ($spellName === '') {
                    continue;
                }
                $actions[] = [
                    'type' => 'spell_cast',
                    'character_id' => $character->id,
                    'spell_name' => $spellName,
                    'level' => $cast['level'] ?? null,
                    'copies' => max(1, (int) ($cast['copies'] ?? 1)),
                    'fingerprint' => 'spell_cast:'.$character->id.':'.IncrementalSheetExtractor::lineHash($spellName).':'.$batchHash,
                ];
            }

            foreach ($update['spells_memorized'] ?? [] as $mem) {
                $spellName = trim((string) ($mem['name'] ?? ''));
                if ($spellName === '') {
                    continue;
                }
                $actions[] = [
                    'type' => 'spell_memorize',
                    'character_id' => $character->id,
                    'spell_name' => $spellName,
                    'level' => $mem['level'] ?? null,
                    'copies' => max(1, (int) ($mem['copies'] ?? 1)),
                    'fingerprint' => 'spell_memorize:'.$character->id.':'.IncrementalSheetExtractor::lineHash($spellName).':'.$batchHash,
                ];
            }

            foreach ($update['inventory_gained'] ?? [] as $item) {
                $itemName = is_array($item) ? trim((string) ($item['name'] ?? '')) : trim((string) $item);
                if ($itemName === '') {
                    continue;
                }
                $actions[] = [
                    'type' => 'inventory_gain',
                    'character_id' => $character->id,
                    'item_name' => $itemName,
                    'fingerprint' => 'inv_gain:'.$character->id.':'.IncrementalSheetExtractor::lineHash($itemName).':'.$batchHash,
                ];
            }

            foreach ($update['inventory_lost'] ?? [] as $item) {
                $itemName = is_array($item) ? trim((string) ($item['name'] ?? '')) : trim((string) $item);
                if ($itemName === '') {
                    continue;
                }
                $actions[] = [
                    'type' => 'inventory_lose',
                    'character_id' => $character->id,
                    'item_name' => $itemName,
                    'fingerprint' => 'inv_lose:'.$character->id.':'.IncrementalSheetExtractor::lineHash($itemName).':'.$batchHash,
                ];
            }
        }

        return $actions;
    }
}
