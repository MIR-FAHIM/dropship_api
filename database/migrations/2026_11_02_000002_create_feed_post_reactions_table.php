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
        Schema::dropIfExists('feed_post_reactions');

        Schema::create('feed_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feed_post_id');
            $table->unsignedInteger('user_id');
            $table->string('type', 20)->default('like');
            $table->timestamps();

            $table->unique(['feed_post_id', 'user_id']);
            $table->index(['feed_post_id', 'type']);
            $table->index('user_id');

            $table->foreign('feed_post_id')->references('id')->on('feed_posts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_post_reactions');
    }
};
