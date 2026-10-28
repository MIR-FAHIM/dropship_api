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
        if (Schema::hasTable('settlement_invoices')) {
            Schema::table('settlement_invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('settlement_invoices', 'admin_payment_details')) {
                    $table->json('admin_payment_details')->nullable()->after('note');
                }
                if (!Schema::hasColumn('settlement_invoices', 'paid_by')) {
                    $table->unsignedBigInteger('paid_by')->nullable()->after('admin_payment_details')->index();
                }
                if (!Schema::hasColumn('settlement_invoices', 'paid_amount')) {
                    $table->decimal('paid_amount', 14, 2)->nullable()->after('paid_by');
                }
                if (!Schema::hasColumn('settlement_invoices', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('paid_amount');
                }
                if (!Schema::hasColumn('settlement_invoices', 'sender_account')) {
                    $table->string('sender_account', 100)->nullable()->after('paid_at');
                }
                if (!Schema::hasColumn('settlement_invoices', 'approved_by')) {
                    $table->unsignedBigInteger('approved_by')->nullable()->after('sender_account')->index();
                }
                if (!Schema::hasColumn('settlement_invoices', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
                if (!Schema::hasColumn('settlement_invoices', 'rejected_by')) {
                    $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_at')->index();
                }
                if (!Schema::hasColumn('settlement_invoices', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable()->after('rejected_by');
                }
                if (!Schema::hasColumn('settlement_invoices', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('rejected_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('settlement_invoices')) {
            Schema::table('settlement_invoices', function (Blueprint $table) {
                $columns = [
                    'admin_payment_details',
                    'paid_by',
                    'paid_amount',
                    'paid_at',
                    'sender_account',
                    'approved_by',
                    'approved_at',
                    'rejected_by',
                    'rejected_at',
                    'rejection_reason',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('settlement_invoices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
