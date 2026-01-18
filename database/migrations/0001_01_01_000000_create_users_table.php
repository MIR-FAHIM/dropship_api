<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Core identity
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Auth (keep if you use Laravel auth)
            $table->string('password')->nullable();
            $table->rememberToken();

            // Contact + profile (based on your API fields)
            $table->string('mobile', 30)->nullable()->index();
            $table->string('optional_phone', 30)->nullable();
            $table->text('address')->nullable();

            // App/device
            $table->text('fcm_token')->nullable();

            // Account state
            $table->boolean('is_banned')->default(false)->index();
            $table->string('role')->default('customer')->index(); // customer, seller, admin, etc.
            $table->string('status')->nullable()->index();

            // Location
            $table->string('zone')->nullable()->index();
            $table->string('district')->nullable()->index();
            $table->string('area')->nullable()->index();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();

            // Timestamps
            $table->timestamps();

            // Soft delete (your API shows deleted_at)
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
