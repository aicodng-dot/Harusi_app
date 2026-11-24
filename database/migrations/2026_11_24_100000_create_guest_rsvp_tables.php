<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RSVP (docs/RSVP_AND_GUEST_TICKETS.md). Additive only.
 *
 *  - events: per-event RSVP settings (on/off, optional closing time in UTC, whether a guest
 *    may change an earlier answer). Existing rows get the defaults: on, no deadline, changes allowed.
 *  - guest_rsvps: one current response per guest (pending | attending | not_attending), created
 *    when the guest's personal invitation link is issued. Expected headcount and a short note
 *    are planning data only; they never change the pass, QR code or admissions.
 *  - guest_rsvp_submissions: idempotency receipts + minimal audit, one per accepted submission.
 *
 * Deleting a guest removes their RSVP and receipts with them (same as their invitation card),
 * so the existing guest deletion flow keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('rsvp_enabled')->default(true);
            $table->timestamp('rsvp_deadline')->nullable();
            $table->boolean('rsvp_allow_changes')->default(true);
        });

        Schema::create('guest_rsvps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('response', 16)->default('pending');
            $table->unsignedSmallInteger('attending_count')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('invited_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('response_changed_at')->nullable();
            $table->string('response_source', 16)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'response']);
        });

        Schema::create('guest_rsvp_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rsvp_id')->constrained('guest_rsvps')->cascadeOnDelete();
            $table->char('request_key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->string('previous_response', 16);
            $table->string('result_response', 16);
            $table->unsignedInteger('result_revision');
            $table->string('source', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('no_change')->default(false);
            $table->timestamp('accepted_at');

            $table->unique(['rsvp_id', 'request_key_hash']);
            $table->index('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_rsvp_submissions');
        Schema::dropIfExists('guest_rsvps');
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['rsvp_enabled', 'rsvp_deadline', 'rsvp_allow_changes']);
        });
    }
};
