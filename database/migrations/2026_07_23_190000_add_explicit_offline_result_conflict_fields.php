<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->string('encrypted_file_path', 1024)->nullable()->after('file_hash');
            $table->unsignedBigInteger('encrypted_file_size')->nullable()->after('encrypted_file_path');
        });

        Schema::table('offline_scanner_package_guest_snapshots', function (Blueprint $table): void {
            $table->string('pass_type', 20)->nullable()->after('record_version');
            $table->string('guest_status', 20)->nullable()->after('pass_type');
            $table->string('manual_code_hash', 128)->nullable()->after('guest_status');
            $table->string('qr_token_hash', 128)->nullable()->after('manual_code_hash');
        });

        Schema::table('offline_scanner_imported_logs', function (Blueprint $table): void {
            // InnoDB needs a replacement index before the FK-supporting unique
            // index can be removed. Preserve index names and conflict semantics.
            $table->index(['offline_scanner_session_id', 'log_uuid'], 'offline_logs_session_log_uuid_index');
            $table->index(['offline_scanner_session_id', 'request_uuid'], 'offline_logs_session_request_uuid_index');
            $table->index(['offline_scanner_session_id', 'master_sequence_number'], 'offline_logs_session_master_sequence_index');
            $table->index(['offline_scanner_session_id', 'source_device_id', 'monotonic_sequence'], 'offline_logs_session_device_monotonic_index');

            $table->dropUnique('offline_logs_session_log_uuid_unique');
            $table->dropUnique('offline_logs_session_request_uuid_unique');
            $table->dropUnique('offline_logs_session_master_sequence_unique');
            $table->dropUnique('offline_logs_session_device_monotonic_unique');
        });

        Schema::table('offline_scanner_import_conflicts', function (Blueprint $table): void {
            $table->text('conflict_reason')->nullable()->after('conflict_type');
            $table->json('server_state')->nullable()->after('offline_remaining_after');
            $table->json('snapshot_state')->nullable()->after('server_state');
            $table->json('offline_request')->nullable()->after('snapshot_state');
            $table->json('expected_final_state')->nullable()->after('offline_request');
            $table->json('allowed_actions')->nullable()->after('expected_final_state');
            $table->string('resolution_action', 80)->nullable()->after('resolution_status');
            $table->unsignedBigInteger('replacement_guest_id')->nullable()->after('resolved_by_user_id');
            $table->unsignedTinyInteger('manual_used_entries')->nullable()->after('replacement_guest_id');

            $table->foreign('replacement_guest_id', 'os_conf_replacement_guest_fk')
                ->references('id')
                ->on('guests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offline_scanner_import_conflicts', function (Blueprint $table): void {
            $table->dropForeign('os_conf_replacement_guest_fk');
            $table->dropColumn([
                'conflict_reason',
                'server_state',
                'snapshot_state',
                'offline_request',
                'expected_final_state',
                'allowed_actions',
                'resolution_action',
                'replacement_guest_id',
                'manual_used_entries',
            ]);
        });

        Schema::table('offline_scanner_imported_logs', function (Blueprint $table): void {
            // Preserve FK index coverage during reversal too. Existing duplicate
            // conflicts still require review before attempting this reversal.
            $table->unique(['offline_scanner_session_id', 'log_uuid'], 'offline_logs_session_log_uuid_unique');
            $table->unique(['offline_scanner_session_id', 'request_uuid'], 'offline_logs_session_request_uuid_unique');
            $table->unique(['offline_scanner_session_id', 'master_sequence_number'], 'offline_logs_session_master_sequence_unique');
            $table->unique(
                ['offline_scanner_session_id', 'source_device_id', 'monotonic_sequence'],
                'offline_logs_session_device_monotonic_unique'
            );

            $table->dropIndex('offline_logs_session_log_uuid_index');
            $table->dropIndex('offline_logs_session_request_uuid_index');
            $table->dropIndex('offline_logs_session_master_sequence_index');
            $table->dropIndex('offline_logs_session_device_monotonic_index');
        });

        Schema::table('offline_scanner_package_guest_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['pass_type', 'guest_status', 'manual_code_hash', 'qr_token_hash']);
        });

        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->dropColumn(['encrypted_file_path', 'encrypted_file_size']);
        });
    }
};
