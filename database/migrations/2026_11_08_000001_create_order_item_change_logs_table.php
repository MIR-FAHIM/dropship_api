<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_change_logs', function (Blueprint $table) {
            $table->id();
            // Keep historical IDs even if the order, item, or admin is deleted.
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedInteger('admin_id')->index();
            $table->string('action', 32);
            $table->json('previous_values');
            $table->json('new_values');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_change_logs');
    }
};
