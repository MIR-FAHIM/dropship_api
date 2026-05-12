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
        Schema::create('delivery_assigned_infos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->string('consignment_id', 50)->unique();
            $table->string('delivery_company_id', 50)->nullable();
            $table->string('merchant_order_id', 100)->nullable();
            $table->string('recipient_name', 150);
            $table->string('recipient_phone', 20);
            $table->text('recipient_address');
            $table->decimal('collectable_amount', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total_fee', 10, 2)->default(0);
            $table->unsignedInteger('transfer_status_id')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_assigned_infos');
    }
};
