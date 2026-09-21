<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalDocument extends Model
{
    protected $fillable = [
        'code',
        'title',
        'kind',
        'opted_in',
        'notes',
    ];

    protected $casts = [
        'opted_in' => 'boolean',
    ];
}
