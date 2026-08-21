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
        'sort_order',
        'is_active',
    ];

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
}
