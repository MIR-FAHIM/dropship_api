<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead management (admin CRM): prospects to call / message, either typed in by an
 * admin or converted from an inactive dropshipper. Every admin sees every lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_product_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->nullable();
            $table->string('phone', 30);
            // 01XXXXXXXXX form, used for duplicates and matching dropshipper accounts.
            $table->string('phone_normalized', 20)->index();
            $table->string('role', 20)->default('dropshipper');      // dropshipper | supplier
            $table->string('status', 20)->default('new')->index();   // new | contacted | converted | cancelled
            $table->string('followup_tag', 30)->nullable()->index(); // phone_off | no_answer | call_later | messaged | interested | not_interested
            $table->string('page_link', 500)->nullable();
            $table->unsignedBigInteger('product_tag_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->timestamp('next_call_at')->nullable()->index();
            $table->string('source', 30)->default('manual');         // manual | dropshipper
            $table->unsignedBigInteger('user_id')->nullable()->index(); // linked dropshipper account
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            // created | updated | status_changed | followup_changed | note | call | whatsapp | scheduled | converted
            $table->string('type', 30)->index();
            $table->string('from_value', 100)->nullable();
            $table->string('to_value', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_product_tags');
    }
};
