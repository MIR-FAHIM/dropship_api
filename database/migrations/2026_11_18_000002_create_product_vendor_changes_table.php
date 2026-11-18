<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit trail for moving a product to another vendor (admin product page → Change vendor).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_vendor_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('from_vendor_id')->nullable();
            $table->unsignedBigInteger('to_vendor_id');
            $table->string('old_sku')->nullable();
            $table->string('new_sku')->nullable();
            $table->unsignedInteger('cart_items_moved')->default(0);
            $table->unsignedInteger('open_orders_left')->default(0); // open orders that stay with the old vendor
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_vendor_changes');
    }
};
