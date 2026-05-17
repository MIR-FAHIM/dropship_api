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
        Schema::create('user_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('bank_name');
            $table->string('acc_name')->nullable();
            $table->string('type')->nullable();
            $table->string('account_no');
            $table->string('branch')->nullable();
            $table->string('route')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->timestamps();

           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_bank_accounts');
    }
};
