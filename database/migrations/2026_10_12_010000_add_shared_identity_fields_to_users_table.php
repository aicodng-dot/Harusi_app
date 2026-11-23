<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_number', 20)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->string('preferred_language', 2)->default('sw');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'is_active', 'preferred_language']);
        });
    }
};
