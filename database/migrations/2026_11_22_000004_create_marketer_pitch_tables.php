<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Partner Portal F5 (Pitch Calculator): the marketer's saved dollar rate and starred products.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'marketer_dollar_rate')) {
                // BDT per USD incl. the card / agency charge, used to turn ad CPA ($) into taka.
                $table->decimal('marketer_dollar_rate', 8, 2)->nullable();
            }
        });

        Schema::create('marketer_starred_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('marketer_user_id')->index();
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
            $table->unique(['marketer_user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_starred_products');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'marketer_dollar_rate')) {
                $table->dropColumn('marketer_dollar_rate');
            }
        });
    }
};
