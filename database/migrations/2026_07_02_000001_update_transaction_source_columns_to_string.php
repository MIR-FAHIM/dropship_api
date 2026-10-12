<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'source')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('source', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
    }
};
