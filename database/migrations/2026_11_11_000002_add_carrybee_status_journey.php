<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CarryBee status journey:
 *  - new order statuses 11 Lost / Damaged, 12 Return in Transit, 13 Partially Delivered
 *  - courier tracking fields on delivery_assigned_infos
 *  - source (manual / webhook / catch-up / system) on order_status_histories
 *  - vendors.carryb_store_id as text (CarryBee store ids are strings)
 */
return new class extends Migration
{
    private array $statuses = [
        11 => ['name' => 'Lost / Damaged', 'type' => 'order'],
        12 => ['name' => 'Return in Transit', 'type' => 'delivery'],
        13 => ['name' => 'Partially Delivered', 'type' => 'delivery'],
    ];

    private array $trackingColumns = [
        'courier_stage', 'courier_stage_at', 'attempt', 'rider_name', 'rider_phone',
        'issue_flag', 'issue_reason', 'collected_amount', 'cod_fee', 'needs_review', 'review_reason',
        'courier_checked_at',
    ];

    public function up(): void
    {
        if (Schema::hasTable('order_statuses')) {
            foreach ($this->statuses as $id => $status) {
                DB::table('order_statuses')->updateOrInsert(
                    ['id' => $id],
                    $status + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        Schema::table('delivery_assigned_infos', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_assigned_infos', 'courier_stage')) {
                $table->string('courier_stage', 64)->nullable();
                $table->timestamp('courier_stage_at')->nullable();
                $table->unsignedSmallInteger('attempt')->nullable();
                $table->string('rider_name')->nullable();
                $table->string('rider_phone', 32)->nullable();
                // pickup | delivery | create_failed - cleared by the next normal step
                $table->string('issue_flag', 32)->nullable();
                $table->text('issue_reason')->nullable();
                $table->decimal('collected_amount', 12, 2)->nullable();
                $table->decimal('cod_fee', 12, 2)->nullable();
                $table->boolean('needs_review')->default(false);
                $table->string('review_reason')->nullable();
                // Last time the catch-up job asked CarryBee about this consignment.
                $table->timestamp('courier_checked_at')->nullable();
            }
        });

        Schema::table('order_status_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('order_status_histories', 'source')) {
                $table->string('source', 20)->nullable()->after('changed_by');
            }
        });

        if (Schema::hasColumn('vendors', 'carryb_store_id')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('carryb_store_id', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('delivery_assigned_infos', function (Blueprint $table) {
            foreach ($this->trackingColumns as $column) {
                if (Schema::hasColumn('delivery_assigned_infos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('order_status_histories', function (Blueprint $table) {
            if (Schema::hasColumn('order_status_histories', 'source')) {
                $table->dropColumn('source');
            }
        });

        // Statuses stay: history rows may reference them. vendors.carryb_store_id stays text.
    }
};
