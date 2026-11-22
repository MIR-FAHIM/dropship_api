<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CarryBee reconciliation: CarryBee's final invoice lines are the truth for every order.
 *  - carrybee_invoice_lines: one row per parcel line of a CarryBee invoice (delivery or return line)
 *  - order_return_penalties: return charges CarryBee took, and who pays them (vendor / admin / dropshipper)
 *  - payout_flags: payouts already made that don't match CarryBee (overpaid / underpaid …), shown, never auto-changed
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('carrybee_invoice_lines')) {
            Schema::create('carrybee_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->string('account_key', 40)->index();         // 'platform' or 'vendor-{id}'
                $table->unsignedBigInteger('vendor_id')->nullable(); // own-account vendor
                $table->string('invoice_id', 60)->index();
                $table->date('invoiced_date')->nullable();
                $table->string('receivable_status', 20)->nullable();
                $table->string('consignment_id', 60);
                $table->string('merchant_order_id', 80)->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('line_type', 10)->default('delivery'); // delivery | return
                $table->decimal('collected', 12, 2)->default(0);
                $table->decimal('delivery_fee', 12, 2)->default(0);
                $table->decimal('cod_fee', 12, 2)->default(0);
                $table->decimal('total_fee', 12, 2)->default(0);
                $table->string('delivery_status', 40)->nullable();
                $table->timestamps();
                $table->unique(['invoice_id', 'consignment_id']);
            });
        }

        if (!Schema::hasTable('order_return_penalties')) {
            Schema::create('order_return_penalties', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->decimal('amount', 12, 2)->default(0);        // CarryBee charges − cash collected
                $table->string('payer', 20)->nullable();             // null = not decided yet; vendor | admin | dropshipper
                $table->text('note')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payout_flags')) {
            Schema::create('payout_flags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('party', 20);                         // dropshipper | vendor
                $table->unsignedBigInteger('settlement_id')->nullable();
                $table->string('flag', 20);                          // overpaid | underpaid (dropshipper), overcharged | undercharged (vendor)
                $table->decimal('recorded', 12, 2)->default(0);
                $table->decimal('correct', 12, 2)->default(0);
                $table->decimal('difference', 12, 2)->default(0);    // recorded − correct
                $table->string('reason', 255)->nullable();
                $table->string('status', 20)->default('open');       // open | resolved
                $table->text('note')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->unique(['order_id', 'party']);
            });
        }

        if (!Schema::hasColumn('orders', 'carrybee_reconciled_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('carrybee_reconciled_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'carrybee_reconciled_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('carrybee_reconciled_at');
            });
        }
        Schema::dropIfExists('payout_flags');
        Schema::dropIfExists('order_return_penalties');
        Schema::dropIfExists('carrybee_invoice_lines');
    }
};
