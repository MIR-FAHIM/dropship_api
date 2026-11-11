<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'admin_note')) {
                // Internal note for admins only; hidden from reseller/vendor responses.
                $table->text('admin_note')->nullable()->after('note');
            }
            if (!Schema::hasColumn('orders', 'admin_note_updated_by')) {
                $table->unsignedBigInteger('admin_note_updated_by')->nullable()->after('admin_note');
            }
            if (!Schema::hasColumn('orders', 'admin_note_updated_at')) {
                $table->timestamp('admin_note_updated_at')->nullable()->after('admin_note_updated_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['admin_note_updated_at', 'admin_note_updated_by', 'admin_note'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
