<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what to a ticket or order (mark present, undo, and later:
 * cancel, name correction, capacity change). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_ticket_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->uuid('order_id')->nullable();
            $table->uuid('ticket_id')->nullable();
            // Admin ids may be ints or uuids elsewhere in the app; keep a snapshot of the name too.
            $table->string('admin_id', 64)->nullable();
            $table->string('admin_name', 255)->nullable();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_id', 'created_at']);
            $table->index('ticket_id');
            $table->index('order_id');
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_ticket_audits');
    }
};
