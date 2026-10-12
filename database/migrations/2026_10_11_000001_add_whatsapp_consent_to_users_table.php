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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('whatsapp_consent')->default(true)->after('phone');
            $table->string('whatsapp_consent_source', 100)->nullable()->after('whatsapp_consent');
            $table->timestamp('whatsapp_consented_at')->nullable()->after('whatsapp_consent_source');
            $table->timestamp('whatsapp_opted_out_at')->nullable()->after('whatsapp_consented_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_consent',
                'whatsapp_consent_source',
                'whatsapp_consented_at',
                'whatsapp_opted_out_at',
            ]);
        });
    }
};
