<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class RuleCard extends Model
{
    public const SUMMARY_MAX = 280;

    protected $fillable = [
        'name',
        'source_code',
        'effect_summary',
        'citation_work',
        'citation_year',
        'citation_pages',
        'citation_topic',
        'user_verified',
    ];

    protected $casts = [
        'citation_year' => 'integer',
        'user_verified' => 'boolean',
    ];
}
