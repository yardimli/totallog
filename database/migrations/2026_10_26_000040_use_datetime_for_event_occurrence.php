<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Convert in the application's normal database session timezone, keeping
        // the wall-clock values it currently reads. Do not change IDs, relations,
        // indexes, or nullability, and do not shift or reconstruct old times.
        foreach (['task_events' => 'NOT NULL', 'log_blocks' => 'NULL DEFAULT NULL'] as $name => $nullable) {
            $table = str_replace('`', '``', DB::connection()->getTablePrefix().$name);
            DB::statement("ALTER TABLE `{$table}` MODIFY `occurred_at` DATETIME {$nullable}");
        }
    }

    public function down(): void
    {
        // Keep DATETIME: reverting to TIMESTAMP can shift values and reject
        // dates outside TIMESTAMP's range. The application supports both types.
    }
};
