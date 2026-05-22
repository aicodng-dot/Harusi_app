<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('guests', 'token_hash')) {
            try {
                Schema::table('guests', function (Blueprint $table) {
                    $table->dropUnique('guests_token_hash_unique');
                });
            } catch (\Throwable) {
                //
            }
        }

        if (Schema::hasColumn('guests', 'token_preview')) {
            try {
                Schema::table('guests', function (Blueprint $table) {
                    $table->dropIndex('guests_token_preview_index');
                });
            } catch (\Throwable) {
                //
            }
        }

        $legacyColumns = array_values(array_filter([
            'token_hash',
            'token_encrypted',
            'token_preview',
            'generated_at',
            'cancelled_at',
            'revoked_at',
            'admin_note',
        ], fn (string $column) => Schema::hasColumn('guests', $column)));

        if ($legacyColumns !== []) {
            Schema::table('guests', function (Blueprint $table) use ($legacyColumns) {
                $table->dropColumn($legacyColumns);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            if (! Schema::hasColumn('guests', 'token_hash')) {
                $table->string('token_hash', 64)->nullable()->unique();
            }

            if (! Schema::hasColumn('guests', 'token_encrypted')) {
                $table->text('token_encrypted')->nullable();
            }

            if (! Schema::hasColumn('guests', 'token_preview')) {
                $table->string('token_preview', 12)->nullable()->index();
            }

            if (! Schema::hasColumn('guests', 'generated_at')) {
                $table->timestamp('generated_at')->nullable();
            }

            if (! Schema::hasColumn('guests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }

            if (! Schema::hasColumn('guests', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable();
            }

            if (! Schema::hasColumn('guests', 'admin_note')) {
                $table->text('admin_note')->nullable();
            }
        });
    }
};
