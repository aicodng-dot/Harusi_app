<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('qr_codes')) {
            Schema::create('qr_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
                $table->string('qr_token', 128)->unique();
                $table->string('qr_image_path')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        $legacyQrRows = [];
        if (Schema::hasColumn('guests', 'token_hash')) {
            $legacyQrRows = DB::table('guests')
                ->select('id', 'token_hash', 'generated_at', 'revoked_at')
                ->whereNotNull('token_hash')
                ->get()
                ->map(fn ($guest) => [
                    'guest_id' => $guest->id,
                    'qr_token' => $guest->token_hash ?: Str::random(64),
                    'qr_image_path' => null,
                    'is_active' => empty($guest->revoked_at),
                    'generated_at' => $guest->generated_at,
                    'revoked_at' => $guest->revoked_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();
        }

        Schema::table('guests', function (Blueprint $table) {
            if (Schema::hasColumn('guests', 'guest_name') && ! Schema::hasColumn('guests', 'name')) {
                $table->renameColumn('guest_name', 'name');
            }

            if (Schema::hasColumn('guests', 'allowed_admissions') && ! Schema::hasColumn('guests', 'allowed_entries')) {
                $table->renameColumn('allowed_admissions', 'allowed_entries');
            }

            if (Schema::hasColumn('guests', 'admitted_count') && ! Schema::hasColumn('guests', 'used_entries')) {
                $table->renameColumn('admitted_count', 'used_entries');
            }
        });

        DB::table('guests')
            ->whereNotIn('status', ['active', 'partially_used', 'fully_used', 'cancelled'])
            ->update(['status' => 'cancelled']);

        DB::table('guests')
            ->where('status', '!=', 'cancelled')
            ->where('used_entries', 0)
            ->update(['status' => 'active']);

        DB::table('guests')
            ->where('status', '!=', 'cancelled')
            ->where('used_entries', '>', 0)
            ->whereRaw('used_entries < allowed_entries')
            ->update(['status' => 'partially_used']);

        DB::table('guests')
            ->where('status', '!=', 'cancelled')
            ->whereRaw('used_entries >= allowed_entries')
            ->update(['status' => 'fully_used']);

        if ($legacyQrRows !== []) {
            foreach ($legacyQrRows as $row) {
                if (! DB::table('qr_codes')->where('guest_id', $row['guest_id'])->exists()) {
                    DB::table('qr_codes')->insert($row);
                }
            }
        }

        if (! Schema::hasTable('checkins')) {
            Schema::create('checkins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('qr_code_id')->nullable()->constrained('qr_codes')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('gate_name')->nullable();
                $table->unsignedTinyInteger('entries_added')->default(0);
                $table->unsignedTinyInteger('used_entries_after_scan')->default(0);
                $table->unsignedTinyInteger('remaining_entries_after_scan')->default(0);
                $table->string('scan_result', 30)->index();
                $table->text('device_info')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('checked_in_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkins');
        Schema::dropIfExists('qr_codes');
    }
};
