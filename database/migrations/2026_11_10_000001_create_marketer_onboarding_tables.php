<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketer_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('marketer_user_id')->index();
            $table->unsignedInteger('dropshipper_user_id')->index();
            // A nullable unique slot allows historical links, but only one active link.
            $table->unsignedInteger('active_dropshipper_user_id')->nullable()->unique();
            $table->string('consent_version', 32);
            $table->timestamp('accepted_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('ended_by')->nullable();
        });

        Schema::create('marketer_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('marketer_user_id')->index();
            $table->string('email', 191);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('marketer_client_id')->nullable()->constrained();
            $table->timestamps();
        });

        foreach (['orders', 'landing_page_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('marketer_client_id')->nullable()->constrained();
                $table->index('marketer_client_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['landing_page_orders', 'orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['marketer_client_id']);
                $table->dropIndex(['marketer_client_id']);
                $table->dropColumn('marketer_client_id');
            });
        }

        Schema::dropIfExists('marketer_invitations');
        Schema::dropIfExists('marketer_clients');
    }
};
