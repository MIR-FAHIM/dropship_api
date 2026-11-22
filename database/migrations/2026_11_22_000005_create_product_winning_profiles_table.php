<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner Portal F6: "winning" products for marketers, with ad hook, creative angle and an
 * optional local-copy vs verified-original photo comparison. Un-marking keeps the content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_winning_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->boolean('is_winning')->default(true)->index();
            $table->text('hook')->nullable();            // 🎯 ad hook
            $table->text('ad_angle')->nullable();        // 🎬 creative angle
            $table->text('creative_tip')->nullable();    // 💡 visual guide when there are no photos
            $table->string('local_image', 500)->nullable();      // ❌ local copy
            $table->string('local_caption', 255)->nullable();
            $table->string('original_image', 500)->nullable();   // ✅ verified original
            $table->string('original_caption', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_winning_profiles');
    }
};
