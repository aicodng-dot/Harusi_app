<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_scanner_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('package_export_id')->nullable()->index();
            $table->string('schema_version', 32)->default('1.0');
            $table->enum('status', [
                'draft',
                'package_generated',
                'active',
                'awaiting_results',
                'results_uploaded',
                'results_imported',
                'conflict',
                'closed',
                'cancelled',
                'expired',
            ])->default('draft')->index();
            $table->foreignId('master_scanner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('master_gate_name', 120)->nullable();
            $table->string('package_fingerprint', 128)->nullable()->index();
            $table->timestamp('package_created_at')->nullable();
            $table->timestamp('package_expires_at')->nullable();
            $table->timestamp('session_started_at')->nullable();
            $table->timestamp('session_closed_at')->nullable();
            $table->timestamp('results_imported_at')->nullable();
            $table->unsignedBigInteger('last_master_sequence')->default(0);
            $table->string('guest_snapshot_version', 64)->nullable();
            $table->text('notes')->nullable();

            // A unique generated value is present only while the event is frozen for offline admission.
            $table->unsignedBigInteger('active_event_id')->storedAs(
                "CASE WHEN status IN ('package_generated', 'active', 'awaiting_results', 'results_uploaded', 'conflict') THEN event_id ELSE NULL END"
            );
            $table->unique('active_event_id', 'offline_sessions_active_event_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_scanner_sessions');
    }
};
