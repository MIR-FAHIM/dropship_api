<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Dropshipper list / details count clicks per user, and the click leaderboard
    // groups by clicked_by with a date range: both need this index.
    public function up(): void
    {
        Schema::table('product_clicks', function (Blueprint $table) {
            $table->index(['clicked_by', 'created_at'], 'product_clicks_clicked_by_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_clicks', function (Blueprint $table) {
            $table->dropIndex('product_clicks_clicked_by_created_at_index');
        });
    }
};
