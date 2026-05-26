<?php
// fix_locks.php
// Run this on your VPS to fix database locks: php fix_locks.php

echo "==========================================\n";
echo " L1mk Database Lock Fixer\n";
echo "==========================================\n";

// 1. Identify DB path
$dbPath = __DIR__ . '/database.sqlite';
if (!file_exists($dbPath)) {
    echo "[INFO] Primary database not found at $dbPath\n";
    // Check fallback
    $dbPath = __DIR__ . '/session_data/database.sqlite';
    if (!file_exists($dbPath)) {
        die("[ERROR] Could not find database.sqlite anywhere!\n");
    }
}
echo "[OK] Found database at: $dbPath\n";

// 2. Kill running worker processes (Linux/VPS specific)
echo "[ACTION] Attempting to stop existing worker processes...\n";
exec("pkill -f 'php.*worker'", $output, $returnVar);
if ($returnVar === 0) {
    echo "[OK] Worker processes killed.\n";
} else {
    echo "[INFO] No worker processes found or permission denied (Exit Code: $returnVar)\n";
}

// 3. Reset file permissions
echo "[ACTION] Resetting file permissions...\n";
if (chmod($dbPath, 0666)) {
    echo "[OK] Database permissions set to 0666.\n";
} else {
    echo "[WARN] Failed to chmod database file.\n";
}

$dir = dirname($dbPath);
if (chmod($dir, 0777)) {
    echo "[OK] Directory permissions set to 0777.\n";
} else {
    echo "[WARN] Failed to chmod directory.\n";
}

// 4. Force WAL mode and Checkpoint
echo "[ACTION] Optimizing database (WAL mode)...\n";
try {
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Force WAL
    $pdo->exec('PRAGMA journal_mode = DELETE;'); // Reset first
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $res = $pdo->query('PRAGMA journal_mode;')->fetchColumn();
    echo "[OK] Journal Mode is now: $res\n";
    
    // Checkpoint to clean up WAL file
    echo "[ACTION] Running WAL Checkpoint...\n";
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');
    echo "[OK] WAL Checkpoint complete.\n";
    
} catch (Exception $e) {
    echo "[ERROR] Database optimization failed: " . $e->getMessage() . "\n";
}

echo "\n==========================================\n";
echo " DONE! You can now restart your worker:\n";
echo " php index.php worker\n";
echo "==========================================\n";
