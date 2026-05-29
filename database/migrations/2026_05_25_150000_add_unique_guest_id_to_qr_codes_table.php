<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforce the app rule that one guest can only have one current QR row.
     */
    public function up(): void
    {
        if (! Schema::hasTable('qr_codes')) {
            return;
        }

        DB::table('qr_codes')
            ->select('guest_id')
            ->groupBy('guest_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('guest_id')
            ->each(function ($guestId): void {
                $keepId = DB::table('qr_codes')
                    ->where('guest_id', $guestId)
                    ->orderByDesc('generated_at')
                    ->orderByDesc('id')
                    ->value('id');

                DB::table('qr_codes')
                    ->where('guest_id', $guestId)
                    ->where('id', '<>', $keepId)
                    ->delete();
            });

        Schema::table('qr_codes', function (Blueprint $table): void {
            $table->unique('guest_id', 'qr_codes_guest_id_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('qr_codes')) {
            return;
        }

        Schema::table('qr_codes', function (Blueprint $table): void {
            $table->dropUnique('qr_codes_guest_id_unique');
        });
    }
};
