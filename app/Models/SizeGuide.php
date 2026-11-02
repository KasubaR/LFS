<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeGuide extends Model
{
    protected $table = 'size_guides';

    protected $fillable = [
        'name',
        'note',
        'columns',
        'rows',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'rows' => 'array',
        ];
    }
}
