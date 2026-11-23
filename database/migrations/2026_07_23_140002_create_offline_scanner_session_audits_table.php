<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_scanner_session_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 64)->index();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->foreign('offline_scanner_session_id', 'os_audit_session_fk')
                ->references('id')
                ->on('offline_scanner_sessions')
                ->restrictOnDelete();
            $table->foreign('event_id', 'os_audit_event_fk')
                ->references('id')
                ->on('events')
                ->restrictOnDelete();
            $table->foreign('actor_user_id', 'os_audit_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_scanner_session_audits');
    }
};
