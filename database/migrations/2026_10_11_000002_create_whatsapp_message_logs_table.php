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
        Schema::dropIfExists('whatsapp_message_logs');

        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 20)->default('outbound'); // outbound, inbound
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->string('phone_number', 50)->index();
            $table->string('message_type', 50)->default('template'); // template, text, interactive, media
            $table->string('template_name', 100)->nullable();
            $table->string('template_language', 20)->nullable();
            $table->text('message_content')->nullable();
            $table->json('parameters')->nullable();
            $table->string('provider_message_id', 150)->nullable()->index(); // Meta wamid
            $table->string('status', 50)->default('pending')->index(); // pending, processing, sent, delivered, read, received, failed
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->json('raw_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'template_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
