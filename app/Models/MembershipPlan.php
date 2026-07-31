<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    protected $table = 'membership_plans';

    protected $fillable = [
        'name',
        'billing_cycle',
        'price',
        'duration_months',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'current_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class, 'plan_id');
    }
}
