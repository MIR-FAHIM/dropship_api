<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('orders', ['user_id'])) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('user_id', 'orders_dropshipper_user_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', 'orders_dropshipper_user_id_idx')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_dropshipper_user_id_idx');
            });
        }
    }
};
