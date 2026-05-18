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
        Schema::create('carry_bee_order_create_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->string('store_id');
            $table->string('merchant_order_id');
            $table->integer('delivery_type');
            $table->integer('product_type');
            $table->string('recipient_phone', 50);
            $table->string('recipient_secendary_phone', 50)->nullable();
            $table->string('recipient_name');
            $table->string('recipient_address', 500);
            $table->integer('city_id');
            $table->integer('zone_id');
            $table->integer('area_id');
            $table->text('special_instruction')->nullable();
            $table->text('product_description')->nullable();
            $table->decimal('item_weight', 8, 2);
            $table->integer('item_quantity');
            $table->decimal('collectable_amount', 10, 2);
            $table->boolean('is_closed_box')->nullable();
            $table->boolean('is_exchange')->nullable();
            // Own fields
            $table->unsignedBigInteger('own_vendor_id')->nullable();
            $table->unsignedBigInteger('own_created_by')->nullable();
            $table->string('own_admin_status')->nullable();
            $table->boolean('own_is_vendor_ready')->default(false);
            $table->text('own_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carry_bee_order_create_forms');
    }
};
