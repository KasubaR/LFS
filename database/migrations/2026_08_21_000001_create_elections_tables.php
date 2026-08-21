<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('type', 32)->default('by_election');
            $table->string('status', 32)->default('draft');
            $table->text('description')->nullable();
            $table->dateTime('scheduled_open_at')->nullable();
            $table->dateTime('scheduled_close_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('roll_locked_at')->nullable();
            $table->dateTime('ballot_approved_at')->nullable();
            $table->unsignedTinyInteger('quorum_percent')->default(50);
            $table->dateTime('quorum_confirmed_at')->nullable();
            $table->dateTime('early_open_override_at')->nullable();
            $table->unsignedInteger('early_open_override_by')->nullable();
            $table->unsignedInteger('early_open_override_second_by')->nullable();
            $table->string('early_open_override_reason')->nullable();
            $table->unsignedInteger('incomplete_ballot_count')->default(0);
            $table->dateTime('locked_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('election_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->string('title');
            $table->boolean('allow_abstain')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->index(['election_id', 'sort_order']);
        });

        Schema::create('election_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('position_id');
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('position_id')->references('id')->on('election_positions')->cascadeOnDelete();
            $table->index(['position_id', 'sort_order']);
        });

        Schema::create('election_voter_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('election_id');
            $table->string('filename');
            $table->unsignedInteger('uploaded_by')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            $table->unsignedInteger('ambiguous_rows')->default(0);
            $table->string('status', 32)->default('completed');
            $table->json('notes')->nullable();
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
        });

        Schema::create('election_voters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('raw_membership_number')->nullable();
            $table->string('raw_email')->nullable();
            $table->string('raw_phone')->nullable();
            $table->string('raw_name')->nullable();
            $table->string('match_status', 32)->default('unmatched');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('membership_id')->nullable();
            $table->dateTime('excluded_at')->nullable();
            $table->boolean('represented_by_proxy')->default(false);
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->foreign('import_batch_id')->references('id')->on('election_voter_import_batches')->nullOnDelete();
            $table->index(['election_id', 'match_status']);
            $table->index(['election_id', 'user_id']);
        });

        Schema::create('election_proxies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->uuid('grantor_voter_id');
            $table->unsignedBigInteger('holder_user_id');
            $table->string('status', 32)->default('pending');
            $table->dateTime('approved_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->foreign('grantor_voter_id')->references('id')->on('election_voters')->cascadeOnDelete();
            $table->unique(['election_id', 'grantor_voter_id']);
            $table->index(['election_id', 'holder_user_id', 'status']);
        });

        Schema::create('election_ballot_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->uuid('position_id');
            $table->unsignedBigInteger('holder_user_id');
            $table->uuid('grantor_voter_id')->nullable();
            $table->string('type', 16)->default('direct');
            $table->string('token_hash', 64);
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->foreign('position_id')->references('id')->on('election_positions')->cascadeOnDelete();
            $table->unique(['election_id', 'position_id', 'holder_user_id', 'type', 'grantor_voter_id'], 'election_entitlement_unique');
            $table->index(['election_id', 'holder_user_id']);
            $table->index('token_hash');
        });

        Schema::create('election_vote_outbox', function (Blueprint $table) {
            $table->uuid('blind_ballot_id')->primary();
            $table->uuid('election_id');
            $table->uuid('position_id');
            $table->text('ciphertext');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->index(['status', 'created_at']);
        });

        Schema::create('election_votes', function (Blueprint $table) {
            $table->uuid('blind_ballot_id')->primary();
            $table->uuid('election_id');
            $table->uuid('position_id');
            $table->text('ciphertext');
            $table->string('tally_status', 16)->nullable();
            $table->uuid('candidate_id')->nullable();
            $table->dateTime('flushed_at');
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->index(['election_id', 'position_id', 'tally_status']);
        });

        Schema::create('election_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('checked_in_at');
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->unique(['election_id', 'user_id']);
        });

        Schema::create('election_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id')->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 64);
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('entry_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['election_id', 'created_at']);
            $table->index('action');
            $table->index('entry_hash');
        });

        Schema::create('election_complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->unsignedBigInteger('reporter_user_id')->nullable();
            $table->unsignedInteger('reporter_admin_id')->nullable();
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
            $table->text('body');
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
        });

        Schema::create('election_result_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('election_id');
            $table->unsignedInteger('admin_user_id');
            $table->dateTime('certified_at');
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();
            $table->unique(['election_id', 'admin_user_id']);
        });

        Schema::table('admin_users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable()->after('password');
            $table->dateTime('totp_confirmed_at')->nullable()->after('totp_secret');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at']);
        });

        Schema::dropIfExists('election_result_certifications');
        Schema::dropIfExists('election_complaints');
        Schema::dropIfExists('election_audit_logs');
        Schema::dropIfExists('election_attendances');
        Schema::dropIfExists('election_votes');
        Schema::dropIfExists('election_vote_outbox');
        Schema::dropIfExists('election_ballot_entitlements');
        Schema::dropIfExists('election_proxies');
        Schema::dropIfExists('election_voters');
        Schema::dropIfExists('election_voter_import_batches');
        Schema::dropIfExists('election_candidates');
        Schema::dropIfExists('election_positions');
        Schema::dropIfExists('elections');
    }
};
