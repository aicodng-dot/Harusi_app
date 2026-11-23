<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working Flow 10 — Event Details. Additive, nullable columns only, so every legacy
 * event row stays valid unchanged:
 *  - event_type: the customer's Choose Event Type selection (wedding, send_off,
 *    kitchen_party, other); null for legacy and administrator-created events.
 *  - event_time: wall-clock start time paired with the existing event_date.
 *  - creation_key: SHA-256 of the customer's one-time request key; the unique index
 *    makes repeated or concurrent submissions resolve to one event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (! Schema::hasColumn('events', 'event_type')) {
                $table->string('event_type', 32)->nullable();
            }
            if (! Schema::hasColumn('events', 'event_time')) {
                $table->time('event_time')->nullable();
            }
            if (! Schema::hasColumn('events', 'creation_key')) {
                $table->char('creation_key', 64)->nullable()->unique('events_creation_key_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (Schema::hasColumn('events', 'creation_key')) {
                $table->dropUnique('events_creation_key_unique');
                $table->dropColumn('creation_key');
            }
            foreach (['event_time', 'event_type'] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
