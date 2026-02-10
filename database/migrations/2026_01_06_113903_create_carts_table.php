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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('user_id')
                ->nullable();
       

            // State
            $table->string('status')->nullable(); 
            // active, checked_out, abandoned

            // Cached values (recomputable)
            $table->integer('total_items')->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('reseller_profit_total', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
