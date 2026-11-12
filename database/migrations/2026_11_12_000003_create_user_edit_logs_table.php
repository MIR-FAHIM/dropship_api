<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_edit_logs', function (Blueprint $table) {
            $table->id();
            // Keep audit history if the dropshipper or admin account is deleted.
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('edited_by')->index();
            $table->json('previous_values');
            $table->json('new_values');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_edit_logs');
    }
};
