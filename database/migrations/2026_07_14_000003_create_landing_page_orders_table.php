<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_product_page_id');
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('division_id')->nullable();
            $table->unsignedBigInteger('upozella_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->boolean('is_outside_dhaka')->default(false);
            $table->string('source')->nullable();
            $table->string('tracking_code')->nullable()->unique();
            $table->timestamp('passed_at')->nullable();
            $table->timestamps();

            $table->index('reseller_product_page_id');
            $table->index('reseller_id');
            $table->index('product_id');
            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_orders');
    }
};
