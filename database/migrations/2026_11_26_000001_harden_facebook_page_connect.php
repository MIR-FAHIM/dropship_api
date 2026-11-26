<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-click Facebook page connect (dropshipper panel).
 *  - facebook_accounts keep the (encrypted) long-lived user token, so pages can be re-read without a new login.
 *  - facebook_pages get a status (connected / needs_reconnect / disconnected), the page photo and the last error.
 * Tokens are always stored encrypted and never returned to the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_accounts', 'access_token')) {
                $table->text('access_token')->nullable();
            }
            if (!Schema::hasColumn('facebook_accounts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable();
            }
            if (!Schema::hasColumn('facebook_accounts', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        Schema::table('facebook_pages', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_pages', 'status')) {
                $table->string('status', 20)->default('connected');
            }
            if (!Schema::hasColumn('facebook_pages', 'picture_url')) {
                $table->text('picture_url')->nullable();
            }
            if (!Schema::hasColumn('facebook_pages', 'last_error')) {
                $table->string('last_error', 500)->nullable();
            }
            if (!Schema::hasColumn('facebook_pages', 'connected_at')) {
                $table->timestamp('connected_at')->nullable();
            }
            if (!Schema::hasColumn('facebook_pages', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            foreach (['status', 'picture_url', 'last_error', 'connected_at', 'updated_at'] as $c) {
                if (Schema::hasColumn('facebook_pages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('facebook_accounts', function (Blueprint $table) {
            foreach (['access_token', 'token_expires_at', 'updated_at'] as $c) {
                if (Schema::hasColumn('facebook_accounts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
