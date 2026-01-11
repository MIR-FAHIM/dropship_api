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
        Schema::create('delivery_addresses', function (Blueprint $table) {
            $table->id();

            $table->string('name');                  // Recipient name
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // User ID
            $table->string('mobile', 20);            // Contact number

            $table->string('address');               // Full address line
            $table->string('district');              // District name
            $table->string('area');                  // Area / Thana

            $table->string('house')->nullable();     // House name/number
            $table->string('flat')->nullable();      // Flat / Apartment

            $table->decimal('lat', 10, 7)->nullable(); // Latitude
            $table->decimal('lon', 10, 7)->nullable(); // Longitude

            $table->text('note')->nullable();        // Delivery note
            $table->boolean('status')->default(1);  // Active / Inactive

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_addresses');
    }
};
