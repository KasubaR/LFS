<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 255);
            $table->string('category', 40);
            $table->text('description')->nullable();
            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedInteger('file_size')->default(0);
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'sort_order']);
            $table->index('is_published');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
