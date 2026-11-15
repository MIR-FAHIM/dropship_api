<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin can set an invoice's final amount when the calculated sum isn't the real
 * charge. The calculated amount is kept; the difference is a recorded adjustment
 * with a reason. Per-order settlements keep their calculated amounts.
 */
return new class extends Migration
{
    private array $columns = ['calculated_amount', 'adjustment_amount', 'adjustment_reason', 'adjusted_by', 'adjusted_at'];

    public function up(): void
    {
        Schema::table('settlement_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('settlement_invoices', 'calculated_amount')) {
                // Sum of the orders as calculated by the system.
                $table->decimal('calculated_amount', 14, 2)->nullable();
                // Final amount - calculated amount (negative = less than calculated).
                $table->decimal('adjustment_amount', 14, 2)->default(0);
                $table->text('adjustment_reason')->nullable();
                $table->unsignedBigInteger('adjusted_by')->nullable();
                $table->timestamp('adjusted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settlement_invoices', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('settlement_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
