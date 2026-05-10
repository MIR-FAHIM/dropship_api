<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // 1) id (UNSIGNED int, AUTO_INCREMENT)
            $table->increments('id');

            // 2) referred_by int NULL
            $table->integer('referred_by')->nullable();

            // 3) provider varchar(255) NULL
            $table->string('provider', 255)->nullable();

            // 4) provider_id varchar(50) NULL
            $table->string('provider_id', 50)->nullable();

            // 5) refresh_token text NULL
            $table->text('refresh_token')->nullable();

            // 6) access_token longtext NULL
            $table->longText('access_token')->nullable();

            // 7) user_type varchar(20) NOT NULL default 'customer'
            $table->string('user_type', 20)->default('customer');

            // 8) name varchar(191) NOT NULL
            $table->string('name', 191);

            // 9) email varchar(191) NULL + INDEX
            $table->string('email', 191)->nullable()->index();

            // 10) email_verified_at timestamp NULL
            $table->timestamp('email_verified_at')->nullable();

            // 11) verification_code text NULL
            $table->text('verification_code')->nullable();

            // 12) new_email_verificiation_code text NULL (keep exact column name)
            $table->text('new_email_verificiation_code')->nullable();

            // 13) password varchar(191) NULL
            $table->string('password', 191)->nullable();

            // 14) remember_token varchar(100) NULL
            $table->rememberToken(); // creates remember_token varchar(100) nullable

            // 15) device_token varchar(255) NULL
            $table->string('device_token', 255)->nullable();

            // 16) avatar varchar(256) NULL
            $table->string('avatar', 256)->nullable();

            // 17) avatar_original varchar(256) NULL
            $table->string('avatar_original', 256)->nullable();

            // 18) address varchar(300) NULL
            $table->string('address', 300)->nullable();

            // 19) country varchar(30) NULL
            $table->string('country', 30)->nullable();

            // 20) state varchar(30) NULL
            $table->string('state', 30)->nullable();

            // 21) city varchar(30) NULL
            $table->string('city', 30)->nullable();

            // 22) postal_code varchar(20) NULL
            $table->string('postal_code', 20)->nullable();

            // 23) phone varchar(20) NULL
            $table->string('phone', 20)->nullable();
            $table->string('role', 100)->nullable();

            // 24) balance double(20,2) NOT NULL default 0.00
            $table->double('balance', 20, 2)->default(0.00);

            // 25) banned tinyint NOT NULL default 1
            $table->tinyInteger('banned')->default(1);

            // 26) referral_code varchar(255) NULL
            $table->string('referral_code', 255)->nullable();

            // 27) customer_package_id int NULL
            $table->integer('customer_package_id')->nullable();

            // 28) remaining_uploads int NULL default 0
            $table->integer('remaining_uploads')->nullable()->default(0);

            // 29-30) created_at, updated_at timestamps NULL
            $table->timestamps(); // nullable depends on DB; if you need strict NULL, see note below
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
