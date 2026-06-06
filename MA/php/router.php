<?php
// router.php for local development
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Let PHP's built-in server handle deploy_tool directly
if (strpos($path, '/deploy_tool') === 0) {
    return false;
}

$file = dirname(__DIR__) . $path;

// Serve existing files and directories directly
if (file_exists($file)) {
    if (is_dir($file)) {
        return false;
    }
    // PHP files must go through index.php (handles api.php, etc.)
    if (substr($file, -4) === '.php') {
        require_once dirname(__DIR__) . '/index.php';
        return;
    }
    // Static assets: serve directly
    return false;
}

// Otherwise, route through the main application
require_once dirname(__DIR__) . '/index.php';
