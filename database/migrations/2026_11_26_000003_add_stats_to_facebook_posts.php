<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Facebook connect phase 3: post history with likes / comments / shares and the Facebook link. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            foreach (['likes_count', 'comments_count', 'shares_count'] as $c) {
                if (!Schema::hasColumn('facebook_posts', $c)) {
                    $table->unsignedInteger($c)->default(0);
                }
            }
            if (!Schema::hasColumn('facebook_posts', 'permalink')) {
                $table->text('permalink')->nullable();
            }
            if (!Schema::hasColumn('facebook_posts', 'published_at')) {
                $table->timestamp('published_at')->nullable();
            }
            if (!Schema::hasColumn('facebook_posts', 'stats_updated_at')) {
                $table->timestamp('stats_updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            foreach (['likes_count', 'comments_count', 'shares_count', 'permalink', 'published_at', 'stats_updated_at'] as $c) {
                if (Schema::hasColumn('facebook_posts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
