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
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); // id (AUTO_INCREMENT)

            $table->unsignedBigInteger('parent_id')->nullable()->default(0);
            $table->integer('level')->default(0);

            $table->string('name', 50);

            $table->integer('order_level')->default(0);

            $table->double('commision_rate', 8, 2)->default(0.00);

            $table->string('banner', 100)->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('cover_image', 100)->nullable();

            $table->integer('featured')->default(0);
            $table->integer('top')->default(0);
            $table->integer('digital')->default(0);

            $table->string('slug', 255)->nullable()->index();
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();

            // timestamps with MySQL behavior equivalent
            $table->timestamp('created_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('updated_at')->nullable()->useCurrent();

            // Optional but recommended indexes for hierarchy queries
            $table->index('parent_id');
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
