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
        Schema::create('social_media_text_contents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('title', 255)->nullable();
            $table->string('platform', 100)->nullable()->default('general');
            $table->longText('content')->nullable();
            $table->longText('css_styles')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('product_id');
            $table->index('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_media_text_contents');
    }
};
