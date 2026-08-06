<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $table = 'promotions';

    protected $fillable = [
        'name',
        'plan_id',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'discount_value' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class, 'promotion_id');
    }
}
