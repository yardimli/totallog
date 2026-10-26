<?php

// Opt-in regression: uses only a temporary table on the configured MySQL
// connection; application records and migration history are untouched.
if (! in_array('--run', $argv, true)) {
    fwrite(STDERR, "Run explicitly: php tests/mysql-event-timestamps.php --run\n");
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
$table = 'event_timestamp_test_'.bin2hex(random_bytes(6));
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    $connection->statement("CREATE TEMPORARY TABLE `{$table}` (
        id INT PRIMARY KEY,
        occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        latitude DECIMAL(10,7) NULL,
        city VARCHAR(255) NULL
    )");
    $connection->insert("INSERT INTO `{$table}` (id, occurred_at) VALUES (1, '2026-01-01 19:05:00')");
    $connection->update("UPDATE `{$table}` SET latitude = 25.0330 WHERE id = 1");
    $assert($connection->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 1")->occurred_at !== '2026-01-01 19:05:00',
        'Legacy location save must reproduce the overwritten occurrence time.');

    $migration = require __DIR__.'/../database/migrations/2026_10_26_000039_stop_event_timestamp_auto_updates.php';
    $queries = $connection->pretend(fn () => $migration->up());
    $assert(count($queries) === 1, 'Expected one schema statement.');
    $original = '`'.str_replace('`', '``', $connection->getTablePrefix().'task_events').'`';
    $sql = str_replace($original, '`'.$table.'`', $queries[0]['query'], $replacements);
    $assert($replacements === 1 && ! str_contains($sql, $original), 'Migration test must target only the temporary table.');
    $before = $connection->selectOne("SELECT * FROM `{$table}` WHERE id = 1");
    $connection->statement($sql);
    $assert($before == $connection->selectOne("SELECT * FROM `{$table}` WHERE id = 1"), 'Migration must preserve all stored values.');

    $connection->insert("INSERT INTO `{$table}` (id, occurred_at) VALUES (2, '2026-01-01 19:05:00')");
    $connection->update("UPDATE `{$table}` SET latitude = 25.0330, city = 'Taipei' WHERE id = 2");
    $assert($connection->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 2")->occurred_at === '2026-01-01 19:05:00',
        'Location save must preserve the browser occurrence time.');
    $connection->update("UPDATE `{$table}` SET occurred_at = '2026-01-01 20:10:00' WHERE id = 2");
    $connection->update("UPDATE `{$table}` SET city = 'New Taipei' WHERE id = 2");
    $assert($connection->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 2")->occurred_at === '2026-01-01 20:10:00',
        'Explicit time edits must work and survive subsequent location updates.');
    $connection->statement($sql);
    $assert($connection->selectOne("SELECT occurred_at FROM `{$table}` WHERE id = 2")->occurred_at === '2026-01-01 20:10:00',
        'Repeating the migration must preserve occurrence time.');
    echo "PASS: reproduced legacy location-save timestamp overwrite; migration preserves data; location saves preserve browser time; explicit edits remain supported.\n";
} finally {
    $connection->statement("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
}
