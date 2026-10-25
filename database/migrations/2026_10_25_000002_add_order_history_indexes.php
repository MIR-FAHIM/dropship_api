<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['product_id', 'order_id'], 'order_items_history_product_order_idx');
            $table->index(['shop_id', 'product_id', 'order_id'], 'order_items_history_shop_product_order_idx');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'orders_history_date_id_idx');
            $table->index(['status', 'created_at'], 'orders_history_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_history_product_order_idx');
            $table->dropIndex('order_items_history_shop_product_order_idx');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_history_date_id_idx');
            $table->dropIndex('orders_history_status_date_idx');
        });
    }
};
