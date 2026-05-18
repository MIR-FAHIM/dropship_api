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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('support_type')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('image_id')->nullable();
            $table->string('status')->default('open');
            $table->boolean('is_active')->default(true);
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
