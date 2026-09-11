<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
        });
        Schema::create('mobile_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('edited_at', 32);
            $table->boolean('deleted')->default(false);
            $table->json('payload')->nullable();
            $table->unique(['user_id', 'entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_revisions');
        Schema::dropIfExists('mobile_operations');
    }
};
