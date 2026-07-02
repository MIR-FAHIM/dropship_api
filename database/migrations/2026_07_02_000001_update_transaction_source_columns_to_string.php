<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source', 100)->nullable()->change();
        });

        Schema::table('reseller_transactions', function (Blueprint $table) {
            $table->string('source', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('source', ['cod', 'online_payment', 'wallet'])->nullable()->change();
        });

        Schema::table('reseller_transactions', function (Blueprint $table) {
            $table->enum('source', ['cod', 'online_payment', 'wallet'])->nullable()->change();
        });
    }
};
