<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail of every password change. Never stores the password or its hash.
        Schema::create('password_change_logs', function (Blueprint $table) {
            $table->id();
            // No foreign keys: keep history if the user or the admin account is deleted.
            $table->unsignedBigInteger('user_id')->index();
            // Who made the change: the user themself, an admin, or null for OTP resets.
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            // self_change | admin_reset | otp_reset
            $table->string('change_type', 30)->index();
            $table->string('note', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_change_logs');
    }
};
