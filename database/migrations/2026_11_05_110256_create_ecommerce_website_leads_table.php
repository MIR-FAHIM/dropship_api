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
        Schema::create('ecommerce_website_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('lead_type', 30); // interested_later, question, show_packages
            $table->text('message')->nullable(); // the dropshipper's question, if any
            $table->string('status', 20)->default('new'); // new, contacted, closed
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('contacted_by')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['status', 'lead_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_website_leads');
    }
};
