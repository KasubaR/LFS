<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The distance summary is a comma-joined list of every distance-route
     * label on the event (e.g. "5K, 10K, 21.1K Half Marathon"), which can
     * easily exceed the original 50-character limit once an event has more
     * than a couple of distances.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('distance', 255)->nullable()->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('distance', 50)->nullable()->default('')->change();
        });
    }
};
