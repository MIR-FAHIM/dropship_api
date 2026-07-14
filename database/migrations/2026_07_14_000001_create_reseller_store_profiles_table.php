<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_store_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id')->unique();
            $table->string('shop_name')->nullable();
            $table->string('logo')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('whatsapp', 50)->nullable();
            $table->text('address')->nullable();
            $table->longText('details')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('website')->nullable();
            $table->string('theme')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_store_profiles');
    }
};
