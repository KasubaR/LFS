<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket orders and tickets for paid events (see docs/EVENT_TICKETING_PLAN.md).
 *
 * The unused event_registrations table is deliberately left alone: it requires
 * a user and is unique per (event, user), so it cannot hold guests or several
 * tickets per buyer.
 *
 * Buyer data lives on the order; attendee data lives on each ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_ticket_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number', 30)->unique();
            $table->uuid('event_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Set only when the buyer's own membership discount was applied.
            $table->uuid('membership_id')->nullable();

            // Buyer (who pays)
            $table->string('buyer_name', 255);
            $table->string('buyer_email', 255);
            $table->string('buyer_phone', 30)->default('');
            // Mobile money number charged; may differ from buyer_phone.
            $table->string('payer_phone', 30)->default('');

            // Pricing snapshot: later price edits never change a past order.
            $table->boolean('is_member_pricing')->default(false);
            $table->decimal('member_unit_price', 12, 2)->nullable();
            $table->decimal('standard_unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('total', 12, 2);

            // pending_payment | paid | failed | cancelled | payment_review_required
            $table->string('status', 30)->default('pending_payment');
            // Reservation expiry for pending_payment orders.
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('confirmation_email_sent_at')->nullable();
            // Per-checkout-page token so a double submit returns the same order.
            $table->string('idempotency_token', 64)->nullable()->unique();

            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->index(['event_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('buyer_email');
            $table->index('membership_id');
        });

        Schema::create('event_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('event_id');
            $table->unsignedSmallInteger('position');

            // Attendee (who attends). Not the same as the buyer.
            $table->string('attendee_name', 100);
            $table->string('attendee_email', 255)->nullable();

            // Human readable, shown in the confirmation email. No QR codes.
            $table->string('ticket_reference', 50)->unique();
            $table->decimal('price', 12, 2);

            // Member discount binding (exactly one per discounted order).
            $table->boolean('is_member_discount')->default(false);
            $table->uuid('membership_id')->nullable();
            $table->string('membership_number', 20)->nullable();

            // Admin typo corrections (standard tickets only), capped at 2.
            $table->unsignedTinyInteger('name_correction_count')->default(0);

            // Manual attendance: set when an organiser marks the attendee present.
            $table->dateTime('checked_in_at')->nullable();

            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('event_ticket_orders')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->unique(['order_id', 'position']);
            $table->index('event_id');
            $table->index(['event_id', 'membership_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tickets');
        Schema::dropIfExists('event_ticket_orders');
    }
};
