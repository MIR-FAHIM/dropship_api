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
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('attribute_value_id');

            // Business fields
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes & constraints
            $table->unique(
                ['product_id', 'attribute_id', 'attribute_value_id'],
                'product_attr_unique'
            );

            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->foreign('attribute_id')
                ->references('id')->on('attributes')
                ->onDelete('cascade');

            $table->foreign('attribute_value_id')
                ->references('id')->on('attribute_values')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
