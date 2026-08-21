<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ElectionVoter extends Model
{
    protected $table = 'election_voters';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'election_id',
        'import_batch_id',
        'raw_membership_number',
        'raw_email',
        'raw_phone',
        'raw_name',
        'match_status',
        'user_id',
        'membership_id',
        'excluded_at',
        'represented_by_proxy',
    ];

    protected function casts(): array
    {
        return [
            'excluded_at' => 'datetime',
            'represented_by_proxy' => 'boolean',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function proxy(): HasOne
    {
        return $this->hasOne(ElectionProxy::class, 'grantor_voter_id');
    }

    public function isEligible(): bool
    {
        return $this->excluded_at === null
            && $this->match_status === 'matched'
            && $this->user_id !== null
            && ! $this->represented_by_proxy;
    }
}
