<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'user_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->nullOnDelete();

                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('wishlist_items')) {
            Schema::create('wishlist_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('product_id');
                $table->timestamps();

                $table->unique(['user_id', 'product_id']);
                $table->index('product_id');

                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');

        if (Schema::hasColumn('orders', 'user_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
