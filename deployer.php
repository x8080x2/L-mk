<?php

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;

/**
 * L1mk Deployer - Single File Application
 * 
 * Logic consolidated and streamlined.
 * 
 * @author L1mk
 * @version 2.2
 */

ini_set('memory_limit', '512M');
set_time_limit(0);

class Deployer
{
    private $serverConfig = [];
    private $currentLicense = '';
    private $isMaster = false;
    private $pdo = null;

    public function __construct()
    {
        // Load .env file
        if (file_exists(__DIR__ . '/.env')) {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        }

        $this->loadConfig();
    }



    public function run()
    {
        if (php_sapi_name() === 'cli-server') {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|woff|ttf|eot|map)$/i', $path)) {
                return false; 
            }
        }

        if (php_sapi_name() === 'cli') {
            $this->runCliMode();
            return;
        }

        if (!$this->validateLicense()) return;

        // Close session write to prevent locking
        session_write_close();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $action = $_GET['action'] ?? '';

        if ($method === 'POST' && $action !== '') {
            // Only load heavy dependencies when performing an action
            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                require_once __DIR__ . '/vendor/autoload.php';
            } else {
                $this->jsonResponse('error', 'Missing vendor dependencies. Run "php composer.phar install" to fix.');
                return;
            }
            $this->handleApi($action);
        } else {
            $this->renderDashboard();
        }
    }

    private function runCliMode()
    {
        global $argv;
        $cliAction = $argv[1] ?? 'deploy';

        if (!in_array($cliAction, ['deploy', 'update', 'inspect_structure', 'inventory', 'view_logs', 'stop_worker', 'restart_worker', 'delete_uninstall', 'chrome_status', 'test_cf', 'fix_server'], true)) {
            $cliAction = 'deploy';
        }

        if (empty($this->serverConfig)) {
            echo "Configuration not found (Database or File).\n";
            exit(1);
        }
        
        // Use first server config for CLI
        $data = isset($this->serverConfig[0]) ? $this->serverConfig[0] : $this->serverConfig;
        
        $data['save_config'] = 'true';
        
        $master = $this->getMasterKey();
        if ($master !== '' && !isset($data['license']) && !isset($data['license_key'])) {
            $data['license_key'] = $master;
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['action'] = $cliAction;
        $GLOBALS['_POST'] = $data;

        if (!$this->validateLicense()) {
            echo "[ERROR] License validation failed.\n";
            exit(1);
        }

        $this->handleApi($cliAction);
    }

    private function handleApi($action)
    {
        // echo "DEBUG ACTION: " . $action . "\n";
        $jsonActions = ['add_server', 'delete_server', 'save_server', 'get_servers', 'test_connection', 'domain_status', 'inspect_structure', 'inventory', 'stop_worker', 'restart_worker', 'chrome_status', 'view_logs'];
        if (!in_array($action, $jsonActions)) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
        } else {
            header('Content-Type: application/json');
        }

        $req = $this->parseDeployRequest($_POST);
        extract($req);

        // Get rotation config for existing server if applicable
        $rotation_slugs = [];
        $rotation_path = '';
        if (!empty($server_id)) {
            foreach ($this->serverConfig as $s) {
                if (($s['id'] ?? '') === $server_id) {
                    $rotation_slugs = $s['rotation_slugs'] ?? [];
                    $rotation_path = $s['rotation_path'] ?? '';
                    break;
                }
            }
        }
        $req['rotation_path'] = $rotation_path;
        $req['rotation_slugs'] = $rotation_slugs;

        try {
            switch ($action) {
                case 'get_servers':
                    $this->apiGetServers();
                    break;
                case 'test_connection':
                    $this->connectSsh($host, $port, $user, $password);
                    $this->jsonResponse('success', 'Connection successful!');
                    break;
                case 'view_logs':
                    $this->apiViewLogs($host, $port, $user, $password, $path);
                    break;
                case 'inspect_structure':
                    $this->apiInspectStructure($host, $port, $user, $password, $path);
                    break;
                case 'inventory':
                    $this->apiInventory($host, $port, $user, $password, $path);
                    break;

                case 'domain_status':
                    $this->apiDomainStatus($host, $port, $user, $password, $main_domain, $domains);
                    break;
                case 'add_server':
                    $this->apiAddServer($req);
                    break;
                case 'delete_server':
                    $this->apiDeleteServer($_POST['server_id'] ?? '');
                    break;
                case 'save_server':
                    $this->apiSaveServer($req, $rotation_path);
                    break;
                case 'apply_domains':
                    $this->apiApplyDomains($req, $rotation_path, $rotation_slugs);
                    break;
                case 'remove_domains_only':
                    $this->apiRemoveDomainsOnly($host, $port, $user, $password, $main_domain);
                    break;
                case 'update_code':
                    $this->deployPackage($req, 'Code Update');
                    break;
                case 'deploy':
                case 'update':
                    $this->apiDeploy($req, $action);
                    break;
                case 'ssl':
                    $this->apiSsl($req);
                    break;
                case 'delete_uninstall':
                    $this->apiDeleteUninstall($req);
                    break;
                case 'fix_server':
                    $this->apiFixNginx($host, $port, $user, $password);
                    break;
                case 'stop_worker':
                    $this->apiStopWorker($host, $port, $user, $password, $path);
                    break;
                case 'restart_worker':
                    $this->apiRestartWorker($host, $port, $user, $password, $path);
                    break;
                case 'chrome_status':
                    $this->apiChromeStatus($host, $port, $user, $password, $path);
                    break;
                default:
                    $this->jsonResponse('error', 'Unknown action');
            }
        } catch (Exception $e) {
             if (headers_sent()) {
                 $this->sseMessage("Error: " . $e->getMessage(), 'error');
             } else {
                 $this->jsonResponse('error', $e->getMessage());
             }
        }
        exit;
    }

    // --- Streamlined API Methods ---

    private function apiViewLogs($host, $port, $user, $password, $path) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        $logs = ['deploy.log', 'project.log', 'worker.log', 'puppeteer.log'];
        $cmd = "cd " . escapeshellarg($path);
        $cmd .= " && printf '=== Paths ===\\n'";
        $cmd .= " && printf 'PROJECT_ROOT: %s\\n' \"$(pwd)\"";
        $cmd .= " && printf 'MA index: %s\\n' \"$(pwd)/index.php\"";
        $cmd .= " && printf 'MA api: %s\\n' \"$(pwd)/api.php\"";
        $cmd .= " && printf 'SESSION_DATA: %s\\n' \"$(pwd)/session_data\"";
        $cmd .= " && printf 'CHROME_CONFIG: %s\\n' \"$(pwd)/chrome_config\"";
        $cmd .= " && printf 'PUPPETEER_CACHE: %s\\n' \"$(pwd)/.cache/puppeteer\"";
        $cmd .= " && printf 'LOGS: %s %s %s\\n' \"$(pwd)/project.log\" \"$(pwd)/worker.log\" \"$(pwd)/puppeteer.log\"";
        $cmd .= " && printf '\\n'";
        // Show only project.log to avoid expensive operations, hide others
        $cmd .= " && printf '=== Project Log ===\\n'";
        $cmd .= " && if [ -f project.log ]; then tail -n 100 project.log; else echo 'No project.log found'; fi";
        $cmd .= " && printf '\\n'";
        // Keep other expensive operations disabled for Render performance
        $cmd .= " && echo '[Worker and Puppeteer logs - connect via SSH to view]'";
        $output = (string)$ssh->exec($sudo . "sh -lc " . escapeshellarg($cmd));
        $this->jsonResponse('success', '', ['logs' => $output]);
    }

    private function apiChromeStatus($host, $port, $user, $password, $path) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        $cmd = "cd " . escapeshellarg($path)
             . " && printf '=== Chrome Status ===\\n'"
             . " && (command -v google-chrome-stable || command -v chromium || command -v chromium-browser || echo 'Chrome/Chromium not found')"
             . " && printf '\\n=== Puppeteer Cache ===\\n'"
             . " && ls -la .cache/puppeteer/chrome 2>/dev/null || echo 'No Chrome cache'"
             . " && printf '\\n=== Snap Check ===\\n'"
             . " && (snap list | grep chromium || echo 'No snap Chromium')"
             . " && printf '\\n=== Permissions ===\\n'"
             . " && ls -ld chrome_config 2>/dev/null || echo 'No chrome_config directory'";
        $output = (string)$ssh->exec($sudo . "sh -lc " . escapeshellarg($cmd));
        $this->jsonResponse('success', '', ['chrome_status' => $output]);
    }

    private function apiStopWorker($host, $port, $user, $password, $_path) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        $ssh->exec($sudo . "pkill -f 'index.php worker' || true");
        $this->jsonResponse('success', 'Worker processes stopped');
    }

    private function apiRestartWorker($host, $port, $user, $password, $path) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        $this->restartWorker($ssh, $sudo, $path);
        $this->jsonResponse('success', 'Worker restarted');
    }

    private function apiInspectStructure($host, $port, $user, $password, $path) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        $cmd = "cd " . escapeshellarg($path)
             . " && pwd"
             . " && printf '\\n--- TOP LEVEL ---\\n'"
             . " && ls -la"
             . " && printf '\\n--- SRC DIR ---\\n'"
             . " && if [ -d src ]; then ls -la src; else echo 'NO src DIR'; fi"
             . " && printf '\\n--- CHECK FILES ---\\n'"
             . ' && for f in index.php src/App.php templates/template.html.enc MA/index.php MA/src/App.php templates/plain/admin.html; do if [ -e "$f" ]; then echo "EXISTS $f"; else echo "MISSING $f"; fi; done'
             . " && printf '\\n--- TREE (depth 3) ---\\n'"
             . " && find . -maxdepth 3 -mindepth 1 \\( -type d -o -type f \\) | head -n 200"
             . " && printf '\\n--- SERVICE STATUS ---\\n'"
             . " && systemctl is-active nginx || echo 'Nginx inactive'"
             . " && systemctl is-active php8.3-fpm || echo 'PHP-FPM inactive'"
             . " && printf '\\n--- FIREWALL ---\\n'"
             . " && ufw status verbose || echo 'No UFW'"
             . " && printf '\\n--- PORTS ---\\n'"
             . " && netstat -plnt || ss -plnt"
             . " && printf '\\n--- NGINX CONFIG ---\\n'"
             . " && cat /etc/nginx/sites-enabled/*"
             . " && printf '\\n--- NGINX ERROR LOG ---\\n'"
             . " && cat /var/log/nginx/error.log | tail -n 50"
             . " && printf '\\n--- CURL TEST ---\\n'"
             . " && curl -k -s -H \"Host: $(hostname -f)\" https://localhost/admin.html | head -n 20";
        $output = (string)$ssh->exec($sudo . "sh -lc " . escapeshellarg($cmd));
        $this->jsonResponse('success', '', ['structure' => $output]);
    }

    private function apiFixNginx($host, $port, $user, $password) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        $cmd = "printf '=== NGINX TEST ===\\n'"
             . " && nginx -t"
             . " && printf '\\n=== RESTARTING ===\\n'"
             . " && systemctl stop nginx"
             . " && pkill nginx || true"
             . " && systemctl start nginx"
             . " && printf '\\n=== STATUS ===\\n'"
             . " && systemctl status nginx --no-pager"
             . " && printf '\\n=== PORTS ===\\n'"
             . " && netstat -plnt || ss -plnt";
        $output = (string)$ssh->exec($sudo . "sh -lc " . escapeshellarg($cmd));
        $this->jsonResponse('success', '', ['fix_output' => $output]);
    }

    private function apiInventory($host, $port, $user, $password, $path) {
        [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
        
        // Removed expensive system checks for Render performance
        $cmd = "printf '=== SYSTEM INFO ===\\n' && uname -a && uptime && echo"
             . " && printf '\\n=== PROJECT FILES ===\\n' && cd " . escapeshellarg($path) . " && ls -la";
             
        $output = (string)$ssh->exec($sudo . "sh -lc " . escapeshellarg($cmd));
        $this->jsonResponse('success', '', ['inventory' => $output]);
    }

    private function apiDomainStatus($host, $port, $user, $password, $main_domain, $domains) {
        $targets = array_unique(array_filter(array_merge([$main_domain], $domains), fn($d) => $d !== ''));
        
        // External Check (Parallel)
        $mh = curl_multi_init();
        $handles = [];
        foreach ($targets as $d) {
            foreach (['http', 'https'] as $proto) {
                $ch = curl_init("$proto://$d");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_FOLLOWLOCATION => true]);
                curl_multi_add_handle($mh, $ch);
                $handles["$proto://$d"] = ['ch' => $ch, 'domain' => $d, 'proto' => $proto];
            }
        }
        
        $active = null;
        do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        while ($active && $mrc == CURLM_OK) { if (curl_multi_select($mh) != -1) { do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM); } }

        $results = [];
        foreach ($handles as $h) {
            $info = curl_getinfo($h['ch']);
            $results[$h['domain']][$h['proto']] = $info['http_code'];
            if ($info['primary_ip']) $results[$h['domain']]['ip'] = $info['primary_ip'];
            curl_multi_remove_handle($mh, $h['ch']);
        }

        // Internal Check - DISABLED for performance (SSH connection is too slow for auto-checks)
        /*
        $localCodes = [];
        try {
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
            $cmdList = [];
            foreach ($targets as $d) {
                // Run checks in parallel background processes
                $cmdList[] = "(echo \"$d:$(curl -Is -o /dev/null -w '%{http_code}' -H 'Host: $d' http://127.0.0.1 --max-time 2 || echo 0)\" &)";
            }
            // Add wait to ensure all background processes complete
            $fullCmd = implode(' ', $cmdList) . " wait";
            $rawOut = (string)$ssh->exec($sudo . "sh -c " . escapeshellarg($fullCmd));
            foreach (explode("\n", trim($rawOut)) as $line) {
                if (strpos($line, ':') !== false) {
                    [$d, $c] = explode(':', trim($line), 2);
                    $localCodes[$d] = intval($c);
                }
            }
        } catch (Exception) {}
        */
        $localCodes = []; // Empty fallback

        $out = [];
        foreach ($targets as $d) {
            $http = $results[$d]['http'] ?? 0;
            $https = $results[$d]['https'] ?? 0;
            $local = $localCodes[$d] ?? 0;
            $dnsIp = $results[$d]['ip'] ?? gethostbyname($d);
            $primary = $https > 0 ? $https : $http;
            $publicLive = ($primary >= 200 && $primary < 400);
            $localLive = ($local >= 200 && $local < 400);
            
            // Special handling for Cloudflare/Proxy 403s
            $proxyBlocked = (!$publicLive && $primary === 403 && $localLive);
            
            $out[] = [
                'domain' => $d,
                'dns_ip' => $dnsIp === $d ? '' : $dnsIp,
                'matches_host' => ($dnsIp !== $d && $dnsIp === $host),
                'http_code' => $http,
                'https_code' => $https,
                'local_code' => $local,
                'live' => $publicLive,
                'local_live' => $localLive,
                'proxy_blocked' => $proxyBlocked
            ];
        }
        $this->jsonResponse('success', '', ['statuses' => $out]);
    }

    private function apiGetServers() {
        $srvs = $this->serverConfig;
        if ($this->currentLicense && !$this->isMaster) {
            $srvs = array_values(array_filter($srvs, fn($s) => ($s['license_key'] ?? '') === $this->currentLicense));
        }
        $this->jsonResponse('success', '', ['servers' => $srvs]);
    }

    private function apiAddServer($req) {
        extract($req);
        if ($this->currentLicense !== '' && !$this->isMaster) {
            $activeCount = 0;
            foreach ($this->serverConfig as $srv) {
                if (($srv['license_key'] ?? '') === $this->currentLicense) $activeCount++;
            }
            if ($activeCount >= 1) $this->jsonResponse('error', 'License limit reached (1 VPS per license).');
        }

        // Check for duplicates
        foreach ($this->serverConfig as $srv) {
            if (($srv['host'] ?? '') === $host && ($srv['path'] ?? '') === $path) {
                $this->jsonResponse('error', 'Server with this Host and Path already exists.');
                return;
            }
        }

        $this->connectSsh($host, $port, $user, $password); // Verify connection
        
        [$usedSlugs, $usedPaths] = $this->getUsedIdentifiers($this->serverConfig);
        $rotation_slugs = [$this->findUniqueString($usedSlugs, 30)];
        $rotation_path = $this->findUniqueString($usedPaths, 99);

        $newServer = array_merge($req, [
            'id' => uniqid('srv_'),
            'license_key' => $this->currentLicense,
            'rotation_slugs' => $rotation_slugs,
            'rotation_path' => $rotation_path,
            'rotation_enabled' => true
        ]);
        // Remove transient API fields
        unset($newServer['action'], $newServer['server_id']);
        
        $this->serverConfig[] = $newServer;
        $this->saveConfig($this->serverConfig);
        $this->jsonResponse('success', 'Server added successfully.', ['server' => $newServer]);
    }

    private function apiDeleteServer($id) {
        $this->serverConfig = array_values(array_filter($this->serverConfig, fn($s) => ($s['id'] ?? '') !== $id));
        $this->saveConfig($this->serverConfig);
        $this->jsonResponse('success', 'Server deleted.');
    }

    private function apiSaveServer($req, $rotation_path) {
        extract($req);
        $id = $_POST['server_id'] ?? '';
        foreach ($this->serverConfig as &$srv) {
            if (($srv['id'] ?? '') === $id) {
                $srv = array_merge($srv, [
                    'host' => $host, 'user' => $user, 'password' => $password, 'port' => $port,
                    'path' => $path, 'main_domain' => $main_domain, 'domains' => $domains,
                    'rotation_enabled' => true, 'wildcard_enabled' => $wildcard_enabled,
                    'local_path' => $local_path
                ]);
                unset($srv['domain']); // cleanup old key

                [$usedSlugs, $usedPaths] = $this->getUsedIdentifiers($this->serverConfig);
                if (empty($srv['rotation_slugs'])) $srv['rotation_slugs'] = [$this->findUniqueString($usedSlugs, 30)];
                if (empty($srv['rotation_path'])) {
                    $srv['rotation_path'] = !empty($rotation_path) ? $rotation_path : $this->findUniqueString($usedPaths, 99);
                }
                break;
            }
        }
        $this->saveConfig($this->serverConfig);
        $this->jsonResponse('success', 'Server updated.');
    }

    private function apiApplyDomains($req, $rotation_path, $rotation_slugs) {
        extract($req);
        
        // Auto-save the new domain configuration
        if (!empty($server_id)) {
            foreach ($this->serverConfig as &$srv) {
                if (($srv['id'] ?? '') === $server_id) {
                    $srv['main_domain'] = $main_domain;
                    $srv['domains'] = $domains;
                    $srv['wildcard_enabled'] = $wildcard_enabled;
                    break;
                }
            }
            $this->saveConfig($this->serverConfig);
        }

        $this->sseMessage("🌐 Applying domains to Nginx...");
        try {
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
            $this->applyNginxConfig($ssh, $sudo, $main_domain, $domains, $path, $rotation_enabled, $wildcard_enabled, $rotation_path, $rotation_slugs);
            $this->sseFinish("DONE_APPLY_DOMAINS", "Domain Update");
        } catch (Exception $e) {
            $this->sseMessage("❌ Apply domains error: " . $e->getMessage(), 'error');
        }
    }

    private function apiRemoveDomainsOnly($host, $port, $user, $password, $main_domain) {
        $this->sseMessage("🧹 Removing managed domains from Nginx...");
        try {
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
            $this->removeNginxConfig($ssh, $sudo, $this->nginxConfigKey($main_domain));
            $this->sseFinish("DONE_REMOVE_DOMAINS", "Domain Removal");
        } catch (Exception $e) {
            $this->sseMessage("❌ Remove domains error: " . $e->getMessage(), 'error');
        }
    }

    private function deployPackage($req, $label) {
        extract($req);
        $this->sseStart($label, "$user@$host");
        
        // Create a unique temporary build directory for isolation
        $buildId = uniqid('deploy_', true);
        $sysTemp = sys_get_temp_dir();
        $tempDir = $sysTemp . '/' . $buildId;
        
        if (!mkdir($tempDir, 0755, true)) {
            $this->sseMessage("❌ Failed to create temp build directory: $tempDir", 'error');
            return;
        }

        $baseDir = rtrim($local_path ?: __DIR__, '/');
        // $zipFile will now be inside the unique temp dir
        $zipFile = $tempDir . '/deploy_package.tar.gz';

        try {
            $this->sseMessage("🚀 Starting isolated deployment process (Build: $buildId)...");
            
            $originalSourceDir = $baseDir . '/MA';
            if (!is_dir($originalSourceDir)) {
                 if (is_dir(__DIR__ . '/MA')) {
                     $originalSourceDir = __DIR__ . '/MA';
                 } else {
                     throw new Exception("Local MA directory does not exist: $originalSourceDir");
                 }
            }
            
            // COPY source to temp dir to avoid race conditions on shared files
            $buildSourceDir = $tempDir . '/MA';
            $this->sseMessage("📦 Staging files for build...");
            // Use cp -r to copy. Exclude node_modules to speed up copy if present (shouldn't be in source but good practice)
            exec("cp -r " . escapeshellarg($originalSourceDir) . " " . escapeshellarg($buildSourceDir));

            $deploymentData = base64_encode(json_encode([
                'main_domain' => $main_domain,
                'domains' => $domains,
                'rotation_path' => $rotation_path,
                'rotation_slugs' => $rotation_slugs,
                'path' => $path
            ]));

            $envPayload = $this->getRemoteEnvPayload();

            // Encrypt templates IN THE TEMP COPY
            $this->encryptTemplates($buildSourceDir);

            // Create config files IN THE TEMP COPY
            file_put_contents($buildSourceDir . '/deployment.json', base64_decode($deploymentData));
            if ($envPayload) {
                file_put_contents($buildSourceDir . '/.env', base64_decode($envPayload));
            }

            // Create archive from the TEMP COPY
            $this->createTarArchive($buildSourceDir, $zipFile);

            $this->sseMessage("🔌 Connecting to VPS...");
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
            
            $this->sseMessage("📤 Uploading package...");
            
            $remotePath = $path;
            if (strpos($remotePath, '/') !== 0) {
                $remotePath = '/var/www/html/' . $remotePath;
                $this->sseMessage("ℹ️ Correcting relative path to: $remotePath");
            }
            
            $ssh->exec($sudo . "mkdir -p " . escapeshellarg($remotePath));
            
            if (method_exists($ssh, 'disconnect')) $ssh->disconnect();
            
            $sftp = new SFTP($host, $port);
            if (!$sftp->login($user, $password)) {
                throw new Exception("SFTP Login failed");
            }
            
            if (!$sftp->put($remotePath . '/deploy_package.tar.gz', $zipFile, SFTP::SOURCE_LOCAL_FILE)) {
                throw new Exception("Upload failed");
            }
            
            unset($sftp);
            
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);

            $this->sseMessage("⚙️ Extracting and configuring...");
            
            
            // Set unlimited timeout for long-running setup script
            $ssh->setTimeout(0);
            
            // Enhanced deployment commands with better error handling
            $combinedCmd = "cd " . escapeshellarg($remotePath) 
                . " && echo 'Extracting package...' && tar -xzf deploy_package.tar.gz"
                . " && echo 'Setting permissions...' && chmod +x setup.sh"
                . " && echo 'Running setup script...' && (./setup.sh > deploy.log 2>&1 || echo 'Setup script failed')"
                . " && echo 'Setup complete' && cat deploy.log";

            $outUnzip = (string)$ssh->exec($sudo . "bash -lc " . escapeshellarg($combinedCmd));
            
            $this->sseMessage("📦 Setup Output:\n" . $outUnzip);

            // Enhanced post-extract validation (use existing SSH connection)
            $checkCmd = "cd " . escapeshellarg($remotePath)
                . " && printf '=== Directory Structure ===\n' && ls -la"
                . " && printf '\n=== Source Directory ===\n' && (ls -la src 2>/dev/null || echo 'No src directory')"
                . " && printf '\n=== Template Files ===\n'"
                . ' && for f in templates/template.html.enc templates/admin.html.enc; do if [ -e "$f" ]; then echo "✅ $f"; else echo "❌ MISSING $f"; fi; done'
                . " && printf '\n=== Core Files ===\n'"
                . ' && for f in index.php src/App.php .env; do if [ -e "$f" ]; then echo "✅ $f"; else echo "❌ MISSING $f"; fi; done'
                . " && printf '\n=== Worker Status ===\n'"
                . " && (ps aux | grep 'index.php worker' | grep -v grep || echo '❌ WORKER NOT RUNNING')";
            $outCheck = (string)$ssh->exec($sudo . "bash -lc " . escapeshellarg($checkCmd));
            $this->sseMessage("📊 Status:\n" . $outCheck);

            $this->applyNginxConfig($ssh, $sudo, $main_domain, $domains, $remotePath, $rotation_enabled, $wildcard_enabled, $rotation_path, $rotation_slugs);
            $ssh->exec("$sudo systemctl reload nginx php*-fpm || true");

            // Clean up SSH connection
            if (method_exists($ssh, 'disconnect')) $ssh->disconnect();

            // Final cleanup of the zip file
            if (file_exists($zipFile)) @unlink($zipFile);

            $this->sseFinish("DONE_DEPLOY", $label);

        } catch (Exception $e) {
            $this->sseMessage("❌ Deployment failed: " . $e->getMessage(), 'error');
            $this->sseMessage("💡 Check the logs above for more details", 'info');
            if (file_exists($zipFile)) unlink($zipFile);
            throw $e;
        } finally {
            // Recursive cleanup of temp build directory
            if (isset($tempDir) && is_dir($tempDir)) {
                exec("rm -rf " . escapeshellarg($tempDir));
            }
        }
    }

    private function apiDeploy($req, $action) {
        $this->deployPackage($req, ($action === 'update' ? 'Update' : 'Deployment'));
    }

    private function apiSsl($req) {
        extract($req);
        $this->sseMessage("🔒 Starting Certbot...");
        try {
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
            if ($main_domain === '') throw new Exception("No main domain.");

            if (!$ssh->exec("command -v certbot")) {
                $this->sseMessage("Installing Certbot...");
                $ssh->exec("$sudo apt-get update && DEBIAN_FRONTEND=noninteractive $sudo apt-get install -y certbot python3-certbot-nginx");
            }

            $certDomains = array_unique(array_filter(array_merge([$main_domain], $domains), fn($d) => $d !== ''));
            $certNames = [];
            foreach ($certDomains as $d) {
                foreach ($this->domainVariants($d) as $v) $certNames[] = $v;
            }
            $certNames = array_unique($certNames);
            
            $args = implode(' ', array_map(fn($n) => "-d " . escapeshellarg($n), $certNames));
            $this->sseMessage("Requesting certs for: " . implode(', ', $certNames));
            
            $ssh->setTimeout(120);
            $out = (string)$ssh->exec("$sudo certbot --nginx $args --non-interactive --agree-tos --register-unsafely-without-email --redirect");
            $this->sseMessage($out);
            
            $this->sseFinish("DONE_SSL", "SSL Setup");
        } catch (Exception $e) {
            $this->sseMessage("❌ SSL Error: " . $e->getMessage(), 'error');
        }
    }

    private function apiDeleteUninstall($req) {
        extract($req);
        $this->sseMessage("⚠️ Starting cleanup...");
        try {
            [$ssh, $sudo] = $this->connectSsh($host, $port, $user, $password);
            $targetPath = trim((string)$path);
            if ($targetPath === '' || $targetPath === '/') {
                throw new Exception("Invalid path for uninstall.");
            }
            
            $this->sseMessage("📍 Target path: " . $targetPath);

            $targets = array_values(array_unique(array_filter(array_merge([$main_domain], $domains), fn($d) => $d !== '')));
            $nginxKeys = [];
            foreach ($targets as $d) $nginxKeys[] = $this->nginxConfigKey($d);
            $nginxKeys = array_values(array_unique(array_filter($nginxKeys)));

            $this->sseMessage("🗑️ Cleaning remote server...");

            $cmds = [];
            $cmds[] = "set +e";
            $cmds[] = "p=" . escapeshellarg($targetPath);
            $cmds[] = 'if [ ! -d "$p" ]; then echo "MISSING_PATH"; exit 0; fi';
            // Removed expensive file counting for Render performance
            $cmds[] = 'a=php; b=" index.php"; c=" worker"; pat="$a$b$c"; pkill -f "$pat" || true';
            $cmds[] = 'a=node; b=" .*consolidated.js"; pat="$a$b"; pkill -f "$pat" || true';
            foreach ($nginxKeys as $k) {
                $cmds[] = "rm -f /etc/nginx/sites-enabled/$k /etc/nginx/sites-available/$k 2>/dev/null || true";
            }
            $cmds[] = "nginx -t >/dev/null 2>&1 && systemctl reload nginx || true";
            $cmds[] = 'mkdir -p "$p"';
            $cmds[] = 'find "$p" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>/dev/null || true';
            $cmds[] = 'echo "Cleanup complete"';

            $remote = implode("; ", $cmds);
            $out = (string)$ssh->exec($sudo . "sh -lc " . escapeshellarg($remote) . " 2>&1");
            if (trim($out) !== '') $this->sseMessage($out);
            if (strpos($out, "MISSING_PATH") !== false) {
                throw new Exception("Target path does not exist.");
            }
            if (strpos($out, "Cleanup complete") === false) {
                throw new Exception("Uninstall did not complete.");
            }
            
            if (method_exists($ssh, 'disconnect')) $ssh->disconnect();

            $this->sseFinish("DONE_CLEANUP", "Uninstall");
        } catch (Exception $e) {
            $this->sseMessage("❌ Error: " . $e->getMessage(), 'error');
        }
    }

    // --- Helpers ---

    private function connectSsh($host, $port, $user, $password) {
        $ssh = new SSH2($host, $port);
        if (!$ssh->login($user, $password)) throw new Exception("SSH Login failed.");
        
        // Set connection timeout and keep-alive to prevent channel issues
        $ssh->setTimeout(300); // 5 minutes timeout
        $ssh->exec("export TMOUT=0"); // Disable SSH timeout
        
        return [$ssh, ($user === 'root' ? '' : 'sudo ')];
    }

    private function restartWorker($ssh, $sudo, $path) {
        $p = escapeshellarg($path);
        $ssh->exec("cd $p && $sudo bash ./setup.sh --worker-only");
    }

    private function applyNginxConfig($ssh, $sudo, $main, $domains, $path, $rot, $wild, $rotPath, $rotSlugs) {
        $leMap = [];
        $all = array_unique(array_filter(array_merge([$main], $domains)));
        foreach ($all as $d) {
            $check = $ssh->exec("if [ -f /etc/letsencrypt/live/" . escapeshellarg($d) . "/fullchain.pem ]; then echo 'Y'; fi");
            $leMap[$d] = (trim($check) === 'Y');
            if ($leMap[$d]) $this->sseMessage("ℹ️ Found LE certs for $d");
        }

        // Ensure self-signed exists as fallback
        $this->setupSslSelfSigned($ssh, $sudo);

        $config = $this->getNginxConfigMulti($main, $domains, $path, $rot, $wild, $rotPath, $rotSlugs, $leMap);
        
        // Improved PHP-FPM socket detection
        $sock = trim((string)$ssh->exec("find /var/run/php -name 'php*-fpm.sock' 2>/dev/null | head -n 1"));
        if (!$sock || strpos($sock, '/') !== 0) {
            // Fallback: try to find the version and guess the path
            $phpVer = trim((string)$ssh->exec("php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;' 2>/dev/null"));
            $sock = $phpVer ? "/var/run/php/php$phpVer-fpm.sock" : "/var/run/php/php-fpm.sock";
        }
        
        $config = str_replace('/var/run/php/php-fpm.sock', $sock, $config);

        $key = $this->nginxConfigKey($main);
        $this->writeNginx($ssh, $sudo, $key, $config);
        
        // Clean old aliases if they differ
        foreach ($domains as $d) {
            if ($d && ($ak = $this->nginxConfigKey($d)) !== $key) {
                $ssh->exec("$sudo rm /etc/nginx/sites-enabled/$ak /etc/nginx/sites-available/$ak 2>/dev/null || true");
            }
        }
        if (!$main) $ssh->exec("$sudo rm /etc/nginx/sites-enabled/default 2>/dev/null || true");
    }

    private function setupSslSelfSigned($ssh, $sudo) {
        $ssh->exec("$sudo mkdir -p /etc/nginx/ssl");
        if (trim($ssh->exec("if [ -f /etc/nginx/ssl/selfsigned.crt ]; then echo 'Y'; fi")) !== 'Y') {
            $this->sseMessage("🔑 Generating self-signed cert...");
            $ssh->exec("$sudo apt-get install -y openssl");
            $ssh->exec("$sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/nginx/ssl/selfsigned.key -out /etc/nginx/ssl/selfsigned.crt -subj '/C=US/ST=State/L=City/O=Organization/CN=localhost'");
        }
    }

    private function getRemoteEnvPayload() {
        $keys = ['APP_ENV', 'ENC_KEY', 'MASTER_LICENSE_KEY', 'LICENSE_KEY', 'PROXYCHECK_API_KEY'];
        $lines = [];
        $rootEnv = __DIR__ . '/.env';
        if (!file_exists($rootEnv)) {
            $rootEnv = dirname(__DIR__) . '/.env';
        }
        $fileEnv = is_file($rootEnv) ? parse_ini_file($rootEnv) : [];
        
        foreach ($keys as $k) {
            $v = getenv($k) ?: ($fileEnv[$k] ?? '');
            
            // Inject current license as MASTER_LICENSE_KEY and LICENSE_KEY
            if (($k === 'MASTER_LICENSE_KEY' || $k === 'LICENSE_KEY') && $this->currentLicense) {
                $v = $this->currentLicense;
            }
            
            if ($v) $lines[] = "$k=$v";
        }
        
        // Ensure MASTER_LICENSE_KEY is always set if we have a current license
        if ($this->currentLicense && !in_array("MASTER_LICENSE_KEY=$this->currentLicense", $lines)) {
             // Logic above should handle it, but this is a safety net
        }
        
        return $lines ? base64_encode(implode("\n", $lines) . "\n") : '';
    }

    private function encryptTemplates($sourceDir) {
        $templates = [
            'templates/plain/template.html' => 'templates/template.html.enc',
            'templates/plain/adobetemplate.html' => 'templates/adobetemplate.html.enc',
            'templates/plain/onedrivetemplate.html' => 'templates/onedrivetemplate.html.enc',
            'templates/plain/challenge.html' => 'templates/challenge.html.enc',
            'templates/plain/ErrorMsPAGE.html' => 'templates/ErrorMsPAGE.html.enc',
            'templates/plain/upload.html' => 'templates/upload.html.enc',
            'templates/plain/admin.html' => 'templates/admin.html.enc',
            'templates/plain/TeamsAudio.html' => 'templates/TeamsAudio.html.enc',
            'templates/plain/d0cu5i4n.html' => 'templates/d0cu5i4n.html.enc',
            'templates/plain/helper.html' => 'templates/helper.html.enc'
        ];
        
        foreach ($templates as $plain => $encrypted) {
            $plainPath = $sourceDir . '/' . $plain;
            if (file_exists($plainPath)) {
                $this->sseMessage("🔐 Encrypting: $plain");
                $cmd = "cd " . escapeshellarg($sourceDir) . " && php index.php manage encrypt " . escapeshellarg($plain);
                exec($cmd . " 2>&1", $output, $returnVar);
                
                if ($returnVar !== 0) {
                    $this->sseMessage("⚠️ Template encryption failed for $plain: " . implode("\n", $output), 'warning');
                } else {
                    $this->sseMessage("✅ Encrypted: $encrypted");
                }
            }
        }
    }

    private function createTarArchive($sourceDir, $outputTarGz) {
        if (file_exists($outputTarGz)) unlink($outputTarGz);
        
        // Simple command line tar (works on macOS/Linux)
        // cd to sourceDir so paths in tar are relative
        // Exclude node_modules, .cache and sensitive local config to prevent overwriting VPS settings
        $cmd = "cd " . escapeshellarg($sourceDir) . " && COPYFILE_DISABLE=1 tar -czf " . escapeshellarg($outputTarGz) . " --exclude='config.json.enc' --exclude='.git' --exclude='.idea' --exclude='.vscode' --exclude='session_data' --exclude='*.log' --exclude='.DS_Store' --exclude='node_modules' --exclude='.cache' .";
        
        exec($cmd . " 2>&1", $output, $returnVar);
        
        if ($returnVar !== 0) {
            $this->sseMessage("Tar failed: " . implode("\n", $output), 'error');
            throw new Exception("Tar packaging failed.");
        }
    }

    private function jsonResponse($status, $message, $data = []) {
        echo json_encode(array_merge(['status' => $status, 'message' => $message], $data));
    }

    private function sseStart($label, $target) {
        $this->sseMessage("🚀 Starting $label to $target...");
    }

    private function sseFinish($signal, $label = null) {
        if ($label) $this->sseMessage("✅ $label Finished Successfully!", 'success');
        $this->sseMessage($signal);
    }

    private function sseMessage($msg, $type = 'info') {
        echo "data: " . json_encode(['message' => $msg, 'type' => $type, 'time' => date('H:i:s')]) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }


    private function getMasterKey() {
        $k = getenv('MASTER_LICENSE_KEY');
        if (!$k && is_file($f = __DIR__ . '/.env')) {
            $p = parse_ini_file($f);
            $k = $p['MASTER_LICENSE_KEY'] ?? '';
        }
        return (string)$k ?: '8080';
    }

    private function validateLicense() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 86400 * 30, // Cookie lasts 30 days
                'gc_maxlifetime' => 10800 // Session data lasts 3 hours (3 * 3600)
            ]);
        }

        // Check inactivity (3 hours)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 10800)) {
            session_unset();
            session_destroy();
        }
        $_SESSION['last_activity'] = time();

        $key = trim($_POST['license'] ?? $_POST['license_key'] ?? $_SESSION['license_key'] ?? '');
        
        // Logout logic
        if (($_GET['action'] ?? '') === 'logout') {
            session_destroy();
            header("Location: ?");
            exit;
        }

        $master = $this->getMasterKey();
        
        $valid = ($master && $key && hash_equals($master, $key));
        
        // Optimization: Check session cache to avoid hitting DB on every request
        if (!$valid && isset($_SESSION['license_valid_until']) && $_SESSION['license_valid_until'] > time() && $key === ($_SESSION['license_key'] ?? '')) {
            $valid = true;
        }

        // Check for bot license in persistent storage first
        $pdo = $this->getPdo();

        // Add file-based cache to avoid database hits on Render
        $cacheFile = sys_get_temp_dir() . '/license_cache_' . md5($key) . '.json';
        if (!$valid && $key && file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true);
            if ($cache && $cache['valid'] && $cache['expires'] > time()) {
                $valid = true;
            }
        }

        if (!$valid && $key && $pdo) {
            try {
                // Check if licenses table exists (it might not if this is a fresh DB from getPdo)
                // But typically license_bot.db implies licenses table. 
                // We'll just try query.
                $stmt = $pdo->prepare('SELECT status, expires_at FROM licenses WHERE license_key = ? LIMIT 1');
                $stmt->execute([$key]);
                $row = $stmt->fetch();
                
                // DEBUG: Log validation attempt
                // error_log("License Check: Key=" . substr($key, 0, 5) . "... Row=" . json_encode($row));
                
                if ($row && $row['status'] === 'active' && $row['expires_at'] > gmdate('c')) {
                    $valid = true;
                    $_SESSION['license_valid_until'] = time() + 300; // Cache valid result for 5 minutes
                    
                    // Cache to file to avoid future database hits
                    $cacheData = ['valid' => true, 'expires' => time() + 300];
                    @file_put_contents($cacheFile, json_encode($cacheData));
                }
            } catch (Exception $e) {
                // Table might not exist or other error
                error_log("License Validation Error: " . $e->getMessage());
            }
        }
        
        $this->currentLicense = $key;
        if ($valid) {
            if ($master && hash_equals($master, $key)) $this->isMaster = true;
            $_SESSION['license_key'] = $key; // Save to session
            // Do NOT update MASTER_LICENSE_KEY - bot licenses should remain regular licenses
            return true;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET['action'])) {
            $this->jsonResponse('error', 'License required');
        } else {
            $this->renderLogin();
        }
        return false;
    }

    private function getPdo() {
        if ($this->pdo) return $this->pdo;

        $neonDatabaseUrl = $_ENV['NEON_DATABASE_URL'] ?? null;

        // Attempt PostgreSQL connection if NEON_DATABASE_URL is set
        if ($neonDatabaseUrl) {
            try {
                $urlParts = parse_url($neonDatabaseUrl);
                if ($urlParts === false) {
                    throw new Exception("Failed to parse NEON_DATABASE_URL.");
                }

                $host = $urlParts['host'] ?? '';
                $port = $urlParts['port'] ?? '5432';
                $user = $urlParts['user'] ?? '';
                $pass = $urlParts['pass'] ?? '';
                $path = $urlParts['path'] ?? '';
                $dbname = ltrim($path, '/');

                if (empty($host) || empty($user) || empty($dbname)) {
                    throw new Exception("Missing required components in NEON_DATABASE_URL.");
                }

                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;user=$user;password=$pass";
                $this->pdo = new PDO($dsn);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Create tables if they don't exist (for fresh deployments)
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS configurations (key TEXT PRIMARY KEY, value TEXT)");
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS licenses (license_key TEXT PRIMARY KEY, status TEXT, expires_at TEXT)");

                error_log("DEPLOYER DB: Connected to PostgreSQL using NEON_DATABASE_URL.");
                return $this->pdo;
            } catch (Exception $e) {
                error_log("DEPLOYER DB: PostgreSQL connection failed: " . $e->getMessage() . ". Not falling back to SQLite as requested.");
                return null;
            }
        }

        error_log("DEPLOYER DB: NEON_DATABASE_URL is not set. No database connection will be established.");
        return null;
    }


    private function loadConfig() {
        $pdo = $this->getPdo();
        if ($pdo) {
            // Try DB first
            try {
                $stmt = $pdo->prepare("SELECT value FROM configurations WHERE key = 'deploy_config'");
                $stmt->execute();
                $row = $stmt->fetch();
                if ($row) {
                    $d = json_decode($row['value'], true);
                    $this->serverConfig = (is_array($d) && isset($d[0])) ? $d : (is_array($d) ? [$d] : []);
                    return;
                }
            } catch (Exception $e) {}
        }

        // Migration: Check for legacy file
        $envPath = getenv('DEPLOY_CONFIG_PATH');
        $f = $envPath ?: ((is_dir('/data') && is_writable('/data')) ? '/data/deploy_config.json' : __DIR__ . '/deploy_config.json');
        
        // Add file cache to avoid reading config file on every request
        $configCacheFile = sys_get_temp_dir() . '/deploy_config_cache.json';
        if (file_exists($configCacheFile) && (time() - filemtime($configCacheFile) < 300)) {
            $this->serverConfig = json_decode(file_get_contents($configCacheFile), true) ?: [];
        } elseif (is_file($f)) {
            $d = json_decode(file_get_contents($f), true);
            $this->serverConfig = (is_array($d) && isset($d[0])) ? $d : (is_array($d) ? [$d] : []);
            
            // Cache the config to avoid future file reads
            @file_put_contents($configCacheFile, json_encode($this->serverConfig));
            
            // Migrate to DB
            if ($pdo && !empty($this->serverConfig)) {
                $this->saveConfig($this->serverConfig);
                // Optional: Rename legacy file
                @rename($f, $f . '.migrated');
            }
        }
    }

    private function saveConfig($d) {
        $pdo = $this->getPdo();
        if ($pdo) {
            try {
                $json = json_encode(array_values($d), JSON_PRETTY_PRINT);
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO configurations (key, value) VALUES ('deploy_config', :val)");
                $stmt->execute([':val' => $json]);
                return;
            } catch (Exception $e) {}
        }
        
        // Fallback to file if DB fails (should not happen if getPdo works)
        $envPath = getenv('DEPLOY_CONFIG_PATH');
        $f = $envPath ?: ((is_dir('/data') && is_writable('/data')) ? '/data/deploy_config.json' : __DIR__ . '/deploy_config.json');
        file_put_contents($f, json_encode(array_values($d), JSON_PRETTY_PRINT));
    }

    private function getUsedIdentifiers($servers) {
        $s = []; $p = [];
        foreach ($servers as $srv) {
            foreach ($srv['rotation_slugs'] ?? [] as $x) $s[$x] = true;
            if (!empty($srv['rotation_path'])) $p[$srv['rotation_path']] = true;
        }
        return [$s, $p];
    }

    private function findUniqueString($used, $len) {
        do { $s = $this->generateRandomString($len); } while (isset($used[$s]));
        return $s;
    }

    private function generateRandomString($len) {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $len);
    }

    private function normalizeDomain($d) {
        $d = preg_replace('#^https?://|/.*$#', '', strtolower(trim((string)$d)));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $d) ? $d : '';
    }

    private function domainVariants($d) {
        $d = $this->normalizeDomain($d);
        if (!$d) return [];
        return str_starts_with($d, 'www.') ? [$d, substr($d, 4)] : [$d, "www.$d"];
    }

    private function nginxConfigKey($d) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $d ?: 'default_app');
    }

    private function writeNginx($ssh, $sudo, $key, $conf) {
        $ssh->exec("echo " . escapeshellarg($conf) . " > /tmp/nginx_conf");
        $ssh->exec("$sudo mv /tmp/nginx_conf /etc/nginx/sites-available/$key");
        $ssh->exec("$sudo ln -sf /etc/nginx/sites-available/$key /etc/nginx/sites-enabled/$key");
        $ssh->exec("$sudo nginx -t && $sudo systemctl reload nginx");
    }

    private function removeNginxConfig($ssh, $sudo, $key) {
        $ssh->exec("$sudo rm /etc/nginx/sites-enabled/$key /etc/nginx/sites-available/$key 2>/dev/null");
        $ssh->exec("$sudo nginx -t && $sudo systemctl reload nginx");
    }

    private function getNginxConfigMulti($main, $add, $root, $rot, $wild, $rotPath, $rotSlugs, $leMap) {
        $main = $this->normalizeDomain($main);
        $add = array_unique(array_filter(array_map([$this, 'normalizeDomain'], $add), fn($d) => $d && $d !== $main));

        if ($rot && $main && $add) {
            $weight = count($add) > 0 ? max(1, intdiv(100, count($add))) : 100;
            $split = "split_clients \"\$request_uri\" \$rotation_target {\n";
            foreach (array_slice($add, 0, -1) as $d) $split .= "    {$weight}% $d;\n";
            if ($last = end($add)) $split .= "    * $last;\n";
            $split .= "}\n\n";

            $slugPattern = '';
            if (is_array($rotSlugs)) {
                $validSlugs = array_values(array_filter(array_map('strval', $rotSlugs), 'strlen'));
                if ($validSlugs) {
                    $escapedSlugs = array_map(fn($s) => preg_quote($s, '/'), $validSlugs);
                    $slugPattern = implode('|', $escapedSlugs);
                }
            }

            if ($rotPath && $slugPattern !== '') {
                $loc = "    location ~ ^/" . preg_quote($rotPath, '/') . "/(" . $slugPattern . ")(/.*)?$ { return 302 \$scheme://\$rotation_target\$request_uri; }\n    location / { try_files \$uri \$uri/ /index.php?\$query_string; }";
            } elseif ($rotPath) {
                $loc = "    location ~ ^/" . preg_quote($rotPath, '/') . "/ { return 302 \$scheme://\$rotation_target\$request_uri; }\n    location / { try_files \$uri \$uri/ /index.php?\$query_string; }";
            } else {
                $loc = "    location / { return 302 \$scheme://\$rotation_target\$request_uri; }";
            }

            $blocks = [$split, $this->nginxBlock($main, $root, $leMap[$main] ?? false, $loc)];
            foreach ($add as $d) $blocks[] = $this->nginxBlock($d, $root, $leMap[$d] ?? false);
            return implode("\n\n", $blocks);
        }

        $names = array_unique(array_merge($this->domainVariants($main), ($wild && $main) ? ["*.$main"] : []));
        foreach ($add as $d) {
            $names = array_merge($names, $this->domainVariants($d));
            if ($wild) $names[] = "*.$d";
        }
        $useLe = ($leMap[$main] ?? false);
        return $this->nginxBlock(implode(' ', $names), $root, $useLe);
    }

    private function nginxBlock($names, $root, $useLe, $customLoc = '') {
        $ssl = $useLe ? 
            "    ssl_certificate /etc/letsencrypt/live/" . explode(' ', $names)[0] . "/fullchain.pem;\n    ssl_certificate_key /etc/letsencrypt/live/" . explode(' ', $names)[0] . "/privkey.pem;" : 
            "    ssl_certificate /etc/nginx/ssl/selfsigned.crt;\n    ssl_certificate_key /etc/nginx/ssl/selfsigned.key;";
        
        $loc = $customLoc ?: "    location / { try_files \$uri \$uri/ /index.php?\$query_string; }";
        
        return <<<NGINX
server {
    listen 80;
    server_name $names;
    root $root;
    location ^~ /.well-known/acme-challenge/ { try_files \$uri =404; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 443 ssl;
    server_name $names;
    root $root;
$ssl
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    index index.php index.html index.htm;
    location ^~ /.well-known/acme-challenge/ { try_files \$uri =404; }
$loc
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/var/run/php/php-fpm.sock; }
    location ~ /\.ht { deny all; }
}
NGINX;
    }

    public function parseDeployRequest($post) {
        return [
            'action' => $_GET['action'] ?? '',
            'host' => $post['host'] ?? '',
            'user' => $post['user'] ?? 'root',
            'password' => $post['password'] ?? '',
            'port' => intval($post['port'] ?? 22),
            'path' => $post['path'] ?? '/var/www/html',
            'main_domain' => $this->normalizeDomain($post['main_domain'] ?? ''),
            'domains' => $this->normalizeDomainsList($post['domains'] ?? []),
            'rotation_enabled' => true, // Slug/Rotation always active
            'wildcard_enabled' => ($post['wildcard_enabled'] ?? '0') === '1',
            'local_path' => $post['local_path'] ?? '',
            'server_id' => $post['server_id'] ?? null,
            'license_key' => trim($post['license'] ?? $post['license_key'] ?? '')
        ];
    }

    private function normalizeDomainsList($in) {
        $arr = is_array($in) ? $in : preg_split('/[\s,]+/', (string)$in, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map([$this, 'normalizeDomain'], $arr))));
    }

    private function renderLogin() {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Login</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#020617;color:#e5e7eb;font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh}.card{background:#020617;border:1px solid #1f2937;border-radius:.75rem;padding:2rem;width:100%;max-width:360px;box-shadow:0 20px 40px rgba(0,0,0,.5)}.btn{width:100%;padding:.75rem;border-radius:.5rem;border:none;background:#6366f1;color:#fff;font-weight:600;cursor:pointer;margin-top:1rem}.input{width:100%;padding:.75rem;border-radius:.5rem;border:1px solid #374151;background:#020617;color:#fff;box-sizing:border-box}</style></head><body><div class="card"><h3>@ClosedServiceDeployer</h3><p style="color:#9ca3af;font-size:.9rem">Enter license key to continue.</p><form method="post"><input name="license" class="input" placeholder="License Key" required><button class="btn">Unlock</button></form></div></body></html>';
    }

    private function renderDashboard() {
        $lic = htmlspecialchars($this->currentLicense);
        
        // Lazy load removed from here to improve initial page load speed
        ?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@ClosedServiceDeployer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        mono: ['"JetBrains Mono"', 'monospace'],
                        sans: ['Inter', 'sans-serif']
                    },
                    colors: {
                        brand: {
                            500: '#3b82f6', // More standard blue
                            600: '#2563eb',
                            900: '#1e3a8a',
                        },
                        slate: {
                            850: '#151f32', // Custom dark
                            950: '#020617',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }
        .glass { background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(10px); }
        .btn-icon { padding: 0.5rem; border-radius: 0.5rem; color: #94a3b8; transition: color .15s ease, background-color .15s ease; }
        .btn-icon:hover { background: rgba(255, 255, 255, 0.05); color: #ffffff; }
        
        /* Enhanced terminal styling for server management buttons */
        #log-terminal {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 6px;
            font-family: 'JetBrains Mono', 'Monaco', 'Menlo', monospace;
            line-height: 1.4;
            padding: 12px;
        }
        
        #log-terminal div[style*="margin-bottom: 8px"] {
            border-bottom: 1px solid #374151;
            padding-bottom: 4px;
            margin-bottom: 8px !important;
            color: #9ca3af;
            font-size: 10px;
            font-weight: 500;
        }
        
        /* Button loading states */
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed !important;
        }
        
        .animate-spin {
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 4px;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="h-screen flex overflow-hidden bg-slate-950 text-slate-200 font-sans selection:bg-brand-500/30">

    <!-- Sidebar -->
    <aside class="w-72 bg-slate-900/50 border-r border-white/5 flex flex-col shrink-0 transition-all duration-300" id="sidebar">
        <div class="h-16 flex items-center px-4 border-b border-white/5 gap-3">
            <div class="w-8 h-8 rounded bg-brand-600 flex items-center justify-center font-bold text-white shadow-lg shadow-brand-500/20">L</div>
            <div class="flex flex-col">
                <span class="font-bold text-sm tracking-tight">ClosedPage</span>
                <span class="text-[10px] text-slate-500 font-mono uppercase tracking-wider">Deployer</span>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto custom-scrollbar p-3 space-y-1" id="server-list">
            <!-- Server Items injected here -->
        </div>

        <div class="p-3 border-t border-white/5 bg-slate-900/30">
            <div class="space-y-1.5" style="display: none;">
                            <label class="text-[10px] font-bold text-slate-500 uppercase">VPS Project Path</label>
                            <input type="text" name="path" id="deploy_path" value="/var/www/html" class="w-full bg-black/40 border border-slate-700 rounded-lg px-3 py-2 text-[11px] text-slate-300 outline-none focus:border-brand-500 transition-all" placeholder="/var/www/html or custom path">
                        </div>

                        <input type="hidden" name="local_path" id="deploy_local_path" value="<?php echo htmlspecialchars(__DIR__); ?>">


            <button onclick="showView('add-server')" class="w-full flex items-center justify-center gap-2 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg font-medium text-sm transition shadow-lg shadow-brand-500/10 group">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="group-hover:scale-110 transition-transform"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Add Server</span>
            </button>
        </div>
        
        <?php if ($lic): ?>
        <div class="px-4 py-2 text-[10px] text-white-600 font-mono text-center border-t border-white/5">
            <span id="license-key"><?php echo $lic; ?></span>
                        <button id="copy-btn" onclick="copyLicense()" class="ml-2 px-2 py-1 rounded bg-slate-700 hover:bg-slate-600 text-white text-xs">Copy</button>
            <a href="?action=logout" class="ml-4 hover:text-red-400">Logout</a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col min-w-0 bg-slate-950 relative">
        <!-- Top Bar -->
        <header class="h-16 flex items-center justify-between px-6 border-b border-white/5 bg-slate-950/80 backdrop-blur z-10">
            <div class="flex items-center gap-4">
                 <h2 id="page-title" class="text-lg font-semibold text-slate-100">Dashboard</h2>
            </div>
            <div class="flex items-center gap-3">
                <div id="connection-status" class="hidden text-xs px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Connected</div>
            </div>
        </header>

        <!-- Views Container -->
        <div class="flex-1 overflow-hidden relative">
            
            <!-- Welcome View -->
            <div id="view-home" class="absolute inset-0 p-8 flex flex-col items-center justify-center text-center opacity-100 transition-opacity duration-300">
                <div class="w-16 h-16 bg-slate-900 rounded-2xl flex items-center justify-center mb-6 border border-white/5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-slate-600"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
                </div>
                <h3 class="text-xl font-bold text-slate-200 mb-2">Select a Server</h3>
                <p class="text-slate-500 max-w-sm">Choose a server from the sidebar to manage deployments, or add a new VPS to get started.</p>
            </div>

            <!-- Add Server View -->
            <div id="view-add-server" class="hidden absolute inset-0 overflow-y-auto custom-scrollbar p-6">
                <div class="max-w-2xl mx-auto">
                    <h2 class="text-2xl font-bold mb-6">Connect New VPS</h2>
                    <form id="addServerForm" class="bg-slate-900/50 border border-white/5 p-6 rounded-xl space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase">IP Address</label>
                                <input type="text" name="host" required class="w-full bg-black/40 border border-slate-700 rounded-lg px-4 py-2.5 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none transition-all" placeholder="1.2.3.4">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase">SSH Port</label>
                                <input type="number" name="port" value="22" class="w-full bg-black/40 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-brand-500 transition-all" placeholder="22">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase">Username</label>
                                <input type="text" name="user" value="root" required class="w-full bg-black/40 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-brand-500 transition-all" placeholder="root">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold text-slate-500 uppercase">Password</label>
                                <input type="password" name="password" required class="w-full bg-black/40 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-brand-500 transition-all" placeholder="••••••••">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-xs font-bold text-slate-500 uppercase">Main Domain</label>
                            <input type="text" name="main_domain" class="w-full bg-black/40 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-brand-500 transition-all" placeholder="example.com">
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-xs font-bold text-slate-500 uppercase">Additional Domains</label>
                            <textarea name="domains" rows="3" class="w-full bg-black/40 border border-slate-700 rounded-lg px-4 py-2.5 text-sm outline-none focus:border-brand-500 transition-all" placeholder="one.com&#10;two.com"></textarea>
                        </div>
                        
                        <div class="flex items-center gap-3 p-3 bg-black/20 rounded-lg border border-white/5">
                            <input type="checkbox" name="wildcard_enabled" value="1" id="wildcard_new" class="w-4 h-4 rounded bg-slate-800 border-slate-600 text-brand-500 focus:ring-offset-0 focus:ring-0">
                            <label for="wildcard_new" class="text-sm text-slate-300 select-none">Enable Wildcard Subdomains (*.domain.com)</label>
                        </div>

                      

                        <input type="hidden" name="rotation_enabled" value="1">
                        <input type="hidden" name="local_path" value="<?php echo htmlspecialchars(__DIR__); ?>">
                        <?php if ($lic): ?><input type="hidden" name="license_key" value="<?php echo $lic; ?>"><?php endif; ?>
                        
                        <div class="pt-2 flex gap-3">
                            <button type="button" onclick="showView('home')" class="flex-1 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg font-medium text-sm transition">Cancel</button>
                            <button type="submit" class="flex-[2] py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg font-bold text-sm transition shadow-lg shadow-brand-500/20">Connect & Add Server</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Deploy View -->
            <div id="view-deploy" class="hidden absolute inset-0 flex flex-col lg:flex-row">
                
                <!-- Left Panel: Config -->
                <div class="w-full lg:w-[400px] xl:w-[450px] border-b lg:border-b-0 lg:border-r border-white/5 bg-slate-900/30 flex flex-col overflow-y-auto custom-scrollbar">
                    <form id="deployForm" class="flex-1 flex flex-col p-6 space-y-6">
                        <input type="hidden" name="server_id" id="deploy_server_id">
                        <?php if ($lic): ?><input type="hidden" name="license_key" value="<?php echo $lic; ?>"><?php endif; ?>

                        <!-- Connection Info Group -->
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider">Connection</h3>
                                <button type="button" id="testBtn" class="text-[10px] bg-slate-800 hover:bg-slate-700 px-2 py-1 rounded text-slate-400 border border-slate-700">Test Ping</button>
                            </div>
                            <div class="grid grid-cols-1 gap-3">
                                <div class="relative">
                                    <span class="absolute left-3 top-2.5 text-slate-600"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg></span>
                                    <input type="text" name="host" id="deploy_host" class="w-full pl-9 bg-black/40 border border-slate-700 rounded-md py-2 text-xs text-slate-300 font-mono" placeholder="Host">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <input type="text" name="user" id="deploy_user" class="bg-black/40 border border-slate-700 rounded-md px-3 py-2 text-xs text-slate-300 font-mono" placeholder="User">
                                    <input type="password" name="password" id="deploy_password" class="bg-black/40 border border-slate-700 rounded-md px-3 py-2 text-xs text-slate-300 font-mono" placeholder="Password">
                                </div>
                            </div>
                            <input type="hidden" name="port" id="deploy_port" value="22">
                        </div>

                        <hr class="border-white/5">

                        <!-- Domains Group -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider">Domains</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] text-slate-500 mb-1 block">Main Domain</label>
                                    <input type="text" name="main_domain" id="deploy_main_domain" class="w-full bg-black/40 border border-slate-700 rounded-md px-3 py-2 text-xs text-slate-300 font-mono focus:border-brand-500 outline-none">
                                </div>
                                
                                <div>
                                    <label class="text-[10px] text-slate-500 mb-1 block">Additional Domains</label>
                                    <textarea name="domains" id="deploy_domains" class="hidden"></textarea>
                                    <div class="flex gap-2 mb-2">
                                        <input id="new_domain_input" class="flex-1 bg-black/40 border border-slate-700 rounded-md px-3 py-2 text-xs text-slate-300 font-mono" placeholder="Add domain..." onkeydown="if(event.key==='Enter'){event.preventDefault();addDomainPill()}">
                                        <button type="button" onclick="addDomainPill()" class="px-3 bg-slate-800 hover:bg-slate-700 rounded-md border border-slate-700 text-slate-300 transition">+</button>
                                    </div>
                                    <div id="domain_pills" class="flex flex-wrap gap-1.5 min-h-[30px]"></div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <input type="checkbox" name="wildcard_enabled" id="deploy_wildcard_enabled" value="1" class="rounded bg-slate-800 border-slate-600 text-brand-500">
                                    <label for="deploy_wildcard_enabled" class="text-xs text-slate-400">Enable Wildcard (*.domain)</label>
                                </div>
                            </div>
                        </div>

                        <!-- Status Group -->
                        <div class="bg-black/20 rounded-lg p-3 border border-white/5 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-bold text-slate-500 uppercase">Live Status</span>
                                <button type="button" id="checkStatusBtn" class="text-[10px] text-brand-400 hover:text-brand-300">Refresh</button>
                            </div>
                            <div id="deployActiveDomains" class="flex flex-wrap gap-1.5 min-h-[20px] text-xs text-slate-500 italic">
                                Ready to check
                            </div>
                        </div>

                        <!-- Health & Worker Group -->
                        <div class="bg-black/20 rounded-lg p-3 border border-white/5 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-bold text-slate-500 uppercase">Server Health</span>
                                <div class="flex gap-2">
                                    <button type="button" id="healthBtn" title="Check Chrome & Worker" class="text-[10px] text-brand-400 hover:text-brand-300">Check</button>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" id="restartWorkerBtn" class="flex-1 py-1 bg-slate-800 hover:bg-slate-700 text-[10px] text-emerald-400 rounded border border-emerald-500/20 transition">Restart Worker</button>
                                <button type="button" id="stopWorkerBtn" class="flex-1 py-1 bg-slate-800 hover:bg-slate-700 text-[10px] text-red-400 rounded border border-red-500/20 transition">Stop Worker</button>
                            </div>
                        </div>

                       
                        <!-- Actions -->
                        <div class="pt-4 mt-auto space-y-3">
                            <button type="submit" id="deployBtn" class="w-full py-3 bg-brand-600 hover:bg-brand-500 text-white rounded-lg font-bold text-sm shadow-lg shadow-brand-500/20 transition-all active:scale-[0.98]">
                                🚀 Full Deploy
                            </button>
                            
                            <button type="button" id="updateAllBtn" class="w-full py-2 bg-slate-800 hover:bg-slate-700 text-blue-300 border border-blue-500/20 rounded-lg text-xs font-medium transition">
                                Update Code & Domains
                            </button>

                            <div class="pt-2">
                                <button type="button" id="toolSslBtn" class="w-full py-1.5 bg-slate-900/50 hover:bg-slate-800 text-purple-300 border border-purple-500/20 rounded text-[10px] transition">
                                    🔒 SSL Certs
                                </button>
                            </div>
                            
                            <div class="grid grid-cols-1 gap-2 pt-1">
                                <button type="button" id="removeDomainsBtn" class="py-1.5 bg-slate-900/50 hover:bg-slate-800 text-orange-300 border border-orange-500/20 rounded text-[10px] transition">
                                    🧹 Clean SSL & Domains
                                </button>
                            </div>
                            
                            <div class="flex justify-between pt-2 border-t border-white/5">
                                <button type="button" id="saveBtn" class="text-xs text-slate-500 hover:text-slate-300 flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                    Save Config
                                </button>
                                <button type="button" id="adminPanelBtn" class="text-xs text-slate-500 hover:text-slate-300 flex items-center gap-1">
                                    Open Admin
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Right Panel: Logs -->
                <div class="flex-1 flex flex-col bg-black/50 min-h-0">
                    <!-- Terminal Tabs/Header -->
                    <div class="h-10 flex items-center justify-between px-4 bg-black/40 border-b border-white/5">
                        <div class="flex gap-4 text-xs font-mono">
                            <button id="tab-deploy" onclick="switchTerminalTab('deploy')" class="text-slate-300 font-bold border-b-2 border-brand-500 py-2.5 transition-colors">Deployment Log</button>
                            <button id="tab-logs" onclick="switchTerminalTab('logs')" class="text-slate-600 py-2.5 hover:text-slate-400 transition-colors">Application Logs</button>
                        </div>
                        <div class="flex items-center gap-2">
                             <span id="term-status" class="text-[10px] text-emerald-500 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Ready
                             </span>
                             <div class="h-4 w-px bg-white/10 mx-1"></div>
                             <button type="button" id="copyLogsBtn" class="text-[10px] text-slate-400 hover:text-white">Copy</button>
                        </div>
                    </div>

                    <!-- Terminal Output -->
                    <div class="flex-1 p-0 overflow-hidden relative group">
                        <!-- Primary Terminal -->
                        <div id="terminal" class="absolute inset-0 p-4 overflow-y-auto font-mono text-xs text-slate-300 space-y-1 pb-10 selection:bg-brand-500/40">
                            <div class="text-slate-600 italic">Select a server and action to start...</div>
                        </div>
                        
                        <!-- Log View (Tabbed) -->
                        <div id="log-terminal-container" class="absolute inset-0 bg-slate-900/90 hidden flex flex-col z-10 w-full h-full">
                             <div class="px-3 py-1 bg-black/40 text-[10px] text-slate-500 font-mono border-b border-white/5 flex justify-between shrink-0">
                                <span>REMOTE LOGS</span>
                             </div>
                             <div id="log-terminal" class="flex-1 p-3 overflow-y-auto font-mono text-[11px] text-slate-400 whitespace-pre-wrap break-all w-full"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        let servers = [];
        let activeServerId = null;

        function copyLicense() {
            const licenseKey = document.getElementById('license-key').innerText;
            const copyButton = document.getElementById('copy-btn');
            const originalText = copyButton.innerText;

            navigator.clipboard.writeText(licenseKey).then(() => {
                copyButton.innerText = 'Copied!';
                setTimeout(() => {
                    copyButton.innerText = originalText;
                }, 2000);
            }, () => {
                copyButton.innerText = 'Failed!';
                setTimeout(() => {
                    copyButton.innerText = originalText;
                }, 2000);
            });
        }

        function switchTerminalTab(tab) {
            const term = document.getElementById('terminal');
            const logContainer = document.getElementById('log-terminal-container');
            const tabDeploy = document.getElementById('tab-deploy');
            const tabLogs = document.getElementById('tab-logs');

            if (tab === 'deploy') {
                term.classList.remove('hidden');
                logContainer.classList.add('hidden');
                
                // Style: Active Deploy
                tabDeploy.className = 'text-slate-300 font-bold border-b-2 border-brand-500 py-2.5 transition-colors';
                tabLogs.className = 'text-slate-600 py-2.5 hover:text-slate-400 transition-colors';
            } else {
                term.classList.add('hidden');
                logContainer.classList.remove('hidden');
                
                // Style: Active Logs
                tabLogs.className = 'text-slate-300 font-bold border-b-2 border-brand-500 py-2.5 transition-colors';
                tabDeploy.className = 'text-slate-600 py-2.5 hover:text-slate-400 transition-colors';
                
                // Trigger fetch if empty or just because
                refreshLogs();
                startLogStream();
            }
        }

        async function loadServers() {
            try {
                const res = await fetch('?action=get_servers', { method: 'POST' });
                const json = await res.json();
                if(json.status === 'success') {
                    servers = json.servers || [];
                    renderServerList();
                }
            } catch(e) {}
        }

        function showView(id) {
            document.querySelectorAll('[id^="view-"]').forEach(el => el.classList.add('hidden'));
            const view = document.getElementById('view-' + id);
            if(view) view.classList.remove('hidden');
            
            if(id === 'home') {
                document.getElementById('page-title').textContent = 'Dashboard';
                activeServerId = null;
                updateSidebarActiveState();
            } else if (id === 'add-server') {
                document.getElementById('page-title').textContent = 'Add Server';
                activeServerId = null;
                updateSidebarActiveState();
            }
            // renderServerList is called by loadServers or manually if needed, but loadServers is better
        }

        function updateSidebarActiveState() {
            document.querySelectorAll('.server-item').forEach(el => {
                el.classList.remove('bg-brand-600', 'text-white', 'shadow-md');
                el.classList.add('text-slate-400', 'hover:bg-white/5', 'hover:text-slate-200');
                if(el.dataset.id === activeServerId) {
                    el.classList.remove('text-slate-400', 'hover:bg-white/5', 'hover:text-slate-200');
                    el.classList.add('bg-brand-600', 'text-white', 'shadow-md');
                }
            });
        }

        function renderServerList() {
            const list = document.getElementById('server-list');
            list.innerHTML = '';
            
            if(servers.length === 0) {
                list.innerHTML = '<div class="text-center py-8 px-2 text-xs text-slate-600">No servers yet.<br>Click "Add Server"</div>';
                return;
            }

            servers.forEach((s) => {
                const el = document.createElement('div');
                // Styling for sidebar item
                const isActive = s.id === activeServerId;
                const baseClasses = 'server-item cursor-pointer rounded-lg px-3 py-2.5 mb-1 transition-all group flex items-center justify-between';
                const activeClasses = isActive ? 'bg-brand-600 text-white shadow-md' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200';
                
                el.className = `${baseClasses} ${activeClasses}`;
                el.dataset.id = s.id;
                el.onclick = () => openDeploy(s.id);
                
                el.innerHTML = `
                    <div class="flex flex-col overflow-hidden">
                        <span class="font-medium text-xs truncate font-mono">${s.host}</span>
                        <span class="text-[10px] opacity-60 truncate">${s.main_domain || 'No domain'}</span>
                    </div>
                    <button onclick="event.stopPropagation(); deleteServer('${s.id}')" class="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-500/20 hover:text-red-400 rounded transition">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                `;
                list.appendChild(el);
            });
        }

        function openDeploy(id) {
            const s = servers.find(x => x.id === id);
            if (!s) return;
            
            activeServerId = id;
            updateSidebarActiveState();

            // Populate basic fields
            document.getElementById('deploy_server_id').value = s.id || '';
            document.getElementById('deploy_host').value = s.host || '';
            document.getElementById('deploy_user').value = s.user || 'root';
            document.getElementById('deploy_port').value = s.port || 22;
            document.getElementById('deploy_password').value = s.password || '';
            document.getElementById('deploy_main_domain').value = s.main_domain || '';
            document.getElementById('deploy_wildcard_enabled').checked = !!s.wildcard_enabled;
            
            // Populate domains
            const dArea = document.getElementById('deploy_domains');
            let dList = s.domains || [];
            if (typeof dList === 'string') dList = [dList];
            if (dArea) dArea.value = Array.isArray(dList) ? dList.join('\n') : '';
            renderPills();
            
            document.getElementById('page-title').textContent = s.host;
            
            // Reset UI states
            document.getElementById('terminal').innerHTML = '<div class="text-slate-600 italic mt-2">Ready to deploy to ' + s.host + '</div>';
            document.getElementById('log-terminal').textContent = '';
            document.getElementById('log-terminal-container').classList.add('hidden');
            document.getElementById('deployActiveDomains').innerHTML = 'Ready to check';
            document.getElementById('connection-status').classList.add('hidden');
            
            showView('deploy');
            
            // Auto test connection - DISABLED for performance
            // testConnection();

            // Auto check domains - DISABLED for performance
            // if (typeof checkDomainStatusAuto === 'function') {
            //     checkDomainStatusAuto();
            // }
        }

        function showNotification(message, isError = false) {
            const notification = document.createElement('div');
            notification.textContent = message;
            notification.className = `fixed top-5 right-5 px-4 py-2 rounded-lg text-white ${
                isError ? 'bg-red-500' : 'bg-green-500'
            }`;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        async function testConnection() {
             const btn = document.getElementById('testBtn');
             const statusIndicator = document.getElementById('connection-status');
             if (!btn || !statusIndicator) return;

             btn.textContent = '...';
             const fd = new FormData(document.getElementById('deployForm'));
             try {
                 const res = await fetch('?action=test_connection', { method: 'POST', body: fd });
                 const json = await res.json();
                 if(json.status === 'success') {
                     statusIndicator.classList.remove('hidden');
                     statusIndicator.textContent = 'Connected';
                     statusIndicator.className = 'text-xs px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                 } else {
                     statusIndicator.classList.remove('hidden');
                     statusIndicator.textContent = 'Disconnected';
                     statusIndicator.className = 'text-xs px-2 py-1 rounded bg-red-500/10 text-red-400 border border-red-500/20';
                 }
             } catch(e) {
                 statusIndicator.classList.remove('hidden');
                 statusIndicator.textContent = 'Error';
                 statusIndicator.className = 'text-xs px-2 py-1 rounded bg-red-500/10 text-red-400 border border-red-500/20';
             } finally {
                 btn.textContent = 'Test Ping';
             }
        }

        function renderPills() {
            const dArea = document.getElementById('deploy_domains');
            const pills = document.getElementById('domain_pills');
            const arr = dArea.value.split('\n').filter(x => x.trim());
            pills.innerHTML = '';
            arr.forEach(d => {
                const p = document.createElement('span');
                p.className = 'px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-[10px] flex items-center gap-1 text-slate-300';
                p.innerHTML = `${d} <button type="button" onclick="removeDomain('${d}')" class="hover:text-red-400 ml-1">×</button>`;
                pills.appendChild(p);
            });
        }

        function addDomainPill() {
            const inp = document.getElementById('new_domain_input');
            const val = inp.value.trim();
            if(!val) return;
            const dArea = document.getElementById('deploy_domains');
            const arr = dArea.value.split('\n').filter(x => x.trim());
            if(!arr.includes(val)) {
                arr.push(val);
                dArea.value = arr.join('\n');
                renderPills();
            }
            inp.value = '';
        }

        window.removeDomain = (d) => {
            const dArea = document.getElementById('deploy_domains');
            const arr = dArea.value.split('\n').filter(x => x.trim());
            dArea.value = arr.filter(x => x !== d).join('\n');
            renderPills();
        };

        // Form Handlers
        const api = async (action, body) => {
            const fd = new FormData();
            for(const k in body) fd.append(k, body[k]);
            const res = await fetch('?action=' + action, { method: 'POST', body: fd });
            return res.json();
        };

        document.getElementById('addServerForm').onsubmit = async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const btn = e.target.querySelector('button[type="submit"]');
            const origText = btn.textContent;
            btn.textContent = 'Connecting...';
            btn.disabled = true;
            
            try {
                const res = await fetch('?action=add_server', { method: 'POST', body: fd });
                const json = await res.json();
                if(json.status === 'success') {
                    await loadServers();
                    e.target.reset();
                    if(json.server && json.server.id) openDeploy(json.server.id);
                } else {
                    showNotification(json.message, true);
                }
            } catch(e) { showNotification('Error connecting', true); }
            
            btn.textContent = origText;
            btn.disabled = false;
        };

        window.deleteServer = async (id) => {
            showNotification('This feature has been temporarily disabled for your protection.', true);
            return;
            if(!confirm('Delete this server config?')) return;
            await api('delete_server', { server_id: id });
            await loadServers();
            if(activeServerId === id) showView('home');
        };

        document.getElementById('saveBtn').onclick = async () => {
            const form = document.getElementById('deployForm');
            const fd = new FormData(form);
            const res = await fetch('?action=save_server', { method: 'POST', body: fd });
            const json = await res.json();
            if(json.status === 'success') {
                // Flash success
                const btn = document.getElementById('saveBtn');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<span class="text-emerald-400">Saved!</span>';
                setTimeout(() => btn.innerHTML = originalHTML, 1500);

                const id = fd.get('server_id');
                const main_domain = fd.get('main_domain');
                const host = fd.get('host');
                
                // Update local servers array for sidebar sync
                const sIdx = servers.findIndex(x => x.id === id);
                if (sIdx !== -1) {
                    servers[sIdx].main_domain = main_domain;
                    servers[sIdx].host = host;
                    renderServerList();
                } else {
                    await loadServers();
                }
            }
        };

        document.getElementById('adminPanelBtn').onclick = () => {
            const form = document.getElementById('deployForm');
            if (!form) return;
            const fd = new FormData(form);
            let target = (fd.get('main_domain') || fd.get('host') || '').toString().trim();
            if (!target) return;
            target = target.replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
            const url = 'https://' + target + '/admin.html';
            window.open(url, '_blank');
        };

        // Terminal & SSE
        const term = document.getElementById('terminal');
        const logTerm = document.getElementById('log-terminal');
        const copyLogsBtn = document.getElementById('copyLogsBtn');
        
        const log = (msg, type='info') => {
            if (!term) return;
            const div = document.createElement('div');
            div.className = type === 'error' ? 'text-red-400 bg-red-900/10 px-2 py-0.5 rounded border-l-2 border-red-500' : (type === 'success' ? 'text-emerald-400' : 'text-slate-300');
            div.innerHTML = `<span class="opacity-50 select-none mr-2">$</span>${msg}`;
            term.appendChild(div);
            term.scrollTop = term.scrollHeight;
        };

        if (copyLogsBtn && logTerm) {
            copyLogsBtn.addEventListener('click', async () => {
                const text = logTerm.textContent || '';
                if (!text) return;
                try {
                    await navigator.clipboard.writeText(text);
                    const original = copyLogsBtn.textContent;
                    copyLogsBtn.textContent = 'Copied!';
                    setTimeout(() => copyLogsBtn.textContent = original, 1000);
                } catch (e) {}
            });
        }

        const refreshLogs = async () => {
            if (!logTerm) return;
            const form = document.getElementById('deployForm');
            if (!form) return;
            const fd = new FormData(form);
            try {
                const res = await fetch('?action=view_logs', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.logs) {
                    const isAtBottom = (logTerm.scrollHeight - logTerm.scrollTop - logTerm.clientHeight) < 50;
                    logTerm.textContent = json.logs;
                    if (isAtBottom) logTerm.scrollTop = logTerm.scrollHeight;
                }
            } catch (e) {}
        };

        const startLogStream = () => {
            refreshLogs();
            setInterval(refreshLogs, 4000);
        };

        const runSse = async (action, extra={}, clearTerm=true) => {
            const form = document.getElementById('deployForm');
            const fd = new FormData(form);
            for(const k in extra) fd.append(k, extra[k]);
            
            if(clearTerm) term.innerHTML = '';
            log(`Starting ${action}...`);
            document.getElementById('term-status').innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-yellow-400 animate-pulse"></span> Working...';
            
            try {
                const res = await fetch('?action=' + action, { method: 'POST', body: fd });
                let hadError = !res.ok;
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                while(true) {
                    const {done, value} = await reader.read();
                    if(done) break;
                    const chunk = decoder.decode(value);
                    const lines = chunk.split('\n\n');
                    lines.forEach(line => {
                        if(line.startsWith('data: ')) {
                            try {
                                const data = JSON.parse(line.substring(6));
                                log(data.message, data.type);
                                if (data.type === 'error') hadError = true;
                            } catch(e) {}
                        }
                    });
                }
                document.getElementById('term-status').innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Done';
                return !hadError;
            } catch(e) {
                 log(e.message, 'error');
                 document.getElementById('term-status').innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Error';
                 return false;
            }
        };

        const deployActiveDomains = document.getElementById('deployActiveDomains');

        const renderStatusMessage = (text) => {
            if (!deployActiveDomains) return;
            deployActiveDomains.innerHTML = '';
            const span = document.createElement('span');
            span.className = 'text-[10px] text-slate-500';
            span.textContent = text;
            deployActiveDomains.appendChild(span);
        };

        const renderDomainStatusPill = (status) => {
            if (!deployActiveDomains) return;
            const pill = document.createElement('div');
            const isLive = !!status.live;
            const isLocal = !!status.local_live;
            const matchesHost = !!status.matches_host;
            const isProxyBlocked = !!status.proxy_blocked;

            let colorClass = 'bg-red-900/20 border-red-900/30 text-red-300';
            let statusText = status.https_code || status.http_code || status.local_code;
            
            if (isLive) {
                colorClass = 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400';
            } else if (isProxyBlocked) {
                colorClass = 'bg-blue-500/10 border-blue-500/30 text-blue-400';
                statusText = 'Proxy Block';
            } else if (isLocal) {
                colorClass = 'bg-yellow-500/10 border-yellow-500/30 text-yellow-400';
            }

            pill.className = `px-1.5 py-0.5 border text-[10px] rounded ${colorClass} flex items-center gap-1`;

            const ip = status.dns_ip || '';
            let extra = '';
            if (!matchesHost && ip) extra += ' (DNS≠Host)';
            
            pill.textContent = statusText ? `${status.domain} (${statusText})${extra}` : `${status.domain}${extra}`;
            deployActiveDomains.appendChild(pill);
        };

        const checkDomainStatusAuto = async () => {
            const form = document.getElementById('deployForm');
            const btn = document.getElementById('checkStatusBtn');
            if (!form || !deployActiveDomains) return;
            if (btn) {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
                btn.textContent = 'Checking...';
            }
            renderStatusMessage('Checking...');
            const fd = new FormData(form);
            
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 60000); // Increased timeout to 60s for parallel checks

            try {
                const res = await fetch('?action=domain_status', { 
                    method: 'POST', 
                    body: fd,
                    signal: controller.signal 
                });
                const json = await res.json();
                clearTimeout(timeoutId);
                if (json.status !== 'success') {
                    renderStatusMessage(json.message || 'Failed');
                    return;
                }
                const items = Array.isArray(json.statuses) ? json.statuses : [];
                deployActiveDomains.innerHTML = '';
                if (items.length === 0) {
                    renderStatusMessage('None');
                    return;
                }
                items.forEach(renderDomainStatusPill);
            } catch (e) {
                console.error(e); // Log error for debugging
                renderStatusMessage(e.name === 'AbortError' ? 'Timeout (60s)' : 'Request failed');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    btn.textContent = 'Refresh';
                }
            }
        };

        document.getElementById('checkStatusBtn').onclick = () => { checkDomainStatusAuto(); };
        document.getElementById('deployBtn').onclick = async (e) => { 
            e.preventDefault();
            if(!confirm('This will CLEAN/UNINSTALL the remote server first, then DEPLOY fresh. Continue?')) return;
            
            const btn = document.getElementById('deployBtn');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            btn.innerHTML = '🧹 Uninstalling...';
            
            const uninstallOk = await runSse('delete_uninstall', {}, true);
            
            if (uninstallOk) {
                btn.innerHTML = '🚀 Deploying...';
                log('--- Uninstall Complete. Starting Deployment ---', 'success');
                await new Promise(r => setTimeout(r, 1000));
                await runSse('deploy', {}, false);
            } else {
                log('Uninstall phase failed. Stopping.', 'error');
            }
            
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
            btn.innerHTML = orig;
        };
        document.getElementById('updateAllBtn').onclick = async () => {
            const btn = document.getElementById('updateAllBtn');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            
            const codeOk = await runSse('update_code', {}, true);
            if (codeOk) {
                log('--- Code Update Complete. Starting Domain Update ---', 'success');
                await new Promise(r => setTimeout(r, 1000));
                await runSse('apply_domains', {}, false);
            }
            
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
            btn.innerHTML = orig;
        };
        // Helper function to format terminal output with syntax highlighting
        function formatTerminalOutput(text) {
            if (!text) return '';
            
            // Add some basic formatting and highlighting
            let formatted = text
                .replace(/^=== (.+?) ===$/gm, '<span style="color: #60a5fa; font-weight: bold;">=== $1 ===</span>')
                .replace(/^(EXISTS .+)$/gm, '<span style="color: #34d399;">$1</span>')
                .replace(/^(MISSING .+)$/gm, '<span style="color: #f87171;">$1</span>')
                .replace(/^(files=|dirs=)/gm, '<span style="color: #a78bfa;">$1</span>')
                .replace(/^(PROJECT_ROOT:|MA index:|MA api:|SESSION_DATA:|CHROME_CONFIG:|PUPPETEER_CACHE:|LOGS:)/gm, '<span style="color: #fbbf24;">$1</span>')
                .replace(/not found$/gm, '<span style="color: #f87171;">$&</span>')
                .replace(/not running$/gm, '<span style="color: #f87171;">$&</span>');
            
            return formatted;
        }

        // Helper function to show formatted results in terminal
        function showFormattedResults(content, title) {
            const logTerm = document.getElementById('log-terminal');
            const timestamp = new Date().toLocaleTimeString();
            
            logTerm.innerHTML = `<div style="margin-bottom: 8px; color: #9ca3af; font-size: 10px;">[${timestamp}] ${title}</div>`;
            logTerm.innerHTML += `<div style="border-top: 1px solid #374151; padding-top: 8px;">${formatTerminalOutput(content)}</div>`;
            
            // Auto-scroll to bottom
            setTimeout(() => {
                logTerm.scrollTop = logTerm.scrollHeight;
            }, 100);
        }

        document.getElementById('testBtn').onclick = () => { testConnection(); };


        document.getElementById('healthBtn').onclick = async () => {
            const btn = document.getElementById('healthBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" stroke-dasharray="32" stroke-dashoffset="0"/></svg> Checking...';
            btn.disabled = true;
            
            const form = document.getElementById('deployForm');
            if (!form) {
                log('Error: Deploy form not found', 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            const fd = new FormData(form);
            switchTerminalTab('logs');
            const logTerm = document.getElementById('log-terminal');
            logTerm.textContent = 'Checking server health...';
            
            try {
                const res = await fetch('?action=chrome_status', { method: 'POST', body: fd });
                const json = await res.json();
                
                if (json.chrome_status) {
                    showFormattedResults(json.chrome_status, 'Server Health Check Results');
                    log('Server health check completed', 'success');
                } else {
                    const errorMsg = json.message || 'Failed to check server health';
                    logTerm.textContent = 'Error: ' + errorMsg;
                    log('Health check failed: ' + errorMsg, 'error');
                }
            } catch (e) {
                logTerm.textContent = 'Connection Error: ' + e.message;
                log('Connection error during health check: ' + e.message, 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        };

        document.getElementById('stopWorkerBtn').onclick = async () => {
            if (!confirm('Stop all background worker processes?')) return;
            
            const btn = document.getElementById('stopWorkerBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" stroke-dasharray="32" stroke-dashoffset="0"/></svg> Stopping...';
            btn.disabled = true;
            
            const form = document.getElementById('deployForm');
            if (!form) {
                log('Error: Deploy form not found', 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            const fd = new FormData(form);
            try {
                const res = await fetch('?action=stop_worker', { method: 'POST', body: fd });
                const json = await res.json();
                
                if (json.status === 'success') {
                    log(json.message || 'Worker processes stopped successfully', 'success');
                } else {
                    const errorMsg = json.message || 'Failed to stop worker processes';
                    log('Stop worker failed: ' + errorMsg, 'error');
                }
            } catch (e) {
                log('Connection error while stopping workers: ' + e.message, 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        };

        document.getElementById('restartWorkerBtn').onclick = async () => {
            const btn = document.getElementById('restartWorkerBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" stroke-dasharray="32" stroke-dashoffset="0"/></svg> Restarting...';
            btn.disabled = true;
            
            const form = document.getElementById('deployForm');
            if (!form) {
                log('Error: Deploy form not found', 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            const fd = new FormData(form);
            try {
                const res = await fetch('?action=restart_worker', { method: 'POST', body: fd });
                const json = await res.json();
                
                if (json.status === 'success') {
                    log(json.message || 'Worker processes restarted successfully', 'success');
                } else {
                    const errorMsg = json.message || 'Failed to restart worker processes';
                    log('Restart worker failed: ' + errorMsg, 'error');
                }
            } catch (e) {
                log('Connection error while restarting workers: ' + e.message, 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        };

        document.getElementById('toolSslBtn').onclick = () => runSse('ssl');
        document.getElementById('removeDomainsBtn').onclick = () => runSse('remove_domains_only');




        loadServers().then(() => showView('home'));
    </script>
</body>
</html>
        <?php
    }




}

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    (new Deployer())->run();
}