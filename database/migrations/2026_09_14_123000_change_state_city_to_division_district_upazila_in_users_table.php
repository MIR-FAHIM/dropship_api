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
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'state')) {
                $table->dropColumn('state');
            }
            if (Schema::hasColumn('users', 'city')) {
                $table->dropColumn('city');
            }
            if (!Schema::hasColumn('users', 'division_id')) {
                $table->unsignedBigInteger('division_id')->nullable()->after('country');
            }
            if (!Schema::hasColumn('users', 'district_id')) {
                $table->unsignedBigInteger('district_id')->nullable()->after('division_id');
            }
            if (!Schema::hasColumn('users', 'upazila_id')) {
                $table->unsignedBigInteger('upazila_id')->nullable()->after('district_id');
            }
            if (!Schema::hasColumn('users', 'division')) {
                $table->string('division', 100)->nullable()->after('upazila_id');
            }
            if (!Schema::hasColumn('users', 'district')) {
                $table->string('district', 100)->nullable()->after('division');
            }
            if (!Schema::hasColumn('users', 'upazila')) {
                $table->string('upazila', 100)->nullable()->after('district');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'division_id')) {
                $table->dropColumn('division_id');
            }
            if (Schema::hasColumn('users', 'district_id')) {
                $table->dropColumn('district_id');
            }
            if (Schema::hasColumn('users', 'upazila_id')) {
                $table->dropColumn('upazila_id');
            }
            if (Schema::hasColumn('users', 'division')) {
                $table->dropColumn('division');
            }
            if (Schema::hasColumn('users', 'district')) {
                $table->dropColumn('district');
            }
            if (Schema::hasColumn('users', 'upazila')) {
                $table->dropColumn('upazila');
            }
            if (!Schema::hasColumn('users', 'state')) {
                $table->string('state', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'city')) {
                $table->string('city', 30)->nullable();
            }
        });
    }
};
