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
        Schema::create('carrybee_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('event', 100);
            $table->string('store_id', 100)->nullable();
            $table->string('consignment_id', 100)->nullable();
            $table->string('merchant_order_id', 100)->nullable();
            $table->timestamp('event_time')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('mapped_status_id')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status', 30)->default('received');
            $table->unsignedInteger('attempts')->default(1);
            $table->text('message')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('event');
            $table->index('consignment_id');
            $table->index('merchant_order_id');
            $table->index('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrybee_webhook_events');
    }
};
