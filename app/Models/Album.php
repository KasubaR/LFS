<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    protected $table = 'albums';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'category',
        'date',
        'location',
        'event',
        'tags',
        'cover_image',
        'external_url',
        'media_count',
        'featured',
        'homepage_slider',
        'event_highlight',
        'sort_priority',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'tags' => 'array',
            'media_count' => 'integer',
            'featured' => 'boolean',
            'homepage_slider' => 'boolean',
            'event_highlight' => 'boolean',
            'sort_priority' => 'integer',
        ];
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }
}
