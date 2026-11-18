<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The admin product list counts clicks per product (withCount('clicks')) and the
    // click leaderboard groups by product_id: both need this index.
    public function up(): void
    {
        Schema::table('product_clicks', function (Blueprint $table) {
            $table->index('product_id', 'product_clicks_product_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_clicks', function (Blueprint $table) {
            $table->dropIndex('product_clicks_product_id_index');
        });
    }
};
