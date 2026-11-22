<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner Portal F4: product sourcing requests a marketer sends for their client's campaign.
 * The sourcing team checks the product and confirms a wholesale price (incl. packaging).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_product_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->index();               // the marketer (users.id)
            $table->unsignedBigInteger('marketer_client_id')->nullable();     // optional: which client it's for
            $table->string('product_name');
            $table->string('product_image', 500);                            // path on the public disk
            $table->string('campaign_platform', 50);                         // facebook_instagram | tiktok | google_youtube | other
            $table->decimal('daily_budget_usd', 10, 2);
            $table->string('campaign_status', 30);                           // active | upcoming | testing
            $table->string('confirmation_deadline', 100);                    // 2h | 6h | 24h | days | date
            $table->timestamp('deadline_at')->nullable()->index();           // when the answer is due (SLA)
            $table->text('special_note')->nullable();
            $table->string('status', 20)->default('pending')->index();      // pending | checking | confirmed | rejected | withdrawn
            $table->decimal('quoted_price_bdt', 10, 2)->nullable();          // incl. packaging
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('linked_product_id')->nullable();     // product they can order now
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_product_requirements');
    }
};
