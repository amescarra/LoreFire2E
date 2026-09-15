<?php

namespace App\Models;

use App\Support\Adnd2e;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'name',
        'player_name',
        'race',
        'subrace',
        'class',
        'subclass',
        'class_path',
        'class_levels',
        'level',
        'background',
        'alignment',
        'experience_points',
        'strength',
        'exceptional_strength',
        'dexterity',
        'constitution',
        'intelligence',
        'wisdom',
        'charisma',
        'max_hp',
        'current_hp',
        'armor_class',
        'thac0',
        'speed',
        'hit_die',
        'saving_throws',
        'weapon_proficiencies',
        'nonweapon_proficiencies',
        'priest_spheres',
        'copper',
        'silver',
        'electrum',
        'gold',
        'platinum',
        'spellcasting_ability',
        'memorization',
        'memorization_used',
        'mannerisms',
        'motivations',
        'ties',
        'weaknesses',
        'backstory',
        'appearance_description',
        'portrait_path',
        'portrait_generation_status',
        'portrait_style',
        'imported_data',
        'class_features',
        'psp_current',
        'psp_max',
        'psionic_powers',
    ];

    protected $casts = [
        'saving_throws' => 'array',
        'weapon_proficiencies' => 'array',
        'nonweapon_proficiencies' => 'array',
        'priest_spheres' => 'array',
        'class_levels' => 'array',
        'memorization' => 'array',
        'memorization_used' => 'array',
        'imported_data' => 'array',
        'class_features' => 'array',
        'psp_current' => 'integer',
        'psp_max' => 'integer',
        'psionic_powers' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function spells(): HasMany
    {
        return $this->hasMany(CharacterSpell::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function inventorySnapshots(): HasMany
    {
        return $this->hasMany(InventorySnapshot::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(CharacterFeature::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(CharacterCondition::class);
    }

    public function speakerProfiles(): HasMany
    {
        return $this->hasMany(SpeakerProfile::class);
    }

    /**
     * @return array<int, array{class: string, level: int, xp?: int}>
     */
    public function classEntries(): array
    {
        return Adnd2e::normalizeClassLevels(
            $this->class_levels,
            (string) $this->class,
            (int) $this->level,
            (string) ($this->class_path ?? 'single'),
        );
    }

    /**
     * Class entries with display-time XP backfill (does not persist).
     *
     * @return array<int, array{class: string, level: int, xp?: int}>
     */
    public function displayClassEntries(): array
    {
        return Adnd2e::backfillClassLevelsXp(
            $this->classEntries(),
            (string) ($this->class_path ?? 'single'),
            $this->experience_points,
        );
    }

    public function getModifier(string $ability): int
    {
        $exceptional = $ability === 'strength' ? $this->exceptional_strength : null;
        $primary = $this->classEntries()[0]['class'] ?? (string) $this->class;

        return Adnd2e::primaryAdjustment($ability, (int) $this->{$ability}, $exceptional, $primary);
    }

    public function formattedAbilityScore(string $ability): string
    {
        $exceptional = $ability === 'strength' ? $this->exceptional_strength : null;

        return Adnd2e::formatAbilityScore($ability, (int) $this->{$ability}, $exceptional);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function abilityAdjustmentLines(string $ability): array
    {
        $exceptional = $ability === 'strength' ? $this->exceptional_strength : null;
        $primary = $this->classEntries()[0]['class'] ?? (string) $this->class;

        return Adnd2e::abilityAdjustmentLines($ability, (int) $this->{$ability}, $exceptional, $primary);
    }

    public function resolvedThac0(): int
    {
        return $this->thac0 ?? Adnd2e::combinedThac0($this->classEntries());
    }

    public function resolvedSavingThrows(): array
    {
        return $this->saving_throws ?: Adnd2e::combinedSavingThrows($this->classEntries());
    }

    public function vitalityState(): string
    {
        return Adnd2e::vitalityState((int) $this->current_hp);
    }

    public function numberNeededToHit(int $targetAc): int
    {
        return Adnd2e::numberNeededToHit($this->resolvedThac0(), $targetAc);
    }
}
