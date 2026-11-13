<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopPromotion extends Model
{
    protected $table = 'shop_promotions';

    protected $fillable = [
        'name',
        'code',
        'discount_type',
        'discount_value',
        'category',
        'members_only',
        'min_order_amount',
        'starts_at',
        'ends_at',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'members_only' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'shop_promotion_id');
    }
}
