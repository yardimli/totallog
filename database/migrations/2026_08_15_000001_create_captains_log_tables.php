<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('openrouter_api_key')->nullable();
        });

        Schema::create('daily_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->timestamps();
            $table->unique(['user_id', 'log_date']);
        });

        Schema::create('task_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 20)->default('indigo');
            $table->boolean('is_sticky')->default(false);
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'is_sticky']);
        });

        Schema::create('log_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_log_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->longText('content')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['daily_log_id', 'position']);
        });

        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('log_block_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('task_name', 80);
            $table->string('selected_value', 100)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['daily_log_id', 'task_definition_id', 'occurred_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('log_block_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['daily_log_id', 'type']);
        });

        Schema::create('api_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('log_block_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 32);
            $table->string('model', 191)->nullable();
            $table->string('request_id', 191)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost', 14, 8)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'daily_log_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_calls');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('log_blocks');
        Schema::dropIfExists('task_definitions');
        Schema::dropIfExists('daily_logs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('openrouter_api_key'));
    }
};
