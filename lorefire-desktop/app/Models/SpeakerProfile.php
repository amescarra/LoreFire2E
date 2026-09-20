<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeakerProfile extends Model
{
    protected $fillable = [
        'campaign_id',
        'game_session_id',
        'speaker_label',
        'display_name',
        'character_id',
        'is_dm',
        'campaign_voiceprint_id',
        'match_confidence',
        'match_source',
    ];

    protected $casts = [
        'is_dm' => 'boolean',
        'match_confidence' => 'float',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function voiceprint(): BelongsTo
    {
        return $this->belongsTo(CampaignVoiceprint::class, 'campaign_voiceprint_id');
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

        return $this->character?->name ?? $this->speaker_label;
    }
}
