<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->unsignedBigInteger('event_id')->nullable()->after('offline_scanner_session_id');
            $table->uuid('source_package_export_id')->nullable()->after('source_results_export_id')->index();
            $table->uuid('results_export_id')->nullable()->after('source_package_export_id');
            $table->string('result_schema_version', 32)->nullable()->after('results_export_id');
            $table->string('encryption_algorithm', 120)->nullable()->after('source_master_device_id');
            $table->text('initialization_vector')->nullable()->after('encryption_algorithm');
            $table->text('authentication_tag')->nullable()->after('initialization_vector');
            $table->string('payload_hash', 128)->nullable()->after('authentication_tag')->index();
            $table->unsignedBigInteger('final_master_sequence_number')->nullable()->after('payload_hash');
            $table->json('metrics')->nullable()->after('final_master_sequence_number');
            $table->timestamp('exported_at')->nullable()->after('metrics');
            $table->unique('results_export_id', 'os_result_imports_export_uuid_unique');
            $table->foreign('event_id', 'os_ri_event_fk')->references('id')->on('events')->nullOnDelete();
        });

        Schema::table('offline_scanner_imported_logs', function (Blueprint $table): void {
            $table->string('qr_payload_hash', 128)->nullable()->after('admission_method');
            $table->unsignedBigInteger('monotonic_sequence')->nullable()->after('master_timestamp');
            $table->string('base_guest_record_version', 128)->nullable()->after('monotonic_sequence');
            $table->unique(
                ['offline_scanner_session_id', 'source_device_id', 'monotonic_sequence'],
                'offline_logs_session_device_monotonic_unique'
            );
        });

        Schema::create('offline_scanner_result_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_result_import_id');
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->string('device_id', 191);
            $table->string('device_name', 191);
            $table->enum('device_role', ['master', 'client'])->default('client')->index();
            $table->unsignedBigInteger('scanner_user_id')->nullable();
            $table->string('scanner_user_name', 191)->nullable();
            $table->string('gate_name', 120)->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('admissions_count')->default(0);
            $table->unique(['offline_scanner_result_import_id', 'device_id'], 'os_result_devices_import_device_unique');
            $table->foreign('offline_scanner_result_import_id', 'os_rd_import_fk')->references('id')->on('offline_scanner_result_imports')->restrictOnDelete();
            $table->foreign('offline_scanner_session_id', 'os_rd_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('scanner_user_id', 'os_rd_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offline_scanner_final_guest_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_result_import_id');
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->unsignedBigInteger('event_id');
            $table->string('external_guest_id', 191);
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedInteger('final_used');
            $table->unsignedInteger('final_remaining');
            $table->string('final_record_version', 128);
            $table->unsignedBigInteger('last_master_sequence_number');
            $table->unique(['offline_scanner_result_import_id', 'external_guest_id'], 'os_final_states_import_guest_unique');
            $table->foreign('offline_scanner_result_import_id', 'os_fgs_import_fk')->references('id')->on('offline_scanner_result_imports')->restrictOnDelete();
            $table->foreign('offline_scanner_session_id', 'os_fgs_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('event_id', 'os_fgs_event_fk')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('guest_id', 'os_fgs_guest_fk')->references('id')->on('guests')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_scanner_final_guest_states');
        Schema::dropIfExists('offline_scanner_result_devices');

        Schema::table('offline_scanner_imported_logs', function (Blueprint $table): void {
            $table->dropUnique('offline_logs_session_device_monotonic_unique');
            $table->dropColumn(['qr_payload_hash', 'monotonic_sequence', 'base_guest_record_version']);
        });

        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->dropForeign('os_ri_event_fk');
            $table->dropUnique('os_result_imports_export_uuid_unique');
            $table->dropColumn([
                'event_id',
                'source_package_export_id',
                'results_export_id',
                'result_schema_version',
                'encryption_algorithm',
                'initialization_vector',
                'authentication_tag',
                'payload_hash',
                'final_master_sequence_number',
                'metrics',
                'exported_at',
            ]);
        });
    }
};