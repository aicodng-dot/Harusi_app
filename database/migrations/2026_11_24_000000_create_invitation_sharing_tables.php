<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation Sharing Center (manual sharing through the customer's own WhatsApp / SMS / share sheet).
 * Additive only: no existing table or column changes.
 *
 *  - invitation_links: one guest-facing invitation link per guest. Only the SHA-256 of the
 *    random key is used for lookup; the key itself is kept encrypted so the owner can copy the
 *    same link again. The link is bound to the guest's QR credential (fingerprint, never the
 *    token): if the credential changes, the old link stops working and a new one is issued.
 *  - invitation_share_templates: the owner's reusable message texts with {{placeholders}}.
 *  - invitation_share_sessions: a guided "one guest at a time" bulk sharing run, so progress
 *    survives closing the browser. Recipients are guest ids only (no copies of names/phones).
 *  - invitation_shares: what the application genuinely knows about sharing one guest's
 *    invitation on one channel: the owner opened the app (initiated), the owner said they
 *    sent it (confirmed) or skipped the guest. Never "delivered" or "read".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('key_hash', 64)->unique();
            $table->text('key_encrypted');
            $table->char('token_fingerprint', 64);
            $table->timestamps();
        });

        Schema::create('invitation_share_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('invitation_share_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->foreignId('template_id')->nullable()->constrained('invitation_share_templates')->nullOnDelete();
            $table->text('message');
            $table->json('recipient_ids');
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'user_id', 'status']);
        });

        Schema::create('invitation_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('invitation_share_sessions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('invitation_share_templates')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'guest_id']);
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_shares');
        Schema::dropIfExists('invitation_share_sessions');
        Schema::dropIfExists('invitation_share_templates');
        Schema::dropIfExists('invitation_links');
    }
};
