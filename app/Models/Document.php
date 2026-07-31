<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'category',
        'description',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'is_published',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
