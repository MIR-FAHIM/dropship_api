<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner Portal F8: partner (marketer) application + profile and level.
 * Marketers created by an admin before this have no row; they are treated as approved with no level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('avatar', 500)->nullable();
            $table->string('agency_logo', 500)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('facebook_page', 500)->nullable();
            $table->string('portfolio_url', 500)->nullable();
            $table->decimal('ad_spend_usd', 12, 2)->default(0);
            $table->unsignedInteger('campaigns_run')->default(0);
            $table->string('niche', 100)->nullable();
            $table->string('campaign_type', 100)->nullable();
            $table->string('campaign_screenshot', 500)->nullable();
            $table->string('level', 10)->nullable();                 // pro | mid (null = not set)
            $table->string('level_source', 10)->default('auto');     // auto | admin
            $table->string('application_status', 20)->default('approved')->index(); // pending | approved | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_profiles');
    }
};
