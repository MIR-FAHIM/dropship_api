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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')
                ->nullable()
                ->constrained('carts')
                ->nullOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // Multivendor requirement (who sells this item)
            $table->foreignId('shop_id')
                ->nullable()
                ->constrained('shops')
                ->nullOnDelete();

            // Quantity and pricing snapshot (cart-time)
            $table->integer('qty')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable(); // qty * unit_price (cached)

            // Optional control
            $table->string('status')->nullable(); 
            // active, saved_for_later, removed

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
