<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Character;
use App\Support\Adnd2e;
use Illuminate\Validation\ValidationException;

trait ValidatesAdnd2eCharacter
{
    /**
     * @return array<string, mixed>
     */
    protected function characterStoreRules(): array
    {
        return [
            'campaign_id' => 'nullable|exists:campaigns,id',
            'name' => 'required|string|max:255',
            'player_name' => 'nullable|string|max:255',
            'race' => 'required|string|max:255',
            'subrace' => 'nullable|string|max:255',
            'class' => 'required|string|max:255',
            'subclass' => 'nullable|string|max:255',
            'class_path' => 'nullable|in:single,multi,dual',
            'class_levels' => 'nullable|array',
            'class_levels.*.class' => 'required_with:class_levels|string|max:50',
            'class_levels.*.level' => 'required_with:class_levels|integer|min:1|max:20',
            'class_levels.*.xp' => 'nullable|integer|min:0',
            'experience_points' => 'nullable|integer|min:0',
            'level' => 'required|integer|min:1|max:20',
            'background' => 'nullable|string|max:255',
            'alignment' => 'nullable|string|max:50',
            'strength' => 'integer|min:1|max:25',
            'exceptional_strength' => 'nullable|string|max:3',
            'dexterity' => 'integer|min:1|max:25',
            'constitution' => 'integer|min:1|max:25',
            'intelligence' => 'integer|min:1|max:25',
            'wisdom' => 'integer|min:1|max:25',
            'charisma' => 'integer|min:1|max:25',
            'max_hp' => 'integer|min:0',
            'current_hp' => 'integer|min:'.Adnd2e::DEATH_THRESHOLD,
            'armor_class' => 'integer|min:-20|max:15',
            'speed' => 'integer|min:0',
            'portrait' => 'nullable|file|image|max:10240',
            'psp_current' => 'nullable|integer|min:0|max:9999',
            'psp_max' => 'nullable|integer|min:0|max:9999',
            'psionic_powers' => 'nullable|array',
            'psionic_powers.*' => 'nullable|string|max:120',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function characterUpdateRules(): array
    {
        return array_merge($this->characterStoreRules(), [
            'experience_points' => 'integer|min:0',
            'thac0' => 'integer|min:-10|max:25',
            'hit_die' => 'nullable|string|max:20',
            'saving_throws' => 'nullable|array',
            'weapon_proficiencies' => 'nullable|array',
            'nonweapon_proficiencies' => 'nullable|array',
            'priest_spheres' => 'nullable|array',
            'spellcasting_ability' => 'nullable|string|max:50',
            'mannerisms' => 'nullable|string',
            'motivations' => 'nullable|string',
            'ties' => 'nullable|string',
            'weaknesses' => 'nullable|string',
            'backstory' => 'nullable|string',
            'appearance_description' => 'nullable|string',
            'copper' => 'integer|min:0',
            'silver' => 'integer|min:0',
            'electrum' => 'integer|min:0',
            'gold' => 'integer|min:0',
            'platinum' => 'integer|min:0',
            'class_features' => 'nullable|array',
            'memorization' => 'nullable|array',
            'portrait_style' => 'nullable|in:lifelike,renaissance,comic',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyEditionDefaults(array $data, ?Character $existing = null): array
    {
        $data = $this->finalizeClassEntries($data);
        $this->assertHouseDualSwitch($data, $existing);

        $defaults = Adnd2e::defaultsForEntries(
            $data['class_levels'],
            (string) ($data['race'] ?? 'Human'),
            (int) ($data['wisdom'] ?? 10),
            $data['subclass'] ?? null,
            (string) $data['class_path'],
        );

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $data[$key] = $value;
            }
        }

        if (! isset($data['current_hp']) && isset($data['max_hp'])) {
            $data['current_hp'] = $data['max_hp'];
        }

        return $this->normalizePsionicSheet($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function finalizeClassEntries(array $data): array
    {
        $path = (string) ($data['class_path'] ?? 'single');
        $entries = Adnd2e::normalizeClassLevels(
            $data['class_levels'] ?? null,
            (string) ($data['class'] ?? 'Fighter'),
            (int) ($data['level'] ?? 1),
            $path,
        );
        if (count($entries) > 1 && $path === 'single') {
            $path = 'multi';
        }

        $entries = Adnd2e::backfillClassLevelsXp(
            $entries,
            $path,
            $data['experience_points'] ?? 0,
        );

        $data['class_path'] = $path;
        $data['class_levels'] = $entries;
        $data['class'] = Adnd2e::displayClassName($entries, $path);
        $data['level'] = Adnd2e::displayLevel($entries, $path);
        $data['experience_points'] = Adnd2e::derivedExperiencePoints($entries, $data['experience_points'] ?? 0);

        return $data;
    }

    /**
     * Reject beginning a second class when the original is below 6th.
     * Existing stored dual sheets that already violate the gate are kept.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertHouseDualSwitch(array $data, ?Character $existing = null): void
    {
        $path = (string) ($data['class_path'] ?? 'single');
        $entries = $data['class_levels'] ?? [];
        if ($path !== 'dual' || ! is_array($entries) || count($entries) < 2) {
            return;
        }

        $originalLevel = (int) ($entries[0]['level'] ?? 1);
        if (Adnd2e::canBeginNewClass($originalLevel)) {
            return;
        }

        if ($existing && Adnd2e::hasStoredDualSwitch(
            $existing->class_path,
            $existing->class_levels,
            (string) $existing->class,
            (int) $existing->level,
        )) {
            return;
        }

        throw ValidationException::withMessages([
            'class_levels.0.level' => 'A new class may begin only after the original class is at least 6th level (house dual-class).',
        ]);
    }

    /**
     * Sheet tools the player fills. Empty names dropped. No engine defaults.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizePsionicSheet(array $data): array
    {
        foreach (['psp_current', 'psp_max'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                $data[$key] = null;
            } else {
                $data[$key] = (int) $data[$key];
            }
        }

        $powers = $data['psionic_powers'] ?? [];
        if (! is_array($powers)) {
            $powers = [];
        }
        $data['psionic_powers'] = array_values(array_filter(
            array_map(fn ($name) => trim((string) $name), $powers),
            fn (string $name) => $name !== '',
        ));

        return $data;
    }
}
