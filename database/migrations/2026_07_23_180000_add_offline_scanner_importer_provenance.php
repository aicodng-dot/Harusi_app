<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_scanner_package_guest_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_scanner_session_id');
            $table->unsignedBigInteger('offline_scanner_package_id');
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('external_guest_id', 191);
            $table->string('record_version', 128);
            $table->unsignedTinyInteger('allowed_entries');
            $table->unsignedTinyInteger('used_entries');
            $table->unsignedTinyInteger('remaining_entries');
            $table->timestamps();

            $table->unique(['offline_scanner_session_id', 'external_guest_id'], 'os_package_snapshot_session_guest_unique');
            $table->foreign('offline_scanner_session_id', 'os_pgs_session_fk')->references('id')->on('offline_scanner_sessions')->restrictOnDelete();
            $table->foreign('offline_scanner_package_id', 'os_pgs_package_fk')->references('id')->on('offline_scanner_packages')->restrictOnDelete();
            $table->foreign('guest_id', 'os_pgs_guest_fk')->references('id')->on('guests')->nullOnDelete();
        });

        Schema::table('checkins', function (Blueprint $table): void {
            $table->unsignedBigInteger('offline_scanner_imported_log_id')->nullable()->after('event_id');
            $table->uuid('offline_log_uuid')->nullable()->after('offline_scanner_imported_log_id');
            $table->uuid('offline_request_uuid')->nullable()->after('offline_log_uuid');
            $table->string('offline_device_id', 191)->nullable()->after('offline_request_uuid');
            $table->string('offline_admission_method', 40)->nullable()->after('offline_device_id');
            $table->unsignedBigInteger('offline_master_sequence_number')->nullable()->after('offline_admission_method');
            $table->unique('offline_scanner_imported_log_id', 'checkins_offline_imported_log_unique');
            $table->foreign('offline_scanner_imported_log_id', 'checkins_offline_imported_log_fk')
                ->references('id')->on('offline_scanner_imported_logs')->nullOnDelete();
        });

        Schema::table('admissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('offline_scanner_imported_log_id')->nullable()->after('event_id');
            $table->unique('offline_scanner_imported_log_id', 'admissions_offline_imported_log_unique');
            $table->foreign('offline_scanner_imported_log_id', 'admissions_offline_imported_log_fk')
                ->references('id')->on('offline_scanner_imported_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropForeign('admissions_offline_imported_log_fk');
            $table->dropUnique('admissions_offline_imported_log_unique');
            $table->dropColumn('offline_scanner_imported_log_id');
        });

        Schema::table('checkins', function (Blueprint $table): void {
            $table->dropForeign('checkins_offline_imported_log_fk');
            $table->dropUnique('checkins_offline_imported_log_unique');
            $table->dropColumn([
                'offline_scanner_imported_log_id',
                'offline_log_uuid',
                'offline_request_uuid',
                'offline_device_id',
                'offline_admission_method',
                'offline_master_sequence_number',
            ]);
        });

        Schema::dropIfExists('offline_scanner_package_guest_snapshots');
    }
};
