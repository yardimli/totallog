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

        // Preserve stored values and the TIMESTAMP type, but remove the legacy
        // implicit ON UPDATE rule that changes occurrence time on location saves.
        $table = str_replace('`', '``', DB::connection()->getTablePrefix().'task_events');
        DB::statement("ALTER TABLE `{$table}` MODIFY `occurred_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }

    public function down(): void
    {
        // Do not restore a rule that corrupts timestamps on unrelated updates.
    }
};
