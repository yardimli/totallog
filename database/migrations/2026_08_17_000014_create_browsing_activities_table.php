<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensors', function (Blueprint $table) {
            $table->char('pairing_key_hash', 64)->nullable()->unique()->after('token');
        });

        Schema::create('browsing_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('log_block_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('client_id', 64)->nullable();
            // An explicit default prevents legacy MySQL/MariaDB from silently
            // adding ON UPDATE CURRENT_TIMESTAMP to this first TIMESTAMP column.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['daily_log_id', 'domain']);
            $table->index(['user_id', 'client_id', 'ended_at'], 'browsing_user_client_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browsing_activities');
        Schema::table('sensors', function (Blueprint $table) {
            $table->dropUnique(['pairing_key_hash']);
            $table->dropColumn('pairing_key_hash');
        });
    }
};
