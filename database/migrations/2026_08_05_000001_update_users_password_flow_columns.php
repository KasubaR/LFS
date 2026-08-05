<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When a bulk-imported member's shared temp password stops being usable
            // for login (see AuthenticatedSessionController + config/member_import.php).
            $table->timestamp('temp_password_expires_at')->nullable()->after('must_change_password');

            // Never read anywhere — verification is fully driven by email_verified_at
            // via Laravel's MustVerifyEmail contract.
            $table->dropColumn('force_email_verification');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temp_password_expires_at');
            $table->boolean('force_email_verification')->default(true)->after('must_change_password');
        });
    }
};
