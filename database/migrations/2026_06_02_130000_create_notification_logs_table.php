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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 30)->index();
            $table->string('message_type', 50)->index();
            $table->string('template_name', 120)->nullable();
            $table->string('recipient_phone', 20);
            $table->text('message')->nullable();
            $table->string('status', 30)->index();
            $table->string('provider', 30)->nullable()->index();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->index(['event_id', 'channel', 'status']);
            $table->index(['guest_id', 'message_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
