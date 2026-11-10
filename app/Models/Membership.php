<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    /**
     * Whatever way a membership row gets a number (signup, import, renewal, a
     * script), it must not be a number another person already holds. A renewal
     * carries the same person's number forward, which the registry allows.
     * (Query-builder updates skip model events; MembershipService claims
     * explicitly where it sets a number that way.)
     */
    protected static function booted(): void
    {
        static::saving(function (Membership $membership): void {
            $number = $membership->membership_number;
            if ($number === null || trim((string) $number) === '') {
                return;
            }
            if ($membership->exists && ! $membership->isDirty('membership_number')) {
                return;
            }

            app(\App\Services\MembershipNumberRegistry::class)->claim((string) $number, (int) $membership->user_id);
        });
    }

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
        'grace_period_ends_at',
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
            'grace_period_ends_at' => 'date',
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
        if ($this->isCardActive()) {
            return 'Active';
        }

        return match ($this->status) {
            MembershipStatus::Expired => 'Expired',
            MembershipStatus::Suspended => 'Suspended',
            default => 'Inactive',
        };
    }

    public function verificationUrl(): ?string
    {
        if ($this->public_token === null || $this->public_token === '') {
            return null;
        }

        return route('membership.verify', ['token' => $this->public_token]);
    }
}
