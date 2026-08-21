<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionBallotEntitlement extends Model
{
    protected $table = 'election_ballot_entitlements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'election_id',
        'position_id',
        'holder_user_id',
        'grantor_voter_id',
        'type',
        'token_hash',
        'issued_at',
        'used_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'used_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ElectionPosition::class, 'position_id');
    }

    public function isAvailable(): bool
    {
        return $this->used_at === null && $this->expired_at === null;
    }
}
