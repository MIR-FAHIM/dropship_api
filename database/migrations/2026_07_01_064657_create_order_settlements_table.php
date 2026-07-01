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
        Schema::create('order_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('payable_user_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('user_type', 30);
            $table->string('settlement_type', 50);
            $table->decimal('settleable_amount', 12, 2)->default(0);
            $table->string('currency', 10)->default('BDT');
            $table->string('status', 30)->default('pending');
            $table->text('admin_note')->nullable();
            $table->string('trx_id')->nullable()->unique();
            $table->string('settled_trx_id')->nullable()->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'user_type']);
            $table->index(['settlement_type', 'status']);
            $table->index('payable_user_id');
            $table->index('vendor_id');
            $table->unique(['order_id', 'settlement_type', 'payable_user_id'], 'order_settlements_unique_payable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_settlements');
    }
};
