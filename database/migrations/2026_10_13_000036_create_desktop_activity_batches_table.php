<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_activity_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->string('request_hash', 64);
            $table->json('activity_ids');
            $table->timestamps();
            $table->unique(['user_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_activity_batches');
    }
};
