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
        Schema::table('orders', function (Blueprint $table) {
            $afterCol = Schema::hasColumn('orders', 'shipping_fee') ? 'shipping_fee' : (Schema::hasColumn('orders', 'total') ? 'total' : null);

            if (!Schema::hasColumn('orders', 'advance_amount')) {
                $col = $table->decimal('advance_amount', 12, 2)->default(0.00);
                if ($afterCol) {
                    $col->after($afterCol);
                }
            }
            if (!Schema::hasColumn('orders', 'advance_recipient')) {
                $table->string('advance_recipient', 20)->default('platform'); // platform, reseller, none
            }
            if (!Schema::hasColumn('orders', 'advance_payment_method')) {
                $table->string('advance_payment_method', 50)->nullable();
            }
            if (!Schema::hasColumn('orders', 'advance_trx_id')) {
                $table->string('advance_trx_id', 100)->nullable();
            }
            if (!Schema::hasColumn('orders', 'remaining_cod')) {
                $table->decimal('remaining_cod', 12, 2)->default(0.00);
            }
            if (!Schema::hasColumn('orders', 'courier_account_owner')) {
                $table->string('courier_account_owner', 20)->default('platform'); // platform, vendor
            }
            if (!Schema::hasColumn('orders', 'actual_courier_fee')) {
                $table->decimal('actual_courier_fee', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('orders', 'actual_cod_fee')) {
                $table->decimal('actual_cod_fee', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('orders', 'actual_courier_cost')) {
                $table->decimal('actual_courier_cost', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('orders', 'courier_cost_confirmed')) {
                $table->boolean('courier_cost_confirmed')->default(false);
            }
            if (!Schema::hasColumn('orders', 'courier_net_payout')) {
                $table->decimal('courier_net_payout', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('orders', 'courier_payout_status')) {
                $table->string('courier_payout_status', 20)->default('pending'); // pending, received, reconciled
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'advance_amount',
                'advance_recipient',
                'advance_payment_method',
                'advance_trx_id',
                'remaining_cod',
                'courier_account_owner',
                'actual_courier_fee',
                'actual_cod_fee',
                'actual_courier_cost',
                'courier_cost_confirmed',
                'courier_net_payout',
                'courier_payout_status',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
