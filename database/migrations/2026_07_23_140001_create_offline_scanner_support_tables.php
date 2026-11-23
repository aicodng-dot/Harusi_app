<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_scanner_session_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->unsignedBigInteger('scanner_user_id')->nullable();
            $table->string('assigned_gate', 120)->nullable();
            $table->enum('device_role', ['master', 'client'])->default('client');
            $table->string('offline_pin_verifier', 255)->nullable();
            $table->string('offline_pin_salt', 255)->nullable();
            $table->string('offline_pin_algorithm', 80)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->unsignedBigInteger('master_session_id')->storedAs(
                "CASE WHEN device_role = 'master' THEN offline_scanner_session_id ELSE NULL END"
            );
            $table->unique(['offline_scanner_session_id', 'scanner_user_id'], 'offline_session_users_session_user_unique');
            $table->unique('master_session_id', 'offline_session_users_one_master_unique');
            $table->foreign('offline_scanner_session_id', 'os_su_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('scanner_user_id', 'os_su_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offline_scanner_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('package_uuid')->unique();
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->string('file_name', 255);
            $table->string('file_path', 1024);
            $table->string('file_hash', 128)->index();
            $table->unsignedBigInteger('encrypted_file_size');
            $table->string('encryption_algorithm', 120);
            $table->string('password_kdf_algorithm', 120)->nullable();
            $table->json('password_kdf_parameters')->nullable();
            $table->string('signature_algorithm', 120)->nullable();
            $table->string('signature_key_id', 191)->nullable();
            $table->string('package_fingerprint', 128)->index();
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreign('offline_scanner_session_id', 'os_pkg_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('generated_by_user_id', 'os_pkg_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('offline_scanner_sessions', function (Blueprint $table): void {
            $table->foreign('package_export_id', 'offline_sessions_package_export_fk')
                ->references('id')
                ->on('offline_scanner_packages')
                ->nullOnDelete();
        });

        Schema::create('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_uuid')->unique();
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('source_results_export_id')->nullable()->index();
            $table->string('source_master_device_id', 191)->nullable()->index();
            $table->string('file_name', 255);
            $table->string('file_hash', 128)->index();
            $table->enum('status', [
                'uploaded', 'verified', 'validation_failed', 'preview_ready', 'importing',
                'completed', 'completed_with_conflicts', 'failed',
            ])->default('uploaded')->index();
            $table->unsignedInteger('total_logs')->default(0);
            $table->unsignedInteger('imported_logs')->default(0);
            $table->unsignedInteger('duplicate_logs')->default(0);
            $table->unsignedInteger('conflict_logs')->default(0);
            $table->unsignedInteger('rejected_logs')->default(0);
            $table->unsignedInteger('unknown_guest_logs')->default(0);
            $table->text('verification_error')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreign('offline_scanner_session_id', 'os_ri_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('uploaded_by_user_id', 'os_ri_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offline_scanner_imported_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_result_import_id');
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->uuid('log_uuid');
            $table->uuid('request_uuid')->nullable();
            $table->unsignedBigInteger('master_sequence_number')->nullable();
            $table->string('external_guest_id', 191)->nullable();
            $table->unsignedBigInteger('scanner_user_id')->nullable();
            $table->string('source_device_id', 191)->nullable();
            $table->string('source_gate_name', 120)->nullable();
            $table->string('admission_method', 40)->nullable();
            $table->unsignedTinyInteger('added_entries')->default(0);
            $table->unsignedTinyInteger('used_before')->default(0);
            $table->unsignedTinyInteger('remaining_before')->default(0);
            $table->unsignedTinyInteger('used_after')->default(0);
            $table->unsignedTinyInteger('remaining_after')->default(0);
            $table->string('result_code', 40);
            $table->timestamp('device_timestamp')->nullable();
            $table->timestamp('master_timestamp')->nullable();
            $table->enum('import_status', [
                'pending', 'imported', 'duplicate', 'conflict', 'rejected', 'unknown_guest',
            ])->default('pending')->index();
            $table->string('conflict_reason', 255)->nullable();
            $table->string('raw_payload_hash', 128)->nullable();
            $table->unique(['offline_scanner_session_id', 'log_uuid'], 'offline_logs_session_log_uuid_unique');
            $table->unique(['offline_scanner_session_id', 'request_uuid'], 'offline_logs_session_request_uuid_unique');
            $table->unique(['offline_scanner_session_id', 'master_sequence_number'], 'offline_logs_session_master_sequence_unique');
            $table->foreign('offline_scanner_result_import_id', 'os_log_import_fk')->references('id')->on('offline_scanner_result_imports')->restrictOnDelete();
            $table->foreign('offline_scanner_session_id', 'os_log_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('scanner_user_id', 'os_log_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offline_scanner_import_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_result_import_id');
            $table->unsignedBigInteger('offline_scanner_imported_log_id')->nullable();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('conflict_type', 80)->index();
            $table->unsignedTinyInteger('server_used')->nullable();
            $table->unsignedTinyInteger('server_remaining')->nullable();
            $table->unsignedTinyInteger('offline_used_before')->nullable();
            $table->unsignedTinyInteger('offline_remaining_before')->nullable();
            $table->unsignedTinyInteger('offline_added')->nullable();
            $table->unsignedTinyInteger('offline_used_after')->nullable();
            $table->unsignedTinyInteger('offline_remaining_after')->nullable();
            $table->string('recommended_action', 120)->nullable();
            $table->enum('resolution_status', ['open', 'resolved', 'dismissed'])->default('open')->index();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreign('offline_scanner_result_import_id', 'os_conf_import_fk')->references('id')->on('offline_scanner_result_imports')->restrictOnDelete();
            $table->foreign('offline_scanner_imported_log_id', 'os_conf_log_fk')->references('id')->on('offline_scanner_imported_logs')->nullOnDelete();
            $table->foreign('event_id', 'os_conf_event_fk')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('guest_id', 'os_conf_guest_fk')->references('id')->on('guests')->nullOnDelete();
            $table->foreign('resolved_by_user_id', 'os_conf_resolver_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('offline_scanner_sessions')) {
            Schema::table('offline_scanner_sessions', function (Blueprint $table): void {
                $table->dropForeign('offline_sessions_package_export_fk');
            });
        }

        Schema::dropIfExists('offline_scanner_import_conflicts');
        Schema::dropIfExists('offline_scanner_imported_logs');
        Schema::dropIfExists('offline_scanner_result_imports');
        Schema::dropIfExists('offline_scanner_packages');
        Schema::dropIfExists('offline_scanner_session_users');
    }
};