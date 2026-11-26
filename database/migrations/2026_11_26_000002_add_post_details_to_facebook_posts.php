<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Facebook posts made from a product: who posted, caption, photo count, schedule and Facebook's error. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_posts', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
            if (!Schema::hasColumn('facebook_posts', 'caption')) {
                $table->text('caption')->nullable();
            }
            if (!Schema::hasColumn('facebook_posts', 'image_count')) {
                $table->unsignedSmallInteger('image_count')->default(0);
            }
            if (!Schema::hasColumn('facebook_posts', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable();
            }
            if (!Schema::hasColumn('facebook_posts', 'error')) {
                $table->string('error', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            foreach (['user_id', 'caption', 'image_count', 'scheduled_at', 'error'] as $c) {
                if (Schema::hasColumn('facebook_posts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
