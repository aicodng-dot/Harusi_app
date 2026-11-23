<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working Flow 14 — Choose Invitation Template. One additive, nullable column holding a
 * stable identifier from App\Services\Events\InvitationTemplates; legacy rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'invitation_template')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->string('invitation_template', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'invitation_template')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->dropColumn('invitation_template');
            });
        }
    }
};
