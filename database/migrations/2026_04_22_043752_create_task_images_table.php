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
        Schema::create('task_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('added_by');
            $table->unsignedBigInteger('task_image_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('type')->nullable();
            $table->timestamps();
            // Foreign key constraints (optional, uncomment if needed)
            // $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            // $table->foreign('added_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_images');
    }
};
