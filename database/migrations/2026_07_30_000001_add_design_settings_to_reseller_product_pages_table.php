<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reseller_product_pages')) {
            DB::statement("ALTER TABLE reseller_product_pages MODIFY template_id VARCHAR(50) NULL DEFAULT 'default'");
            DB::statement("UPDATE reseller_product_pages SET template_id = 'default' WHERE template_id IS NULL OR template_id = '' OR template_id REGEXP '^[0-9]+$'");
            DB::statement("ALTER TABLE reseller_product_pages MODIFY template_id VARCHAR(50) NOT NULL DEFAULT 'default'");

            Schema::table('reseller_product_pages', function (Blueprint $table) {
                if (!Schema::hasColumn('reseller_product_pages', 'design_settings')) {
                    $table->json('design_settings')->nullable()->after('template_id');
                }

                if (!Schema::hasColumn('reseller_product_pages', 'design_version')) {
                    $table->unsignedInteger('design_version')->default(1)->after('design_settings');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reseller_product_pages')) {
            Schema::table('reseller_product_pages', function (Blueprint $table) {
                if (Schema::hasColumn('reseller_product_pages', 'design_version')) {
                    $table->dropColumn('design_version');
                }

                if (Schema::hasColumn('reseller_product_pages', 'design_settings')) {
                    $table->dropColumn('design_settings');
                }
            });

            DB::statement("UPDATE reseller_product_pages SET template_id = NULL WHERE template_id NOT REGEXP '^[0-9]+$'");
            DB::statement('ALTER TABLE reseller_product_pages MODIFY template_id BIGINT UNSIGNED NULL');
        }
    }
};
