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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // Multivendor tracking (who will fulfill this item)
            $table->foreignId('shop_id')
                ->nullable()
                ->constrained('shops')
                ->nullOnDelete();

            // Product snapshot (so history survives product edits/deletes)
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();

            // Pricing snapshot (order-time)
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('reseller_price', 12, 2)->nullable();
            $table->integer('qty')->nullable();
            $table->decimal('line_total', 12, 2)->nullable(); // qty * unit_price
            $table->decimal('line_total_reseller_profit', 12, 2)->nullable(); // qty * reseller_price

            // Item-level status (supports partial shipping/refund)
            $table->string('status')->nullable();
            // pending, confirmed, packed, shipped, delivered, cancelled, refunded, returned

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
