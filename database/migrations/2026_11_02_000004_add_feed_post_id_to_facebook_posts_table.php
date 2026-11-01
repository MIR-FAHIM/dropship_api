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
        if (Schema::hasTable('facebook_posts')) {
            Schema::table('facebook_posts', function (Blueprint $table) {
                if (!Schema::hasColumn('facebook_posts', 'feed_post_id')) {
                    $table->foreignId('feed_post_id')->nullable()->after('id')->constrained('feed_posts')->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('facebook_posts')) {
            Schema::table('facebook_posts', function (Blueprint $table) {
                if (Schema::hasColumn('facebook_posts', 'feed_post_id')) {
                    $table->dropForeign(['feed_post_id']);
                    $table->dropColumn('feed_post_id');
                }
            });
        }
    }
};
