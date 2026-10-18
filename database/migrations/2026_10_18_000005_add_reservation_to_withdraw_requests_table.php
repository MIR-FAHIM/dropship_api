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
        if (Schema::hasTable('withdraw_requests')) {
            Schema::table('withdraw_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('withdraw_requests', 'reserved_amount')) {
                    $table->decimal('reserved_amount', 12, 2)->default(0.00)->after('amount');
                }
                if (!Schema::hasColumn('withdraw_requests', 'bank_reference')) {
                    $table->string('bank_reference', 100)->nullable()->after('note');
                }
                if (!Schema::hasColumn('withdraw_requests', 'transferred_at')) {
                    $table->timestamp('transferred_at')->nullable()->after('status');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('withdraw_requests')) {
            Schema::table('withdraw_requests', function (Blueprint $table) {
                $columns = ['reserved_amount', 'bank_reference', 'transferred_at'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('withdraw_requests', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
