<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->id();
                $table->string('event_name', 160);
                $table->string('bride_name', 120)->nullable();
                $table->string('groom_name', 120)->nullable();
                $table->string('venue_name', 160)->nullable();
                $table->date('event_date')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        $defaultEventId = DB::table('events')->where('event_name', 'Default Wedding Event')->value('id');

        if (! $defaultEventId) {
            $defaultEventId = DB::table('events')->insertGetId([
                'event_name' => 'Default Wedding Event',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('guests') && ! Schema::hasColumn('guests', 'event_id')) {
            Schema::table('guests', function (Blueprint $table) use ($defaultEventId): void {
                $table->unsignedBigInteger('event_id')->default($defaultEventId)->index()->after('id');
            });

            DB::table('guests')->whereNull('event_id')->update(['event_id' => $defaultEventId]);
        }

        if (Schema::hasTable('checkins') && ! Schema::hasColumn('checkins', 'event_id')) {
            Schema::table('checkins', function (Blueprint $table) use ($defaultEventId): void {
                $table->unsignedBigInteger('event_id')->default($defaultEventId)->index()->after('id');
            });

            DB::table('checkins')->whereNull('event_id')->update(['event_id' => $defaultEventId]);
        }

        if (Schema::hasTable('admissions') && ! Schema::hasColumn('admissions', 'event_id')) {
            Schema::table('admissions', function (Blueprint $table) use ($defaultEventId): void {
                $table->unsignedBigInteger('event_id')->default($defaultEventId)->index()->after('id');
            });

            DB::table('admissions')->whereNull('event_id')->update(['event_id' => $defaultEventId]);
        }

        if (Schema::hasTable('scan_logs') && ! Schema::hasColumn('scan_logs', 'event_id')) {
            Schema::table('scan_logs', function (Blueprint $table) use ($defaultEventId): void {
                $table->unsignedBigInteger('event_id')->default($defaultEventId)->index()->after('id');
            });

            DB::table('scan_logs')->whereNull('event_id')->update(['event_id' => $defaultEventId]);
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'event_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('event_id')->nullable()->index()->after('role');
            });

            DB::table('users')
                ->where('role', 'scanner')
                ->whereNull('event_id')
                ->update(['event_id' => $defaultEventId]);
        }
    }

    public function down(): void
    {
        foreach (['users', 'scan_logs', 'admissions', 'checkins', 'guests'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'event_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    try {
                        $table->dropIndex($tableName.'_event_id_index');
                    } catch (Throwable) {
                        //
                    }

                    $table->dropColumn('event_id');
                });
            }
        }

        Schema::dropIfExists('events');
    }
};
