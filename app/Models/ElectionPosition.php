<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionPosition extends Model
{
    protected $table = 'election_positions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'election_id',
        'title',
        'allow_abstain',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'allow_abstain' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ElectionCandidate::class, 'position_id')->orderBy('sort_order');
    }
}
