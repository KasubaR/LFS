<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('town', 100)->nullable()->after('satellite_id');
            $table->string('t_shirt_size', 20)->nullable()->after('town');
            $table->dateTime('registered_at')->nullable()->after('t_shirt_size');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['town', 't_shirt_size', 'registered_at']);
        });
    }
};
