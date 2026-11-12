<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row table (id = 1): when registrations/renewals for the NEXT
        // calendar year open. Null = not open early; renewals open 1 January.
        Schema::create('membership_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->date('next_year_opens_on')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_settings');
    }
};
