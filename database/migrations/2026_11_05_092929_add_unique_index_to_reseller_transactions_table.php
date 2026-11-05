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
        // Defense-in-depth against duplicate credits/debits for the same
        // order (e.g. a double-click on "disburse", or a retried request):
        // every order-tied type/trx_type combination should exist at most
        // once. Rows with a NULL order_id (wallet topups, non-order
        // withdrawals, etc.) are intentionally not deduplicated - NULLs are
        // never considered equal in a unique index on any driver this app
        // runs on (MySQL, sqlite).
        //
        // This intentionally does NOT attempt to clean up any pre-existing
        // duplicates automatically - deleting financial transaction rows
        // without a human reviewing them first would itself violate the
        // "no payment should be traceless" goal this migration serves. If
        // this migration fails here, that means real duplicates already
        // exist and need manual reconciliation before re-running it.
        Schema::table('reseller_transactions', function (Blueprint $table) {
            $table->unique(['order_id', 'type', 'trx_type'], 'reseller_transactions_order_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_transactions', function (Blueprint $table) {
            $table->dropUnique('reseller_transactions_order_type_unique');
        });
    }
};
