<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('contact_email', 255)->nullable();

            // Public half of the credential, sent in the Bearer token.
            $table->string('key_id', 40)->unique();
            // SHA-256 of the secret half. Never reversible, compared with hash_equals.
            $table->string('key_hash', 64);

            $table->json('scopes')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);

            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index('revoked_at');
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->nullable()
                ->constrained('api_clients')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            // Outcome of the lookup itself (active/expired/not_found/...), not the HTTP status.
            $table->string('result', 30)->nullable();
            $table->unsignedSmallInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('api_client_id');
            $table->index('created_at');
            $table->index(['api_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_clients');
    }
};
