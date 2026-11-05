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
        Schema::table('order_settlements', function (Blueprint $table) {
            // Explicit, not sign-implied: who owes whom. Needed because a
            // vendor who collected the COD directly (own CarryBee account)
            // can end up owing ResellerBrain the surplus above their product
            // cost, rather than the usual platform-owes-payee direction.
            $table->enum('direction', ['platform_owes_payee', 'payee_owes_platform'])
                ->default('platform_owes_payee')
                ->after('net_payable_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
