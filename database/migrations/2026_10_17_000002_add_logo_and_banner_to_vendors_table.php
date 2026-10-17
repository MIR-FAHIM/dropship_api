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
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'logo')) {
                $table->string('logo')->nullable()->after('shop_type');
            }
            if (!Schema::hasColumn('vendors', 'banner')) {
                $table->string('banner')->nullable()->after('logo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'banner')) {
                $table->dropColumn('banner');
            }
            if (Schema::hasColumn('vendors', 'logo')) {
                $table->dropColumn('logo');
            }
        });
    }
};
