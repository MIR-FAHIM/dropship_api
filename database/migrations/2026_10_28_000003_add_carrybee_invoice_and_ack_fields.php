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
        Schema::table('carrybee_webhook_events', function (Blueprint $table) {
            if (!Schema::hasColumn('carrybee_webhook_events', 'invoice_id')) {
                $table->string('invoice_id', 100)->nullable()->after('merchant_order_id');
                $table->index('invoice_id');
            }
            if (!Schema::hasColumn('carrybee_webhook_events', 'admin_acknowledged')) {
                $table->boolean('admin_acknowledged')->default(false)->after('processing_status');
                $table->index('admin_acknowledged');
            }
            if (!Schema::hasColumn('carrybee_webhook_events', 'admin_acknowledged_at')) {
                $table->timestamp('admin_acknowledged_at')->nullable()->after('admin_acknowledged');
            }
            if (!Schema::hasColumn('carrybee_webhook_events', 'disbursed_to')) {
                $table->string('disbursed_to', 20)->nullable()->after('admin_acknowledged_at'); // 'vendor' or 'platform'
            }
            if (!Schema::hasColumn('carrybee_webhook_events', 'disbursed_amount')) {
                $table->decimal('disbursed_amount', 12, 2)->nullable()->after('disbursed_to');
            }
        });

        Schema::table('delivery_assigned_infos', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_assigned_infos', 'carrybee_invoice_id')) {
                $table->string('carrybee_invoice_id', 100)->nullable()->after('merchant_order_id');
            }
            if (!Schema::hasColumn('delivery_assigned_infos', 'carrybee_paid_at')) {
                $table->timestamp('carrybee_paid_at')->nullable()->after('carrybee_invoice_id');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'carrybee_invoice_id')) {
                $table->string('carrybee_invoice_id', 100)->nullable()->after('courier_payout_status');
            }
            if (!Schema::hasColumn('orders', 'carrybee_paid_at')) {
                $table->timestamp('carrybee_paid_at')->nullable()->after('carrybee_invoice_id');
            }
            if (!Schema::hasColumn('orders', 'carrybee_disbursed_to')) {
                $table->string('carrybee_disbursed_to', 20)->nullable()->after('carrybee_paid_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrybee_webhook_events', function (Blueprint $table) {
            $cols = ['invoice_id', 'admin_acknowledged', 'admin_acknowledged_at', 'disbursed_to', 'disbursed_amount'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('carrybee_webhook_events', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('delivery_assigned_infos', function (Blueprint $table) {
            $cols = ['carrybee_invoice_id', 'carrybee_paid_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('delivery_assigned_infos', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $cols = ['carrybee_invoice_id', 'carrybee_paid_at', 'carrybee_disbursed_to'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
