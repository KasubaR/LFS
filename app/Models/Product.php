<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'price',
        'compare_price',
        'description',
        'short_description',
        'images',
        'thumbnail',
        'category',
        'gender',
        'tags',
        'sizes',
        'total_stock',
        'featured',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'images' => 'array',
            'tags' => 'array',
            'sizes' => 'array',
            'total_stock' => 'integer',
            'featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id', 'id');
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class, 'product_id', 'id');
    }
}
