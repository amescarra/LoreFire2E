<?php

namespace App\Models;

use App\Support\Adnd2e;
use App\Support\SpellMaterialComponents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterSpell extends Model
{
    protected $fillable = [
        'character_id',
        'name',
        'level',
        'school',
        'casting_time',
        'range',
        'components',
        'duration',
        'description',
        'is_prepared',
        'is_cast',
        'times_memorized',
        'times_cast',
    ];

    protected $casts = [
        'is_prepared' => 'boolean',
        'is_cast' => 'boolean',
        'times_memorized' => 'integer',
        'times_cast' => 'integer',
    ];

    protected $appends = [
        'remaining_memorized',
        'material_requirements',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $spell) {
            $flags = Adnd2e::spellVancianFlags(
                (int) $spell->times_memorized,
                (int) $spell->times_cast,
            );
            foreach ($flags as $key => $value) {
                $spell->{$key} = $value;
            }
        });
    }

    public function getRemainingMemorizedAttribute(): int
    {
        return Adnd2e::remainingMemorized((int) $this->times_memorized, (int) $this->times_cast);
    }

    /**
     * Named materials/foci from this spell's components/description, or from
     * Adnd2eSpellCatalog when the sheet record has no named items.
     *
     * @return list<array{name: string, quantity: int, consumed: bool, focus: bool}>
     */
    public function getMaterialRequirementsAttribute(): array
    {
        return SpellMaterialComponents::requirementsFor($this);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
