<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lead Manager: extra lead roles added by admins, on top of the built-in dropshipper / supplier. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lead_roles')) {
            Schema::create('lead_roles', function (Blueprint $table) {
                $table->id();
                $table->string('value', 20)->unique();   // stored in leads.role (string 20)
                $table->string('label', 60);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_roles');
    }
};
