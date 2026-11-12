<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dropshipper payout invoices reuse settlement_invoices (type = dropshipper_payout).
 * They need to know which dropshipper they pay, and who cancelled an unpaid one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('settlement_invoices', 'dropshipper_id')) {
                $table->unsignedBigInteger('dropshipper_id')->nullable()->index()->after('vendor_id');
            }
            if (!Schema::hasColumn('settlement_invoices', 'receiver_account')) {
                // bKash / Nagad / bank account the money was sent to.
                $table->string('receiver_account', 100)->nullable()->after('trx_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settlement_invoices', function (Blueprint $table) {
            foreach (['receiver_account', 'dropshipper_id'] as $column) {
                if (Schema::hasColumn('settlement_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
