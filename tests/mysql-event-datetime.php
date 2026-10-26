<?php

// Opt-in integration test; all writes target temporary tables only.
if (! in_array('--run', $argv, true)) {
    fwrite(STDERR, "Run explicitly: php tests/mysql-event-datetime.php --run\n");
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection();
if ($db->getDriverName() !== 'mysql') {
    throw new RuntimeException('This test requires MySQL/MariaDB.');
}
$suffix = bin2hex(random_bytes(6));
$tables = ['task_events' => 'test_event_datetime_'.$suffix, 'log_blocks' => 'test_block_datetime_'.$suffix];
$timezone = $db->selectOne('SELECT @@SESSION.time_zone AS zone')->zone;
$assert = function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
try {
    $db->statement("SET SESSION time_zone = '+08:00'");
    foreach ($tables as $name => $table) {
        $definition = $name === 'task_events'
            ? 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            : 'TIMESTAMP NULL DEFAULT NULL';
        $db->statement("CREATE TEMPORARY TABLE `{$table}` (id INT PRIMARY KEY, occurred_at {$definition}, related_id INT NOT NULL, city VARCHAR(80) NULL, INDEX occurrence_index (occurred_at))");
        $db->insert("INSERT INTO `{$table}` (id, occurred_at, related_id) VALUES (1, '2026-01-01 19:05:00', 11), (2, '2026-01-02 00:00:00', 12)");
        if ($name === 'log_blocks') {
            $db->insert("INSERT INTO `{$table}` (id, occurred_at, related_id) VALUES (3, NULL, 13)");
        }
    }
    $migration = require __DIR__.'/../database/migrations/2026_10_26_000040_use_datetime_for_event_occurrence.php';
    $queries = $db->pretend(fn () => $migration->up());
    $assert(count($queries) === 2, 'Expected two column conversions.');
    foreach (array_keys($tables) as $index => $name) {
        $table = $tables[$name];
        $original = '`'.str_replace('`', '``', $db->getTablePrefix().$name).'`';
        $sql = str_replace($original, '`'.$table.'`', $queries[$index]['query'], $count);
        $assert($count === 1 && ! str_contains($sql, $original), 'Must modify only the temporary table.');
        $before = $db->select("SELECT * FROM `{$table}` ORDER BY id");
        $db->statement($sql);
        $assert($before == $db->select("SELECT * FROM `{$table}` ORDER BY id"), 'All values, IDs, and relations must survive conversion.');
        $column = collect($db->select("SHOW COLUMNS FROM `{$table}`"))->firstWhere('Field', 'occurred_at');
        $assert($column->Type === 'datetime' && $column->Default === null && $column->Extra === '', 'Must have DATETIME without an automatic default or update rule.');
        $assert($column->Null === ($name === 'task_events' ? 'NO' : 'YES'), 'Nullability must be preserved.');
        $assert(count($db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = 'occurrence_index'")) === 1, 'Occurrence index must survive.');
        $db->update("UPDATE `{$table}` SET city = 'Taipei' WHERE id = 1");
        $assert($db->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 1")->occurred_at === '2026-01-01 19:05:00', 'Unrelated writes must not alter occurrence time.');
        $db->statement("SET SESSION time_zone = '+00:00'");
        $assert($db->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 1")->occurred_at === '2026-01-01 19:05:00', 'A different session timezone must not shift DATETIME.');
        $db->statement("SET SESSION time_zone = '+08:00'");
        $db->update("UPDATE `{$table}` SET occurred_at = '2026-01-01 20:10:00' WHERE id = 1");
        $db->statement($sql);
        $assert($db->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 1")->occurred_at === '2026-01-01 20:10:00', 'Explicit edits and repeated conversion must work.');
    }
    echo "PASS: DATETIME conversion preserves every existing value, ID, link, index, and null; location writes and timezone changes cannot alter the occurrence time.\n";
} finally {
    foreach ($tables as $table) $db->statement("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
    $db->statement('SET SESSION time_zone = ?', [$timezone]);
}
