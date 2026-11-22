<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner Portal phase 1: marketer commission.
 *
 * A marketer earns rate% of the platform's profit (product markup + delivery margin) on
 * every order of their clients, once it is delivered and CarryBee has paid. Commissions are
 * paid out with settlement_invoices of type "marketer_commission".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketer_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('marketer_user_id')->index();
            $table->unsignedBigInteger('marketer_client_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->index();
            // commission: earned on delivery; clawback: negative line when a paid/invoiced one is reversed
            $table->string('type', 20)->default('commission');
            $table->decimal('product_markup', 12, 2)->default(0);
            $table->decimal('delivery_margin', 12, 2)->default(0);
            $table->decimal('base_amount', 12, 2)->default(0);   // markup + margin
            $table->decimal('rate', 5, 2);                       // % at the time it was earned
            $table->decimal('amount', 12, 2);                    // rate% of base (negative for clawback)
            $table->string('status', 20)->default('earned')->index(); // earned | paid | reversed
            $table->unsignedBigInteger('payout_invoice_id')->nullable()->index();
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type'], 'marketer_commissions_order_type_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'marketer_commission_rate')) {
                // null = the default rate (config marketer.commission_rate, 20%)
                $table->decimal('marketer_commission_rate', 5, 2)->nullable();
            }
        });

        Schema::table('settlement_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('settlement_invoices', 'marketer_id')) {
                $table->unsignedBigInteger('marketer_id')->nullable()->index()->after('dropshipper_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settlement_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('settlement_invoices', 'marketer_id')) {
                $table->dropIndex(['marketer_id']);
                $table->dropColumn('marketer_id');
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'marketer_commission_rate')) {
                $table->dropColumn('marketer_commission_rate');
            }
        });
        Schema::dropIfExists('marketer_commissions');
    }
};
