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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Customer
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Public reference
            $table->string('order_number')->nullable()->unique();

            // Order state
            $table->string('status')->nullable(); 
            // pending, confirmed, processing, shipped, delivered, cancelled

            // Payment state
            $table->string('payment_status')->nullable(); 
            // unpaid, paid, failed, refunded

            // Address snapshot (VERY IMPORTANT)
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('shipping_address')->nullable();

            $table->string('zone')->nullable();
            $table->string('district')->nullable();
            $table->string('area')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();

            // Financial snapshot
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('shipping_fee', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();

            // Meta
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
