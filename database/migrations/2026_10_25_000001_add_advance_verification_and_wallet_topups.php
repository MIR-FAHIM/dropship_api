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
        // 1. Add advance verification fields to orders table
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'advance_status')) {
                    $table->string('advance_status', 20)->default('none')->after('advance_amount'); // none, pending, approved, rejected
                }
                if (!Schema::hasColumn('orders', 'advance_sender_phone')) {
                    $table->string('advance_sender_phone', 30)->nullable()->after('advance_trx_id');
                }
                if (!Schema::hasColumn('orders', 'advance_verified_at')) {
                    $table->timestamp('advance_verified_at')->nullable()->after('advance_sender_phone');
                }
                if (!Schema::hasColumn('orders', 'advance_verified_by')) {
                    $table->unsignedBigInteger('advance_verified_by')->nullable()->after('advance_verified_at');
                }
                if (!Schema::hasColumn('orders', 'advance_admin_note')) {
                    $table->text('advance_admin_note')->nullable()->after('advance_verified_by');
                }
            });
        }

        // 2. Create wallet_topups table for dropshipper prepaid recharge requests
        if (!Schema::hasTable('wallet_topups')) {
            Schema::create('wallet_topups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 50)->default('bkash');
                $table->string('sender_phone', 30)->nullable();
                $table->string('trx_id', 100)->nullable();
                $table->string('receipt_image', 255)->nullable();
                $table->string('status', 20)->default('pending'); // pending, approved, rejected
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('admin_note')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index('trx_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $cols = ['advance_status', 'advance_sender_phone', 'advance_verified_at', 'advance_verified_by', 'advance_admin_note'];
                foreach ($cols as $c) {
                    if (Schema::hasColumn('orders', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }

        Schema::dropIfExists('wallet_topups');
    }
};
