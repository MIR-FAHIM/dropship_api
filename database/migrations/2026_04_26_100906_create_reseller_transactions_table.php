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
        Schema::create('reseller_transactions', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('ref_id')->nullable();
            $table->string('trx_id')->nullable();
            $table->enum('trx_type', ['credit', 'debit']);
            $table->enum('source', ['cod', 'online_payment', 'wallet'])->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_transactions');
    }
};
