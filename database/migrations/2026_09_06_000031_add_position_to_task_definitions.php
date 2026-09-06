<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_definitions', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('user_id');
            $table->index(['user_id', 'position']);
        });

        $positions = [];
        DB::table('task_definitions')
            ->orderBy('user_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'user_id'])
            ->each(function ($task) use (&$positions) {
                $position = $positions[$task->user_id] ?? 0;
                DB::table('task_definitions')->where('id', $task->id)->update(['position' => $position]);
                $positions[$task->user_id] = $position + 1;
            });
    }

    public function down(): void
    {
        Schema::table('task_definitions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
