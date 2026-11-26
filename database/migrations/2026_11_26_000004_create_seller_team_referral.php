<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Passive Income phase 1 — seller team (referral) system.
// A dropshipper becomes a verified seller after 10 delivered orders and gets a seller code.
// New dropshippers can sign up with that code: users.referred_by = the code owner (their upline).
// Every link / unlink / verify is written to seller_team_logs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'seller_code')) {
                $table->string('seller_code', 16)->nullable()->unique();
            }
            if (!Schema::hasColumn('users', 'seller_verified_at')) {
                $table->timestamp('seller_verified_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'referred_at')) {
                $table->timestamp('referred_at')->nullable();
            }
        });

        if (!Schema::hasTable('seller_team_logs')) {
            Schema::create('seller_team_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                // joined | upline_changed | verified | unverified
                $table->string('action', 32);
                $table->unsignedBigInteger('old_upline_id')->nullable();
                $table->unsignedBigInteger('new_upline_id')->nullable();
                $table->string('seller_code', 16)->nullable();
                $table->unsignedBigInteger('actor_id')->nullable(); // null = system
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_team_logs');
        Schema::table('users', function (Blueprint $table) {
            foreach (['seller_code', 'seller_verified_at', 'referred_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    if ($col === 'seller_code') {
                        $table->dropUnique(['seller_code']);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
