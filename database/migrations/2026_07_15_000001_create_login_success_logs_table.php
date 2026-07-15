<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_success_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('user_type', 50)->nullable();
            $table->string('role', 50)->nullable();
            $table->string('login_type', 50)->default('login');
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('token_name')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('method', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('request_data')->nullable();
            $table->timestamp('logged_in_at')->nullable()->useCurrent();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index('user_id');
            $table->index('user_type');
            $table->index('role');
            $table->index('login_type');
            $table->index('logged_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_success_logs');
    }
};
