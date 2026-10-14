<?php

// Opt-in regression against the .env MySQL/MariaDB connection. Only a temporary
// table is written; the application's tables and migration history stay untouched.
if (! in_array('--run', $argv, true)) {
    fwrite(STDERR, "Run explicitly: php tests/mysql-browsing-timestamps.php --run\n");
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$connection = DB::connection();
if ($connection->getDriverName() !== 'mysql') {
    throw new RuntimeException('This regression requires MySQL/MariaDB.');
}
$table = 'browsing_timestamp_test_'.bin2hex(random_bytes(6));
$originalMode = $connection->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    // Reproduce the actual legacy schema, then enable strict validation for
    // the migration. These mode changes affect only this test connection.
    $legacyMode = implode(',', array_diff(explode(',', $originalMode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
    $connection->statement('SET SESSION sql_mode = ?', [$legacyMode]);
    $connection->statement("CREATE TEMPORARY TABLE `{$table}` (
        id INT PRIMARY KEY,
        started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
        log_block_id INT NOT NULL,
        duration_seconds INT NOT NULL DEFAULT 0
    )");
    $connection->insert("INSERT INTO `{$table}` VALUES (1, '2026-01-01 10:00:00', '2026-01-01 10:00:00', 1, 0)");
    $strictMode = implode(',', array_unique([...explode(',', $originalMode), 'STRICT_TRANS_TABLES', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
    $connection->statement('SET SESSION sql_mode = ?', [$strictMode]);
    $legacyAlterFailed = false;
    try {
        $connection->statement("ALTER TABLE `{$table}` MODIFY `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    } catch (\Illuminate\Database\QueryException $error) {
        $legacyAlterFailed = (int) ($error->errorInfo[1] ?? 0) === 1067;
    }
    $assert($legacyAlterFailed, 'The old migration must reproduce error 1067 for the last_seen_at default.');
    $connection->update("UPDATE `{$table}` SET duration_seconds = 30 WHERE id = 1");
    $assert($connection->selectOne("SELECT started_at FROM `{$table}` WHERE id = 1")->started_at !== '2026-01-01 10:00:00',
        'The legacy schema must reproduce start-time corruption.');

    $migration = require __DIR__.'/../database/migrations/2026_10_14_000037_stop_browsing_start_timestamp_auto_updates.php';
    $queries = $connection->pretend(fn () => $migration->up());
    $assert(count($queries) === 1, 'Expected one schema statement.');
    $original = '`'.str_replace('`', '``', $connection->getTablePrefix().'browsing_activities').'`';
    $sql = str_replace($original, '`'.$table.'`', $queries[0]['query'], $replacements);
    $assert($replacements === 1 && ! str_contains($sql, $original), 'Schema test must target only its temporary table.');
    $before = $connection->selectOne("SELECT * FROM `{$table}` WHERE id = 1");
    $connection->statement($sql);
    $assert($before == $connection->selectOne("SELECT * FROM `{$table}` WHERE id = 1"), 'Migration must preserve stored values.');

    $connection->update("UPDATE `{$table}` SET started_at = '2026-01-01 10:00:00' WHERE id = 1");
    $connection->update("UPDATE `{$table}` SET last_seen_at = '2026-01-01 10:01:00', duration_seconds = 60 WHERE id = 1");
    $connection->update("UPDATE `{$table}` SET log_block_id = 2 WHERE id = 1");
    $assert($connection->selectOne("SELECT started_at FROM `{$table}` WHERE id = 1")->started_at === '2026-01-01 10:00:00',
        'Heartbeats and regrouping must preserve the original start.');
    $connection->statement($sql);
    $assert($connection->selectOne("SELECT started_at FROM `{$table}` WHERE id = 1")->started_at === '2026-01-01 10:00:00',
        'Repeating the migration must preserve the original start.');
    $row = $connection->selectOne("SELECT * FROM `{$table}` WHERE id = 1");
    $assert($row->last_seen_at === '2026-01-01 10:01:00', 'Reassignment must also preserve last_seen_at.');
    echo "PASS: reproduced error 1067 and legacy corruption; fixed migration passes strict mode and preserves data; heartbeat and regrouping keep timestamps; repeat is safe.\n";
} finally {
    $connection->statement("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
    $connection->statement('SET SESSION sql_mode = ?', [$originalMode]);
}
