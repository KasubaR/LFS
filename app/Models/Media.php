<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $table = 'media';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'album_id',
        'filename',
        'stored_name',
        'type',
        'mimetype',
        'size',
        'urls',
        'caption',
        'tags',
        'featured',
        'sort_order',
        'homepage_slider',
        'event_highlight',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'urls' => 'array',
            'tags' => 'array',
            'featured' => 'boolean',
            'sort_order' => 'integer',
            'homepage_slider' => 'boolean',
            'event_highlight' => 'boolean',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }
}
