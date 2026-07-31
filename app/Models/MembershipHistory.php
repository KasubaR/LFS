<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipHistory extends Model
{
    protected $table = 'membership_history';

    public $timestamps = false;

    protected $fillable = [
        'membership_id',
        'user_id',
        'event',
        'from_status',
        'to_status',
        'plan_id',
        'metadata',
        'actor',
        'is_active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'plan_id' => 'integer',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }
}
