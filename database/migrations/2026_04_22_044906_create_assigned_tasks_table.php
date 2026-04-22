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
        Schema::create('assigned_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('added_by');
            $table->unsignedBigInteger('assign_to');
            $table->text('note')->nullable();
            $table->timestamps();

            // Foreign key constraints (optional, uncomment if needed)
            // $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            // $table->foreign('added_by')->references('id')->on('users')->onDelete('set null');
            // $table->foreign('assign_to')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assigned_tasks');
    }
};
