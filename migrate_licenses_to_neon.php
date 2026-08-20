<?php
/**
 * One-time migration: Copy licenses from SQLite (license_bot.db) to Neon PostgreSQL.
 * 
 * The Telegram bot now writes directly to Neon, but existing licenses
 * in the old SQLite database need to be migrated.
 * 
 * Run: php migrate_licenses_to_neon.php
 */

echo "=== License Migration: SQLite → Neon PostgreSQL ===\n\n";

// Step 1: Load .env for Neon credentials
$envPath = __DIR__ . '/.env';
$env = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim(trim($value), '"\'');
    }
}

// Step 2: Connect to SQLite
$sqlitePaths = [
    __DIR__ . '/license_bot.db',
    '/data/license_bot.db',
];

$sqlitePdo = null;
foreach ($sqlitePaths as $dbPath) {
    if (is_file($dbPath)) {
        echo "[SQLite] Found database at: $dbPath\n";
        try {
            $sqlitePdo = new PDO("sqlite:$dbPath");
            $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            break;
        } catch (Exception $e) {
            echo "[SQLite] Error connecting: " . $e->getMessage() . "\n";
        }
    }
}

if (!$sqlitePdo) {
    echo "[SQLite] No SQLite database found. Nothing to migrate.\n";
    exit(0);
}

// Read licenses from SQLite
$licenses = $sqlitePdo->query("SELECT license_key, status, expires_at, created_at FROM licenses")->fetchAll(PDO::FETCH_ASSOC);
echo "[SQLite] Found " . count($licenses) . " licenses.\n";

if (empty($licenses)) {
    echo "[SQLite] No licenses to migrate.\n";
    exit(0);
}

// Step 3: Connect to Neon PostgreSQL
$neonUrl = getenv('NEON_DATABASE_URL') ?: ($env['NEON_DATABASE_URL'] ?? '');
$neonUrl = trim($neonUrl, '"\'');
if (empty($neonUrl)) {
    die("[Neon] ERROR: NEON_DATABASE_URL not found.\n");
}

echo "[Neon] Connecting to PostgreSQL...\n";
$urlParts = parse_url($neonUrl);
$host = $urlParts['host'] ?? '';
$port = $urlParts['port'] ?? '5432';
$user = $urlParts['user'] ?? '';
$pass = $urlParts['pass'] ?? '';
$dbname = ltrim($urlParts['path'] ?? '', '/');

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;user=$user;password=$pass";
// Neon SNI workaround: pass endpoint ID explicitly for older libpq
if (strpos($host, 'neon.tech') !== false) {
    $dsn .= ';options=endpoint=' . explode('.', $host)[0];
}
$neonPdo = new PDO($dsn);
$neonPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo "[Neon] Connected successfully.\n";

// Step 4: Migrate
$inserted = 0;
$skipped = 0;
$errors = 0;

$checkStmt = $neonPdo->prepare("SELECT COUNT(*) FROM licenses WHERE license_key = ?");
$insertStmt = $neonPdo->prepare(
    "INSERT INTO licenses (license_key, status, expires_at, created_at) 
     VALUES (?, ?, ?, ?) 
     ON CONFLICT (license_key) DO UPDATE SET 
       status = EXCLUDED.status,
       expires_at = EXCLUDED.expires_at"
);

foreach ($licenses as $lic) {
    $key = $lic['license_key'];
    $status = $lic['status'];
    $expires = $lic['expires_at'];
    $created = $lic['created_at'];
    
    // Convert SQLite integer created_at to ISO 8601 if needed
    if (is_numeric($created)) {
        $created = date('c', (int)$created);
    }
    
    try {
        $checkStmt->execute([$key]);
        $exists = $checkStmt->fetchColumn();
        
        if ($exists > 0) {
            echo "  ⏭  Already exists: $key\n";
            $skipped++;
        } else {
            $insertStmt->execute([$key, $status, $expires, $created]);
            echo "  ✅ Migrated: $key (status=$status, expires=$expires)\n";
            $inserted++;
        }
    } catch (Exception $e) {
        echo "  ❌ Error migrating $key: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Migration Complete ===\n";
echo "  Inserted: $inserted\n";
echo "  Skipped (already in Neon): $skipped\n";
echo "  Errors: $errors\n";
