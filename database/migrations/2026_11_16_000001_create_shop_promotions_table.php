<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Null = automatic sale (no code needed); set = promo code, stored uppercase.
            $table->string('code', 30)->nullable()->unique();
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 10, 2);
            // Product category slug. Null = every product.
            $table->string('category', 100)->nullable();
            $table->boolean('members_only')->default(false);
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shop_promotion_id')->nullable()->constrained('shop_promotions')->nullOnDelete();
            // Snapshots so later edits/deletes of the promotion never rewrite order history.
            $table->string('promo_code', 30)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_promotion_id');
            $table->dropColumn(['promo_code', 'discount_amount']);
        });
        Schema::dropIfExists('shop_promotions');
    }
};
