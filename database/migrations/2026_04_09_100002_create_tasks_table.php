<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->string('task_title')->nullable();
            $table->text('task_details')->nullable();
            $table->boolean('is_active')->default(true);

            $table->integer('priority_id')->nullable();
            $table->integer('task_type_id')->nullable();

            $table->boolean('is_remind')->default(false);
            $table->boolean('is_waiting')->default(false);

            $table->date('due_date')->nullable();
            $table->date('start_date')->nullable();

            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('project_phase_id')->nullable();
            $table->unsignedBigInteger('prospect_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->decimal('completion_percentage', 5, 2)->default(0);
            $table->boolean('show_completion_percentage')->default(false);
           $table->string('task_link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
