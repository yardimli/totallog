<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_browser_logins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('challenge', 64);
            $table->string('state', 64);
            $table->string('device_name', 100);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('expected_user_id')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_browser_logins');
    }
};
