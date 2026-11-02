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
        Schema::dropIfExists('partner_stores');

        Schema::create('partner_stores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id'); // References users.id
            $table->string('store_name', 191);
            $table->string('store_domain', 191)->nullable();  // e.g. www.tanvirgadget.com
            $table->string('store_url', 255)->nullable();
            $table->string('api_key', 64)->unique();          // e.g. rb_live_pk_...
            $table->string('api_secret', 128);                // e.g. rb_live_sk_...
            $table->string('webhook_url', 255)->nullable();
            $table->string('webhook_secret', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->json('metadata')->nullable();             // Store preferences, branding
            $table->timestamps();

            $table->index('user_id');
            $table->index('is_active');
            $table->index('store_domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_stores');
    }
};
