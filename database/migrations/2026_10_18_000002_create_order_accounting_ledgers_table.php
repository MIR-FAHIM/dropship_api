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
        if (!Schema::hasTable('order_accounting_ledgers')) {
            Schema::create('order_accounting_ledgers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('entry_type', 50); // booking, delivery_completed, courier_payout, vendor_payout, reseller_profit, advance_deduction, reversal
                $table->string('account_code', 50); // customer_receivable, courier_clearing, cash_platform, vendor_payable, reseller_payable, platform_margin_product, platform_margin_shipping, courier_cost_expense
                $table->unsignedBigInteger('party_id')->nullable(); // user_id or vendor_id
                $table->string('party_type', 30)->default('platform'); // platform, vendor, reseller, courier, customer
                $table->decimal('debit', 12, 2)->default(0.00);
                $table->decimal('credit', 12, 2)->default(0.00);
                $table->string('currency', 10)->default('BDT');
                $table->string('reference_id', 100)->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index(['order_id', 'account_code']);
                $table->index(['party_type', 'party_id']);
                $table->index('entry_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_accounting_ledgers');
    }
};
