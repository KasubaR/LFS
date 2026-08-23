<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionCandidate extends Model
{
    protected $table = 'election_candidates';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'position_id',
        'name',
        'photo_path',
        'sort_order',
        'is_active',
    ];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ElectionPosition::class, 'position_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? asset($this->photo_path) : null;
    }
}
