<?php

require __DIR__ . '/lib/bootstrap.php';

$pdo = db();

// Create migrations tracking table if it doesn't exist
$pdo->exec('
    CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        run_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )
');

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $filename = basename($file);
    $stmt = $pdo->prepare('SELECT id FROM migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetch()) {
        continue; // already run
    }
    $pdo->exec(file_get_contents($file));
    $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
    $stmt->execute([$filename]);
    echo "Ran migration: {$filename}\n";
}