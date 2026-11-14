<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per dashboard alert type. A dismissal only hides the alert for the
        // situation it was cleared in (fingerprint, e.g. the newest failed payment),
        // so a new failure brings it back.
        Schema::create('admin_alert_dismissals', function (Blueprint $table) {
            $table->string('alert_key', 64)->primary();
            $table->string('fingerprint', 191);
            $table->string('dismissed_by', 191)->nullable();
            $table->timestamp('dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alert_dismissals');
    }
};
