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
        Schema::dropIfExists('feed_post_comments');

        Schema::create('feed_post_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feed_post_id');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('comment');
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->index(['feed_post_id', 'parent_id', 'created_at']);
            $table->index('user_id');

            $table->foreign('feed_post_id')->references('id')->on('feed_posts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_post_comments');
    }
};
