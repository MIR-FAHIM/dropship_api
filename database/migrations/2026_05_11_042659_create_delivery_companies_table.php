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
        Schema::create('delivery_companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('base_url')->nullable()->comment('Base URL for API calls');
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('support_number')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('email')->nullable();
            $table->string('secondary_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('secret_key')->nullable()->comment('Secret-Key for steadfast, Client-Secret for carrybee');
            $table->string('api_key')->nullable()->comment('Api-Key for steadfast, Client-ID for carrybee');
            $table->string('client_context')->nullable()->comment('Client-Context for carrybee');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_companies');
    }
};
