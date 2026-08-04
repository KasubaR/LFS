<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weekly recurring events can be saved without a specific "next
     * occurrence" date (the admin form explicitly allows this), so the
     * column must accept null instead of failing the insert.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dateTime('event_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dateTime('event_date')->nullable(false)->change();
        });
    }
};
