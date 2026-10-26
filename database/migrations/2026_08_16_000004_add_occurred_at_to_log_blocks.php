<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_blocks', function (Blueprint $table) {
            $table->dateTime('occurred_at')->nullable()->after('position');
            $table->index(['daily_log_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('log_blocks', function (Blueprint $table) {
            $table->dropIndex(['daily_log_id', 'occurred_at']);
            $table->dropColumn('occurred_at');
        });
    }
};
