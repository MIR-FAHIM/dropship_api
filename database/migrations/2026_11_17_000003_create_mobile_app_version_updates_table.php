<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile app releases. The app sends its platform and build number to
 * GET api/app-version/check; the latest active row for that platform decides
 * whether it sees no prompt, an optional update or a forced update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_version_updates', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10);                    // android | ios
            $table->string('version_name', 30);                // shown to users, e.g. 1.2.0
            $table->unsignedInteger('build_number');           // compared: the +N in pubspec
            $table->unsignedInteger('min_supported_build')->default(0); // below this = forced
            $table->boolean('is_force_update')->default(false);
            $table->string('title', 150)->nullable();
            $table->text('release_notes')->nullable();
            $table->string('store_url', 500);
            $table->boolean('is_active')->default(true);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'build_number']);
            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_version_updates');
    }
};
