<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bibliographic index only. There is no excerpt column.
 */
class Citation extends Model
{
    protected $fillable = [
        'work',
        'year',
        'pages',
        'topic',
        'source_code',
    ];

    protected $casts = [
        'year' => 'integer',
    ];
}
