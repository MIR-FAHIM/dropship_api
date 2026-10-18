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
            if (!Schema::hasColumn('orders', 'advance_amount')) {
                $table->decimal('advance_amount', 12, 2)->default(0.00)->after('shipping_charge');
            }
            if (!Schema::hasColumn('orders', 'advance_recipient')) {
                $table->string('advance_recipient', 20)->default('platform')->after('advance_amount'); // platform, reseller, none
            }
            if (!Schema::hasColumn('orders', 'advance_payment_method')) {
                $table->string('advance_payment_method', 50)->nullable()->after('advance_recipient');
            }
            if (!Schema::hasColumn('orders', 'advance_trx_id')) {
                $table->string('advance_trx_id', 100)->nullable()->after('advance_payment_method');
            }
            if (!Schema::hasColumn('orders', 'remaining_cod')) {
                $table->decimal('remaining_cod', 12, 2)->default(0.00)->after('advance_trx_id');
            }
            if (!Schema::hasColumn('orders', 'courier_account_owner')) {
                $table->string('courier_account_owner', 20)->default('platform')->after('remaining_cod'); // platform, vendor
            }
            if (!Schema::hasColumn('orders', 'actual_courier_fee')) {
                $table->decimal('actual_courier_fee', 12, 2)->nullable()->after('courier_account_owner');
            }
            if (!Schema::hasColumn('orders', 'actual_cod_fee')) {
                $table->decimal('actual_cod_fee', 12, 2)->nullable()->after('actual_courier_fee');
            }
            if (!Schema::hasColumn('orders', 'actual_courier_cost')) {
                $table->decimal('actual_courier_cost', 12, 2)->nullable()->after('actual_cod_fee');
            }
            if (!Schema::hasColumn('orders', 'courier_cost_confirmed')) {
                $table->boolean('courier_cost_confirmed')->default(false)->after('actual_courier_cost');
            }
            if (!Schema::hasColumn('orders', 'courier_net_payout')) {
                $table->decimal('courier_net_payout', 12, 2)->nullable()->after('courier_cost_confirmed');
            }
            if (!Schema::hasColumn('orders', 'courier_payout_status')) {
                $table->string('courier_payout_status', 20)->default('pending')->after('courier_net_payout'); // pending, received, reconciled
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
