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
        if (Schema::hasTable('order_settlements')) {
            Schema::table('order_settlements', function (Blueprint $table) {
                if (!Schema::hasColumn('order_settlements', 'gross_entitlement')) {
                    $table->decimal('gross_entitlement', 12, 2)->nullable()->after('settleable_amount');
                }
                if (!Schema::hasColumn('order_settlements', 'deducted_by_courier')) {
                    $table->decimal('deducted_by_courier', 12, 2)->default(0.00)->after('gross_entitlement');
                }
                if (!Schema::hasColumn('order_settlements', 'advance_deduction')) {
                    $table->decimal('advance_deduction', 12, 2)->default(0.00)->after('deducted_by_courier');
                }
                if (!Schema::hasColumn('order_settlements', 'net_payable_amount')) {
                    $table->decimal('net_payable_amount', 12, 2)->nullable()->after('advance_deduction');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_settlements')) {
            Schema::table('order_settlements', function (Blueprint $table) {
                $columns = ['gross_entitlement', 'deducted_by_courier', 'advance_deduction', 'net_payable_amount'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('order_settlements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
