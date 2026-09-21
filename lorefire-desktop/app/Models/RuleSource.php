<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuleSource extends Model
{
    protected $fillable = [
        'code',
        'title',
        'year',
        'tsr_number',
        'era',
        'enabled',
        'citation_only',
        'notes',
    ];

    protected $casts = [
        'year' => 'integer',
        'enabled' => 'boolean',
        'citation_only' => 'boolean',
    ];
}
