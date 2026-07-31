<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    protected $table = 'memberships';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'membership_number',
        'public_token',
        'status',
        'start_date',
        'expiry_date',
        'renewal_due_date',
        'current_plan_id',
        'approval_status',
        'approved_by',
        'approved_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'current_plan_id' => 'integer',
            'start_date' => 'date',
            'expiry_date' => 'date',
            'renewal_due_date' => 'date',
            'approved_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'current_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(MembershipHistory::class);
    }

    public function isCardActive(): bool
    {
        if ($this->status !== MembershipStatus::Active) {
            return false;
        }

        if ($this->expiry_date !== null && $this->expiry_date->copy()->endOfDay()->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Conference-facing badge: Active vs Expired/Inactive.
     */
    public function cardDisplayStatus(): string
    {
        return $this->isCardActive() ? 'Active' : (
            $this->status === MembershipStatus::Expired ? 'Expired' : 'Inactive'
        );
    }

    public function verificationUrl(): ?string
    {
        if ($this->public_token === null || $this->public_token === '') {
            return null;
        }

        return route('membership.verify', ['token' => $this->public_token]);
    }
}
