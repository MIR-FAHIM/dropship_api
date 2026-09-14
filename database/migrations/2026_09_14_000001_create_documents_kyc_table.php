<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_kyc', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('document_name')->nullable();
            $table->string('type', 80)->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('note')->nullable();
            $table->string('document_file_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type'], 'documents_kyc_user_type_unique');
            $table->index('user_id');
            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_kyc');
    }
};
