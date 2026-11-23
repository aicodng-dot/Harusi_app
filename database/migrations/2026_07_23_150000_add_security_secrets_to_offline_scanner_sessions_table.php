<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_scanner_sessions', function (Blueprint $table): void {
            $table->text('session_bootstrap_secret_encrypted')->nullable()->after('guest_snapshot_version');
            $table->text('result_file_encryption_secret_encrypted')->nullable()->after('session_bootstrap_secret_encrypted');
            $table->text('result_file_authentication_secret_encrypted')->nullable()->after('result_file_encryption_secret_encrypted');
            $table->text('device_pairing_secret_encrypted')->nullable()->after('result_file_authentication_secret_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('offline_scanner_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'session_bootstrap_secret_encrypted',
                'result_file_encryption_secret_encrypted',
                'result_file_authentication_secret_encrypted',
                'device_pairing_secret_encrypted',
            ]);
        });
    }
};