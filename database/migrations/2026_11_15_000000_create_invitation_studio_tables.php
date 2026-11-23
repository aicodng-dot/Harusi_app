<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation workflow persistence. Additive only; no existing table or column changes.
 *
 *  - invitation_designs: one versioned design document per event (template + the
 *    owner's customisation). The event row stays the source of event facts.
 *  - invitation_cards: one generated personalised card per event guest. It records
 *    which design version and which QR credential (by SHA-256 fingerprint, never the
 *    token) produced the files, so a stale card is detectable and regeneration is
 *    idempotent. Rendering never creates admissions or changes allowances.
 *  - invitation_activity: audit trail of important owner actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_designs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 40);
            $table->longText('document');
            $table->unsignedInteger('version')->default(1);
            $table->char('photo_id', 32)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invitation_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_code_id')->nullable()->constrained('qr_codes')->nullOnDelete();
            $table->unsignedInteger('design_version');
            $table->char('design_fingerprint', 64);
            $table->char('token_fingerprint', 64);
            $table->string('png_path');
            $table->string('pdf_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['event_id', 'guest_id']);
        });

        Schema::create('invitation_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40)->index();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_activity');
        Schema::dropIfExists('invitation_cards');
        Schema::dropIfExists('invitation_designs');
    }
};
