<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 500);
            $table->text('description')->nullable()->default('');
            $table->string('category', 100)->nullable();
            $table->dateTime('date')->nullable();
            $table->string('location', 255)->nullable()->default('');
            $table->string('event', 255)->nullable()->default('');
            $table->json('tags')->nullable()->default('[]');
            $table->string('cover_image', 500)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->unsignedInteger('media_count')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('homepage_slider')->default(false);
            $table->boolean('event_highlight')->default(false);
            $table->integer('sort_priority')->default(0);
            $table->timestamps();

            $table->index('date');
            $table->index('created_at');
            $table->index('featured');
            $table->index('category');
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable()->default('');
            $table->string('featured_image', 500)->nullable()->default('');
            $table->string('author', 100)->default('LFS Admin');
            $table->string('category', 50);
            $table->json('tags')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('views')->default(0);
            $table->dateTime('publish_date')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('status');
            $table->index('featured');
            $table->index('publish_date');
            $table->index('created_at');
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('subject', 255)->nullable()->default('');
            $table->text('message');
            $table->string('status', 20)->default('New');
            $table->dateTime('created_at')->useCurrent();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('contact_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contact_message_id');
            $table->text('reply_message');
            $table->dateTime('created_at')->useCurrent();

            $table->index('contact_message_id');
            $table->index('created_at');

            $table->foreign('contact_message_id')
                ->references('id')
                ->on('contact_messages')
                ->cascadeOnDelete();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 255);
            $table->string('slug', 255)->nullable()->unique();
            $table->text('description')->nullable()->default('');
            $table->string('location', 255)->nullable()->default('');
            $table->dateTime('event_date');
            $table->string('distance', 50)->nullable()->default('');
            $table->string('recurrence_type', 20)->default('none');
            $table->string('recurrence_days', 100)->nullable();
            $table->string('category', 100)->nullable()->default('');
            $table->dateTime('registration_open')->nullable();
            $table->dateTime('registration_close')->nullable();
            $table->string('registration_type', 20)->default('open');
            $table->string('registration_link', 2048)->nullable();
            $table->string('banner_image', 500)->nullable();
            $table->boolean('feature_on_home')->default(false);
            $table->string('brochure_pdf', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index('event_date');
            $table->index('category');
            $table->index('created_at');
        });

        Schema::create('event_distance_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('label', 80);
            $table->string('route_image', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->index('event_id');

            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('user_id');
            $table->string('bib_number', 50)->nullable()->default('');
            $table->string('status', 30)->default('Registered');
            $table->string('payment_status', 20)->default('pending');
            $table->dateTime('registered_at')->nullable()->useCurrent();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index('event_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('registered_at');

            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();
        });

        Schema::create('event_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('runner_name', 255);
            $table->unsignedInteger('position');
            $table->string('time', 20)->default('');
            $table->string('category', 100)->nullable()->default('');
            $table->string('club', 255)->nullable()->default('');
            $table->timestamps();

            $table->index('event_id');
            $table->index(['event_id', 'position']);
            $table->index('category');

            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('question');
            $table->text('answer');
            $table->string('category', 100)->nullable()->default('');
            $table->dateTime('created_at')->useCurrent();

            $table->index('category');
            $table->index('created_at');
        });

        Schema::create('gallery_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('banner_image', 255)->nullable();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('album_id');
            $table->string('filename', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->enum('type', ['photo', 'video']);
            $table->string('mimetype', 100)->nullable();
            $table->bigInteger('size')->nullable();
            $table->json('urls')->nullable()->default('{}');
            $table->text('caption')->nullable()->default('');
            $table->json('tags')->nullable()->default('[]');
            $table->boolean('featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('homepage_slider')->default(false);
            $table->boolean('event_highlight')->default(false);
            $table->timestamps();

            $table->index('album_id');
            $table->index('created_at');
            $table->index('type');

            $table->foreign('album_id')
                ->references('id')
                ->on('albums')
                ->cascadeOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->decimal('price', 10, 2);
            $table->decimal('compare_price', 10, 2)->nullable();
            $table->text('description')->nullable()->default('');
            $table->text('short_description')->nullable()->default('');
            $table->json('images')->nullable()->default('[]');
            $table->string('thumbnail', 500)->nullable()->default('/images/products/placeholder.webp');
            $table->string('category', 50);
            $table->string('gender', 20)->default('unisex');
            $table->json('tags')->nullable()->default('[]');
            $table->json('sizes')->nullable()->default('[]');
            $table->unsignedInteger('total_stock')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
            $table->index('created_at');
            $table->index('price');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->string('customer_name', 255);
            $table->string('customer_email', 255);
            $table->string('customer_phone', 30)->default('');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 30)->default('pending_payment');
            $table->timestamps();

            $table->index('customer_email');
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('product_id', 100);
            $table->string('name', 255);
            $table->string('size', 20)->default('');
            $table->unsignedSmallInteger('qty')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);

            $table->index('order_id');
            $table->index('product_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30);
            $table->string('payment_method', 30)->default('mobile_money');
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('ZMW');
            $table->string('status', 20)->default('pending');
            $table->string('customer_name', 255)->default('');
            $table->string('customer_email', 255)->default('');
            $table->string('customer_phone', 30)->default('');
            $table->string('lenco_transaction_id', 255)->nullable()->unique();
            $table->string('lenco_reference', 255)->nullable()->unique();
            $table->string('lenco_provider', 20)->nullable();
            $table->string('lenco_status', 50)->nullable();
            $table->json('lenco_response')->nullable();
            $table->string('transaction_id', 255)->nullable()->unique();
            $table->text('payment_instructions')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('webhook_received')->default(false);
            $table->json('webhook_payload')->nullable();
            $table->dateTime('webhook_received_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('order_number');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('media');
        Schema::dropIfExists('gallery_settings');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('event_results');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_distance_routes');
        Schema::dropIfExists('events');
        Schema::dropIfExists('contact_replies');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('albums');
    }
};
