<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->timestamp('result_session_started_at')->nullable()->after('exported_at');
            $table->timestamp('result_session_closed_at')->nullable()->after('result_session_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('offline_scanner_result_imports', function (Blueprint $table): void {
            $table->dropColumn(['result_session_started_at', 'result_session_closed_at']);
        });
    }
};