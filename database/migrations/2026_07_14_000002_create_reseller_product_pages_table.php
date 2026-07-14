<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_product_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('product_id');
            $table->string('slug')->unique();
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('discount_price', 12, 2)->nullable();
            $table->string('custom_title')->nullable();
            $table->longText('custom_description')->nullable();
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('published_status')->default('draft');
            $table->timestamps();

            $table->unique(['reseller_id', 'product_id'], 'reseller_product_pages_unique_product');
            $table->index('reseller_id');
            $table->index('product_id');
            $table->index('published_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_product_pages');
    }
};
