<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignVoiceprint extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'display_name',
        'character_id',
        'is_dm',
        'embedding',
        'embedding_model',
        'enrollment_audio_path',
        'enrolled_at',
    ];

    protected $casts = [
        'is_dm' => 'boolean',
        'embedding' => 'array',
        'enrolled_at' => 'datetime',
    ];

    protected $hidden = [
        'embedding',
    ];

    protected $appends = [
        'has_embedding',
    ];

    public function getHasEmbeddingAttribute(): bool
    {
        return $this->hasEmbedding();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function speakerProfiles(): HasMany
    {
        return $this->hasMany(SpeakerProfile::class);
    }

    public function hasEmbedding(): bool
    {
        return is_array($this->embedding) && $this->embedding !== [];
    }

    public function transcriptLabel(): string
    {
        $name = trim((string) $this->display_name);
        if ($name !== '') {
            return $name;
        }

        if ($this->is_dm) {
            return 'Dungeon Master';
        }

        return $this->character?->name ?? 'Unknown';
    }
}
