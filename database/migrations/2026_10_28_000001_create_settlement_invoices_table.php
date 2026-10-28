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
        if (!Schema::hasTable('settlement_invoices')) {
            Schema::create('settlement_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_number', 50)->unique();
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index(); // Created by admin
                $table->string('type', 30)->default('vendor_settlement'); // vendor_settlement, dropshipper_payout
                $table->integer('orders_count')->default(1);
                $table->decimal('total_collectable', 14, 2)->default(0.00);
                $table->decimal('total_delivery_fee', 14, 2)->default(0.00);
                $table->decimal('total_cod_fee', 14, 2)->default(0.00);
                $table->decimal('total_courier_net', 14, 2)->default(0.00);
                $table->decimal('total_supplier_price', 14, 2)->default(0.00);
                $table->decimal('total_amount_owed', 14, 2)->default(0.00); // Vendor owes platform
                $table->decimal('total_dropshipper_payout', 14, 2)->default(0.00);
                $table->decimal('total_platform_net_profit', 14, 2)->default(0.00);
                $table->string('payment_method', 50)->default('bank');
                $table->string('trx_id', 100)->nullable()->index();
                $table->string('status', 20)->default('settled'); // pending, settled, cancelled
                $table->json('order_ids')->nullable();
                $table->json('orders_breakdown')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_invoices');
    }
};
