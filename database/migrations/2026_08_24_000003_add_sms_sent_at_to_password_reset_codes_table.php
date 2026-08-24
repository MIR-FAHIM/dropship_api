<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_codes') && !Schema::hasColumn('password_reset_codes', 'sms_sent_at')) {
            Schema::table('password_reset_codes', function (Blueprint $table) {
                $table->timestamp('sms_sent_at')->nullable()->after('expires_at');
                $table->index(['phone', 'sms_sent_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('password_reset_codes') && Schema::hasColumn('password_reset_codes', 'sms_sent_at')) {
            Schema::table('password_reset_codes', function (Blueprint $table) {
                $table->dropIndex(['phone', 'sms_sent_at']);
                $table->dropColumn('sms_sent_at');
            });
        }
    }
};
