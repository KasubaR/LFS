<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('gender', 20)->nullable()->after('phone');
            $table->string('nationality', 100)->nullable()->after('gender');
            $table->foreignId('satellite_id')->nullable()->after('nationality')
                ->constrained('satellites')->nullOnDelete();
            $table->dateTime('first_login')->nullable()->after('satellite_id');
            $table->boolean('must_change_password')->default(false)->after('first_login');
            $table->boolean('force_email_verification')->default(true)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('satellite_id');
            $table->dropColumn([
                'phone',
                'gender',
                'nationality',
                'first_login',
                'must_change_password',
                'force_email_verification',
            ]);
        });
    }
};
