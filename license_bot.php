<?php
// license_bot.php - Restored Telegram License Bot

// Set timezone
date_default_timezone_set('UTC');

// Load environment variables manually
$envPath = __DIR__ . '/.env';
$env = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }
}

$botToken = getenv('LICENSE_BOT_TOKEN') ?: ($env['LICENSE_BOT_TOKEN'] ?? '');
$adminChatId = getenv('LICENSE_ADMIN_CHAT_ID') ?: ($env['LICENSE_ADMIN_CHAT_ID'] ?? '');
$btcAddress = getenv('LICENSE_BTC_ADDRESS') ?: ($env['LICENSE_BTC_ADDRESS'] ?? '');
$usdtAddress = getenv('LICENSE_USDT_ADDRESS') ?: ($env['LICENSE_USDT_ADDRESS'] ?? '');

if (empty($botToken)) {
    die("Error: LICENSE_BOT_TOKEN not found in environment or .env file.\n");
}

echo "Starting License Bot...\n";
echo "Admin Chat ID: " . ($adminChatId ?: 'Not set (anyone can generate licenses)') . "\n";
echo "BTC Address: " . ($btcAddress ?: 'Not set') . "\n";
echo "USDT Address: " . ($usdtAddress ?: 'Not set') . "\n";

// Database Connection — now uses Neon PostgreSQL instead of SQLite
function getPdo() {
    global $env;
    
    // Read NEON_DATABASE_URL from env (loaded earlier, strip quotes)
    $neonUrl = getenv('NEON_DATABASE_URL') ?: ($env['NEON_DATABASE_URL'] ?? '');
    $neonUrl = trim($neonUrl, '"\'');
    
    if (empty($neonUrl)) {
        die("Database Error: NEON_DATABASE_URL not found in environment or .env file.\n");
    }
    
    echo "Connecting to Neon PostgreSQL...\n";
    
    try {
        $urlParts = parse_url($neonUrl);
        if ($urlParts === false) {
            throw new Exception("Failed to parse NEON_DATABASE_URL.");
        }
        
        $host = str_replace('-pooler', '', $urlParts['host'] ?? '');
        $port = $urlParts['port'] ?? '5432';
        $user = $urlParts['user'] ?? '';
        $pass = $urlParts['pass'] ?? '';
        $path = $urlParts['path'] ?? '';
        $dbname = ltrim($path, '/');
        
        // Extract sslmode from query string (e.g. sslmode=require)
        $query = [];
        if (!empty($urlParts['query'])) parse_str($urlParts['query'], $query);
        $sslmode = $query['sslmode'] ?? 'require';
        
        if (empty($host) || empty($user) || empty($dbname)) {
            throw new Exception("Missing required components in NEON_DATABASE_URL.");
        }
        
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;user=$user;password=$pass;sslmode=$sslmode";
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Handle persistent connections better
        
        // Ensure licenses table exists (matching deployer schema)
        $pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
            license_key TEXT PRIMARY KEY,
            status TEXT,
            expires_at TEXT,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
        )");
        
        echo "Connected to Neon PostgreSQL successfully.\n";
        return $pdo;
    } catch (Exception $e) {
        die("Database Error: " . $e->getMessage() . "\n");
    }
}

$pdo = getPdo();

// Telegram API Helper
function apiRequest($method, $token, $parameters = []) {
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    $parameters["method"] = $method;

    $handle = curl_init("https://api.telegram.org/bot$token/");
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    curl_setopt($handle, CURLOPT_POST, true);
    curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

    $response = curl_exec($handle);
    if ($response === false) {
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        error_log("Curl error: " . $error);
        curl_close($handle);
        return false;
    }
    curl_close($handle);
    $response = json_decode($response, true);
    if (isset($response['ok']) && $response['ok'] == true) {
        return $response['result'];
    }
    return false;
}

function sendMessage($chatId, $text, $token, $keyboard = null) {
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    apiRequest("sendMessage", $token, $data);
}

// Crypto Price Helper with Fallback
function getCryptoPrices() {
    // 1. Try CoinGecko (Best coverage)
    $url = "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,tether&vs_currencies=usd";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'L1mkLicenseBot/1.0');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['bitcoin']['usd'])) {
            return $data;
        }
    }

    // 2. Fallback to Binance (Very reliable)
    $url = "https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['price'])) {
            return [
                'bitcoin' => ['usd' => (float)$data['price']],
                'tether' => ['usd' => 1.00] // Assume peg for fallback
            ];
        }
    }

    // 3. Fallback to Coinbase (Another reliable source)
    $url = "https://api.coinbase.com/v2/prices/BTC-USD/spot";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['data']['amount'])) {
            return [
                'bitcoin' => ['usd' => (float)$data['data']['amount']],
                'tether' => ['usd' => 1.00] // Assume peg for fallback
            ];
        }
    }

    return null;
}

// Main Loop
$updateId = 0;
while (true) {
    $updates = apiRequest("getUpdates", $botToken, ['offset' => $updateId + 1, 'timeout' => 30]);
    
    if ($updates) {
        foreach ($updates as $update) {
            $updateId = $update['update_id'];
            
            $chatId = null;
            $text = '';
            $callbackQueryId = null;

            if (isset($update['message'])) {
                $message = $update['message'];
                $chatId = $message['chat']['id'];
                $text = $message['text'] ?? '';
            } elseif (isset($update['callback_query'])) {
                $chatId = $update['callback_query']['message']['chat']['id'];
                $data = $update['callback_query']['data'];
                $callbackQueryId = $update['callback_query']['id'];
                
                // Admin Approval Logic
                if (strpos($data, 'approve_') === 0 || strpos($data, 'decline_') === 0) {
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                        apiRequest("answerCallbackQuery", $botToken, ['callback_query_id' => $callbackQueryId, 'text' => '⛔ Admin only']);
                        continue;
                    }

                    $parts = explode('_', $data);
                    $action = $parts[0];
                    $targetUserId = end($parts); // User ID is the last part
                    
                    if ($action === 'approve') {
                        $days = 30; // Default approval duration
                        if (isset($parts[1]) && is_numeric($parts[1])) $days = (int)$parts[1];

                        $key = 'LIC-' . strtoupper(bin2hex(random_bytes(8)));
                        $expires = date('c', strtotime("+$days days"));
                        
                        try {
                            $created = date('c');
                            $stmt = $pdo->prepare("INSERT INTO licenses (license_key, status, expires_at, created_at) VALUES (?, 'active', ?, ?)");
                            $stmt->execute([$key, $expires, $created]);
                            
                            // Notify User
                            sendMessage($targetUserId, "✅ *Payment Approved!*\n\nHere is your license ($days Days):\n`$key`\n\nExpires: $expires", $botToken);
                            
                            // Notify Admin
                            sendMessage($chatId, "✅ License sent to user `$targetUserId`.\nKey: `$key`", $botToken);
                            
                            // Remove buttons from the original message to prevent double-clicking
                            apiRequest("editMessageReplyMarkup", $botToken, [
                                'chat_id' => $chatId,
                                'message_id' => $update['callback_query']['message']['message_id'],
                                'reply_markup' => json_encode(['inline_keyboard' => []])
                            ]);

                        } catch (Exception $e) {
                            sendMessage($chatId, "❌ Error generating license: " . $e->getMessage(), $botToken);
                        }
                    } elseif ($action === 'decline') {
                        // Notify User
                        sendMessage($targetUserId, "❌ *Payment Declined.*\n\nPlease contact admin if you think this is a mistake.", $botToken);
                        
                        // Notify Admin
                        sendMessage($chatId, "❌ Declined payment for user `$targetUserId`.", $botToken);
                        
                        // Remove buttons
                        apiRequest("editMessageReplyMarkup", $botToken, [
                            'chat_id' => $chatId,
                            'message_id' => $update['callback_query']['message']['message_id'],
                            'reply_markup' => json_encode(['inline_keyboard' => []])
                        ]);
                    }
                } elseif (strpos($data, 'plan_') === 0) {
                    // Handle Plan Selection
                    $parts = explode('_', $data);
                    // Format: plan_{days}_{usd_price}
                    $days = isset($parts[1]) ? (int)$parts[1] : 30;
                    $usdPrice = isset($parts[2]) ? (float)$parts[2] : 0;
                    
                    // Fetch Crypto Prices
                    apiRequest("answerCallbackQuery", $botToken, ['callback_query_id' => $callbackQueryId, 'text' => '⏳ Calculating crypto rates...']);
                    
                    $prices = getCryptoPrices();
                    $btcRate = $prices['bitcoin']['usd'] ?? 0;
                    $usdtRate = $prices['tether']['usd'] ?? 1.0; // Usually close to 1
                    
                    if ($btcRate > 0) {
                        $btcAmount = number_format($usdPrice / $btcRate, 8, '.', '');
                        $usdtAmount = number_format($usdPrice / $usdtRate, 2, '.', '');
                        
                        $invoiceMsg = "🧾 *Invoice Generated*\n\n";
                        $invoiceMsg .= "📅 *Plan:* $days Days\n";
                        $invoiceMsg .= "💵 *Price:* $$usdPrice USD\n\n";
                        
                        if ($btcAddress) {
                            $invoiceMsg .= "🔹 *Bitcoin (BTC):*\nAmount: `$btcAmount BTC`\nAddress:\n`$btcAddress`\n\n";
                        }
                        if ($usdtAddress) {
                            $invoiceMsg .= "🔸 *USDT (TRC20):*\nAmount: `$usdtAmount USDT`\nAddress:\n`$usdtAddress`\n\n";
                        }
                        
                        $invoiceMsg .= "⚠️ *Instructions:* Send the EXACT amount to one of the addresses above. Then send the transaction ID (TXID) here as a message.";
                        
                        sendMessage($chatId, $invoiceMsg, $botToken);
                    } else {
                        sendMessage($chatId, "❌ Error fetching crypto rates. Please try again later.", $botToken);
                    }
                }
                
                // Acknowledge callback to stop loading animation
                apiRequest("answerCallbackQuery", $botToken, ['callback_query_id' => $callbackQueryId]);
                
                // Fix: Prevent falling through to message logic (which sends "Unknown command")
                continue;
            }

            if ($chatId) {
                // Admin Check REMOVED: Allow all users to interact with the bot
                /*
                if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                    sendMessage($chatId, "⛔ You are not authorized to use this bot.", $botToken);
                    continue;
                }
                */
                
                // Persistent Keyboard Menu
                $keyboard = [
                    'keyboard' => [
                        [
                            ['text' => '💰 Buy License'],
                            ['text' => '🔑 Generate (Admin)']
                        ],
                        [
                            ['text' => '📋 List Licenses'],
                            ['text' => '🧹 Clean Expired']
                        ],
                        [
                            ['text' => '➕ Add License'],
                            ['text' => '🛑 Revoke License']
                        ]
                    ],
                    'resize_keyboard' => true,
                    'persistent' => true
                ];

                if ($text === '/start') {
                    sendMessage($chatId, "👋 Welcome to @ClosedServiceLicense Bot!\n\nUse the menu below to manage licenses or purchase one.", $botToken, $keyboard);
                } elseif ($text === '💰 Buy License' || $text === '/buy') {
                    // Send Duration/Plan Selection
                    $msg = "🛒 *Select a Plan:*\n\nChoose a license duration to proceed with payment.";
                    
                    $inlineKeyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '10 Days ($130)', 'callback_data' => 'plan_10_130'],
                                ['text' => '20 Days ($210)', 'callback_data' => 'plan_20_210']
                            ],
                            [
                                ['text' => '30 Days ($300)', 'callback_data' => 'plan_30_300']
                            ]
                        ]
                    ];
                    
                    sendMessage($chatId, $msg, $botToken, $inlineKeyboard);
                    /*
                    $msg = "💳 *Payment Methods:*\n\n";
                    if ($btcAddress) $msg .= "🔹 *BTC (Bitcoin):*\n`$btcAddress`\n\n";
                    if ($usdtAddress) $msg .= "🔸 *USDT (TRC20):*\n`$usdtAddress`\n\n";
                    $msg .= "⚠️ *After payment:* Send the transaction hash (TXID) here for verification (Manual verification for now).";
                    
                    sendMessage($chatId, $msg, $botToken, $keyboard);
                    */
                } elseif ($text === '10 Days' || $text === '20 Days' || $text === '30 Days') {
                    // Admin only for direct generation
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only. Please use '💰 Buy License'.", $botToken, $keyboard);
                         continue;
                    }

                    // Parse days
                    $days = (int)filter_var($text, FILTER_SANITIZE_NUMBER_INT);
                    if (!$days) $days = 30; // Default fallback

                    // Generate License
                    $key = 'LIC-' . strtoupper(bin2hex(random_bytes(8)));
                    $expires = date('c', strtotime("+$days days"));
                    
                    try {
                        $created = date('c');
                        $stmt = $pdo->prepare("INSERT INTO licenses (license_key, status, expires_at, created_at) VALUES (?, 'active', ?, ?)");
                        $stmt->execute([$key, $expires, $created]);
                        
                        sendMessage($chatId, "✅ *New License Generated ($days Days):*\n\n`$key`\n\nExpires: $expires", $botToken, $keyboard);
                    } catch (Exception $e) {
                        sendMessage($chatId, "❌ Error generating license: " . $e->getMessage(), $botToken, $keyboard);
                    }
                } elseif ($text === '/gen' || $text === '🔑 Generate (Admin)') {
                     // Admin only
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only. Please use '💰 Buy License'.", $botToken, $keyboard);
                         continue;
                    }

                    // Show duration options for Admin
                    $adminKeyboard = [
                        'keyboard' => [
                            [
                                ['text' => '10 Days'],
                                ['text' => '20 Days'],
                                ['text' => '30 Days']
                            ],
                            [
                                ['text' => '🔙 Back to Menu']
                            ]
                        ],
                        'resize_keyboard' => true,
                        'persistent' => true
                    ];
                    sendMessage($chatId, "Select duration:", $botToken, $adminKeyboard);

                } elseif ($text === '➕ Add License') {
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only.", $botToken, $keyboard);
                         continue;
                    }
                    sendMessage($chatId, "Use:\n`/add LICENSE_KEY DAYS`\n\nExample:\n`/add LIC-ABCDEF1234567890 30`\n\nYou can also use an exact date:\n`/add LIC-ABCDEF1234567890 2026-12-31`", $botToken, $keyboard);
                } elseif ($text === '🛑 Revoke License') {
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only.", $botToken, $keyboard);
                         continue;
                    }
                    sendMessage($chatId, "Use:\n`/revoke LICENSE_KEY`\n\nExample:\n`/revoke LIC-ABCDEF1234567890`", $botToken, $keyboard);
                } elseif (strpos($text, '/add') === 0) {
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only.", $botToken, $keyboard);
                         continue;
                    }
                    $parts = preg_split('/\s+/', trim($text));
                    $manualKey = $parts[1] ?? '';
                    $expiryInput = $parts[2] ?? '';
                    if ($manualKey === '') {
                        sendMessage($chatId, "Usage:\n`/add LICENSE_KEY DAYS`\n\nExample:\n`/add LIC-ABCDEF1234567890 30`", $botToken, $keyboard);
                        continue;
                    }
                    $expires = '';
                    if ($expiryInput === '') {
                        $expires = date('c', strtotime("+30 days"));
                    } elseif (is_numeric($expiryInput)) {
                        $days = (int)$expiryInput;
                        if ($days <= 0) $days = 30;
                        $expires = date('c', strtotime("+$days days"));
                    } else {
                        $ts = strtotime($expiryInput);
                        if ($ts === false) {
                            sendMessage($chatId, "Invalid expiry. Use days or date (YYYY-MM-DD).", $botToken, $keyboard);
                            continue;
                        }
                        $expires = date('c', $ts);
                    }
                    try {
                        $created = date('c');
                        $stmt = $pdo->prepare("INSERT INTO licenses (license_key, status, expires_at, created_at) VALUES (?, 'active', ?, ?) ON CONFLICT (license_key) DO UPDATE SET status = 'active', expires_at = EXCLUDED.expires_at, created_at = EXCLUDED.created_at");
                        $stmt->execute([$manualKey, $expires, $created]);
                        sendMessage($chatId, "✅ *License Added:*\n\n`$manualKey`\n\nExpires: $expires", $botToken, $keyboard);
                    } catch (Exception $e) {
                        sendMessage($chatId, "❌ Error adding license: " . $e->getMessage(), $botToken, $keyboard);
                    }
                } elseif (strpos($text, '/revoke') === 0 || strpos($text, '/stop') === 0) {
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only.", $botToken, $keyboard);
                         continue;
                    }
                    $parts = preg_split('/\s+/', trim($text));
                    $targetKey = $parts[1] ?? '';
                    if ($targetKey === '') {
                        sendMessage($chatId, "Usage:\n`/revoke LICENSE_KEY`\n\nExample:\n`/revoke LIC-ABCDEF1234567890`", $botToken, $keyboard);
                        continue;
                    }
                    try {
                        $stmt = $pdo->prepare("UPDATE licenses SET status = 'revoked' WHERE license_key = ?");
                        $stmt->execute([$targetKey]);
                        if ($stmt->rowCount() > 0) {
                            sendMessage($chatId, "🛑 *License Revoked:*\n\n`$targetKey`", $botToken, $keyboard);
                        } else {
                            sendMessage($chatId, "⚠️ License not found:\n`$targetKey`", $botToken, $keyboard);
                        }
                    } catch (Exception $e) {
                        sendMessage($chatId, "❌ Error revoking license: " . $e->getMessage(), $botToken, $keyboard);
                    }
                } elseif ($text === '🔙 Back to Menu') {
                    sendMessage($chatId, "Main Menu:", $botToken, $keyboard);
                } elseif ($text === '/list' || $text === '📋 List Licenses') {
                    // Admin only
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only.", $botToken, $keyboard);
                         continue;
                    }
                    
                    $stmt = $pdo->prepare("SELECT license_key, expires_at FROM licenses WHERE status = 'active' AND expires_at > ?");
                    $stmt->execute([date('c')]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($rows)) {
                        sendMessage($chatId, "No active licenses found.", $botToken, $keyboard);
                    } else {
                        $msg = "📋 *Active Licenses:*\n";
                        foreach ($rows as $row) {
                            $msg .= "- `{$row['license_key']}` (Exp: " . substr($row['expires_at'], 0, 10) . ")\n";
                        }
                        sendMessage($chatId, $msg, $botToken, $keyboard);
                    }
                } elseif ($text === '/clean' || $text === '🧹 Clean Expired') {
                    // Admin only
                    if ($adminChatId && (string)$chatId !== (string)$adminChatId) {
                         sendMessage($chatId, "⛔ Admin only.", $botToken, $keyboard);
                         continue;
                    }

                    $stmt = $pdo->prepare("DELETE FROM licenses WHERE expires_at < ?");
                    $stmt->execute([date('c')]);
                    $count = $stmt->rowCount();
                    sendMessage($chatId, "🧹 Removed $count expired licenses.", $botToken, $keyboard);
                } else {
                    // Ignore echo messages (e.g. user copy-pasting instructions)
                    if (strpos($text, 'Instructions:') !== false || strpos($text, '⚠️') === 0) {
                        sendMessage($chatId, "⚠️ Please send ONLY the transaction hash (TXID) string.\n\nExample: `7f8...1a2`", $botToken, $keyboard);
                        continue;
                    }

                    // Check if message looks like a TXID (relaxed check)
                    // Allow alphanumeric, 30+ chars, ignoring whitespace
                    $cleanText = trim($text);
                    if (strlen($cleanText) > 30 && preg_match('/^[a-zA-Z0-9]+$/', $cleanText)) {
                         sendMessage($chatId, "📨 *TXID Received:* `$cleanText`\n\nAdmin will verify and send your license shortly.", $botToken, $keyboard);
                         // Notify Admin
                          if ($adminChatId) {
                              $adminButtons = [
                                  'inline_keyboard' => [
                                      [
                                          ['text' => '✅ Approve (10 Days)', 'callback_data' => "approve_10_$chatId"],
                                          ['text' => '✅ Approve (20 Days)', 'callback_data' => "approve_20_$chatId"]
                                      ],
                                      [
                                          ['text' => '✅ Approve (30 Days)', 'callback_data' => "approve_30_$chatId"],
                                          ['text' => '❌ Decline', 'callback_data' => "decline_$chatId"]
                                      ]
                                  ]
                              ];
                              sendMessage($adminChatId, "🔔 *New Payment Report:*\nUser: `$chatId`\nTXID: `$cleanText`\n\nUse buttons below or /gen to create a license for them.", $botToken, $adminButtons);
                          }
                    } else {
                         sendMessage($chatId, "Unknown command. Use the menu.", $botToken, $keyboard);
                    }
                }
            }
        }
    }
    
    sleep(1);
}
