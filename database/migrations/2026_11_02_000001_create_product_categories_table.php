<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the product category list out of code (App\Enums\ProductCategory) and
 * into the database. products.category keeps storing the slug, so no product
 * rows change. Any category already used by a product but missing from the
 * original six is imported too, so nothing is orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $defaults = [
            'running-kits' => 'Running Kits',
            't-shirts' => 'T-Shirts',
            'caps' => 'Caps',
            'shorts' => 'Shorts',
            'accessories' => 'Accessories',
            'other' => 'Other',
        ];

        $inUse = DB::table('products')->whereNotNull('category')->distinct()->pluck('category')->all();
        foreach ($inUse as $slug) {
            $defaults[$slug] ??= ucwords(str_replace('-', ' ', (string) $slug));
        }

        $now = now();
        $order = 1;
        foreach ($defaults as $slug => $name) {
            DB::table('product_categories')->insert([
                'slug' => $slug,
                'name' => $name,
                'sort_order' => $order++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
