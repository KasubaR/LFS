<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * events.created_by was typed as a uuid, but the only entity that
     * creates events (an authenticated admin, App\Models\AdminUser) has an
     * auto-increment integer id — so the column could never actually be
     * populated with a real, meaningful value. Repoint it at admin_users.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('created_by');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('brochure_pdf')
                ->constrained('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->uuid('created_by')->nullable()->after('brochure_pdf');
        });
    }
};
