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
        if (Schema::hasTable('order_statuses')) {
            $exists = DB::table('order_statuses')->where('id', 10)->exists();
            if (!$exists) {
                DB::table('order_statuses')->insert([
                    'id' => 10,
                    'name' => 'Carrybee Paid',
                    'type' => 'delivery',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('order_statuses')->where('id', 10)->update([
                    'name' => 'Carrybee Paid',
                    'type' => 'delivery',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_statuses')) {
            DB::table('order_statuses')->where('id', 10)->delete();
        }
    }
};
