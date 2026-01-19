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
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            $table->string('banner_name');
            $table->string('title')->nullable();

            $table->foreignId('related_product_id')
                  ->nullable()
                  ->constrained('products')
                  ->nullOnDelete();

            $table->foreignId('related_category_id')
                  ->nullable()
                  ->constrained('categories')
                  ->nullOnDelete();

            $table->string('image_path')->nullable();     // Banner image path or URL
            $table->string('image_id')->nullable();     // Banner image ID or reference
            $table->text('note')->nullable(); // Optional description

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Helpful indexes
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
