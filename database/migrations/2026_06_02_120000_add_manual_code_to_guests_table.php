<?php

use App\Services\ManualCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('guests')) {
            return;
        }

        $hasEventId = Schema::hasColumn('guests', 'event_id');

        if (! Schema::hasColumn('guests', 'manual_code')) {
            Schema::table('guests', function (Blueprint $table): void {
                $table->string('manual_code', 4)->nullable()->after('phone_number');
            });
        }

        $this->backfillManualCodes($hasEventId);

        Schema::table('guests', function (Blueprint $table) use ($hasEventId): void {
            if ($hasEventId) {
                $table->unique(['event_id', 'manual_code'], 'guests_event_manual_code_unique');
                return;
            }

            $table->unique('manual_code', 'guests_manual_code_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('guests') || ! Schema::hasColumn('guests', 'manual_code')) {
            return;
        }

        Schema::table('guests', function (Blueprint $table): void {
            try {
                $table->dropUnique('guests_event_manual_code_unique');
            } catch (Throwable) {
                //
            }

            try {
                $table->dropUnique('guests_manual_code_unique');
            } catch (Throwable) {
                //
            }

            $table->dropColumn('manual_code');
        });
    }

    private function backfillManualCodes(bool $hasEventId): void
    {
        DB::transaction(function () use ($hasEventId): void {
            $manualCodes = app(ManualCodeService::class);
            $columns = ['id', 'manual_code'];

            if ($hasEventId) {
                $columns[] = 'event_id';
            }

            DB::table('guests')
                ->select($columns)
                ->where(function ($query): void {
                    $query->whereNull('manual_code')
                        ->orWhere('manual_code', '')
                        ->orWhereRaw('LENGTH(manual_code) <> 4');
                })
                ->orderBy('id')
                ->get()
                ->each(function ($guest) use ($manualCodes, $hasEventId): void {
                    $code = $manualCodes->generate($hasEventId ? (int) $guest->event_id : null);

                    DB::table('guests')
                        ->where('id', $guest->id)
                        ->update(['manual_code' => $code]);
                });
        });
    }
};
