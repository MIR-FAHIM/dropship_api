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
        Schema::create('price_update_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id');
            $table->decimal('before_price', 12, 2);
            $table->decimal('new_price', 12, 2);
            $table->foreignId('updated_by');
            $table->string('status', 50)->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_update_logs');
    }
};
