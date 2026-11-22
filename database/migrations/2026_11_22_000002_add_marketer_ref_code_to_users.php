<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Partner Portal F3: each marketer's public referral code (e.g. RB-SCALEUP12) that dropshippers
// can enter on Marketer Partnership to connect (with the same consent as an invitation).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'marketer_ref_code')) {
                $table->string('marketer_ref_code', 32)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'marketer_ref_code')) {
                $table->dropUnique(['marketer_ref_code']);
                $table->dropColumn('marketer_ref_code');
            }
        });
    }
};
