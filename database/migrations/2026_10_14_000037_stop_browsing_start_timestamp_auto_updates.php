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

        // Keep the TIMESTAMP type and all stored values. Supplying an explicit
        // default without ON UPDATE removes the implicit legacy auto-update rule.
        $table = str_replace('`', '``', DB::connection()->getTablePrefix().'browsing_activities');
        DB::statement("ALTER TABLE `{$table}` MODIFY `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }

    public function down(): void
    {
        // Intentionally retain the safe definition: restoring ON UPDATE would
        // corrupt original session start times again as soon as a row changes.
    }
};
