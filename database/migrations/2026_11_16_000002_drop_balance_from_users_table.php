<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Dropshipper wallet balance is calculated from reseller_transactions;
    // users.balance was never written and is no longer read.
    public function up(): void
    {
        if (Schema::hasColumn('users', 'balance')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('balance');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'balance')) {
            Schema::table('users', function (Blueprint $table) {
                $table->double('balance', 20, 2)->default(0.00);
            });
        }
    }
};
