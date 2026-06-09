<?php
// apply_structure.php - Applies permissions and structure from structure.json
$jsonFile = __DIR__ . '/structure.json';
if (!file_exists($jsonFile)) {
    echo "⚠️ structure.json not found, skipping detailed permission setup.\n";
    exit(0);
}

$structure = json_decode(file_get_contents($jsonFile), true);
if (!$structure) {
    echo "❌ Failed to parse structure.json\n";
    exit(1);
}

echo "🛠 Applying structure and permissions...\n";

foreach ($structure as $item) {
    $path = __DIR__ . '/' . $item['path'];
    $type = $item['type'];
    $perms = octdec($item['perms']);

    if ($type === 'dir') {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
            echo "📁 Created directory: {$item['path']}\n";
        }
    }

    if (file_exists($path)) {
        if (chmod($path, $perms)) {
            // echo "✅ Set perms {$item['perms']} on {$item['path']}\n";
        } else {
            echo "⚠️ Failed to set perms on {$item['path']}\n";
        }
    }
}

// Special case for database and logs to ensure they are writable
$writableFiles = ['database.sqlite', 'project.log', 'deploy.log'];
foreach ($writableFiles as $f) {
    if (file_exists(__DIR__ . '/' . $f)) {
        chmod(__DIR__ . '/' . $f, 0666);
    }
}

$writableDirs = ['session_data'];
foreach ($writableDirs as $d) {
    if (!is_dir(__DIR__ . '/' . $d)) mkdir(__DIR__ . '/' . $d, 0777, true);
    chmod(__DIR__ . '/' . $d, 0777);
}

echo "✅ Structure applied successfully.\n";
