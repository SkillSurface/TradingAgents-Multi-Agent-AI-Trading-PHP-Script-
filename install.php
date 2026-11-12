<?php
/**
 * TradingAgents PHP 8.4 — One-Time Web Installer (`install.php`)
 *
 * Designed for Namecheap Shared Hosting / cPanel / CloudLinux / VPS.
 * - Verifies PHP 8.1 - 8.4+ environment & required extensions (PDO, pdo_mysql, curl, openssl, mbstring, json)
 * - Tests MySQL 8.x / MariaDB connection via PDO
 * - Executes `schema.sql` to create all 13 tables and seed default settings
 * - Generates a cryptographically secure 256-bit AES-GCM master key & cron secret token
 * - Creates the primary Administrator account (Argon2id / Bcrypt) & initial $100,000 Paper Portfolio
 * - Optionally stores and encrypts the user's first OpenRouter API key
 * - Writes `config.php` and locks the installer (`storage/installed.lock`)
 */

declare(strict_types=1);

session_start();

define('TA_BASE_PATH', __DIR__);
define('TA_LOCK_FILE', TA_BASE_PATH . '/storage/installed.lock');
define('TA_CONFIG_FILE', TA_BASE_PATH . '/config.php');
define('TA_SCHEMA_FILE', TA_BASE_PATH . '/schema.sql');

// Generate CSRF token for installer
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$isInstalled = file_exists(TA_LOCK_FILE) && file_exists(TA_CONFIG_FILE);
$errors = [];
$success = false;
$generatedCronToken = '';

// Environment check helper
function checkEnvironment(): array {
    $checks = [
        'php_version' => [
            'label' => 'PHP Version (8.1+ Required, 8.4 Recommended)',
            'current' => PHP_VERSION,
            'pass' => version_compare(PHP_VERSION, '8.1.0', '>='),
        ],
        'pdo_mysql' => [
            'label' => 'PDO & pdo_mysql Extension',
            'current' => extension_loaded('pdo') && extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing',
            'pass' => extension_loaded('pdo') && extension_loaded('pdo_mysql'),
        ],
        'curl' => [
            'label' => 'cURL Extension (AI & Market Data APIs)',
            'current' => extension_loaded('curl') ? 'Loaded' : 'Missing',
            'pass' => extension_loaded('curl'),
        ],
        'openssl' => [
            'label' => 'OpenSSL Extension (AES-256-GCM API Key Vault)',
            'current' => extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'Missing',
            'pass' => extension_loaded('openssl'),
        ],
        'mbstring' => [
            'label' => 'Multibyte String (mbstring)',
            'current' => extension_loaded('mbstring') ? 'Loaded' : 'Missing',
            'pass' => extension_loaded('mbstring'),
        ],
        'json' => [
            'label' => 'JSON Extension',
            'current' => extension_loaded('json') ? 'Loaded' : 'Missing',
            'pass' => extension_loaded('json'),
        ],
        'root_writable' => [
            'label' => 'Application Directory Writable (config.php)',
            'current' => is_writable(TA_BASE_PATH) ? 'Writable' : 'Read-Only',
            'pass' => is_writable(TA_BASE_PATH),
        ],
        'schema_exists' => [
            'label' => 'Database Schema File (schema.sql)',
            'current' => is_readable(TA_SCHEMA_FILE) ? 'Found' : 'Missing',
            'pass' => is_readable(TA_SCHEMA_FILE),
        ],
    ];
    return $checks;
}

$envChecks = checkEnvironment();
$allChecksPass = array_reduce($envChecks, fn(bool $carry, array $item) => $carry && $item['pass'], true);

// AES-256-GCM helper for encrypting the initial OpenRouter API key during installation
function encryptInitialKey(string $plainKey, string $masterKeyHex): string {
    $key = hex2bin($masterKeyHex);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plainKey, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('OpenSSL AES-256-GCM encryption failed.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function maskKeyHint(string $plainKey): string {
    $len = strlen($plainKey);
    if ($len <= 10) {
        return substr($plainKey, 0, 2) . '***' . substr($plainKey, -2);
    }
    return substr($plainKey, 0, 6) . '...' . substr($plainKey, -4);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isInstalled) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['install_csrf'], $postedCsrf)) {
        $errors[] = 'Invalid CSRF security token. Please refresh the page and try again.';
    } elseif (!$allChecksPass) {
        $errors[] = 'Please resolve all failed server requirement checks before installing.';
    } else {
        $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');

        $siteName = trim((string)($_POST['site_name'] ?? 'TradingAgents AI Platform'));
        $appUrl   = rtrim(trim((string)($_POST['app_url'] ?? '')), '/');
        $adminUser = trim((string)($_POST['admin_username'] ?? 'admin'));
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $adminPass = (string)($_POST['admin_password'] ?? '');
        $openrouterKey = trim((string)($_POST['openrouter_api_key'] ?? ''));
        $secUserAgent = trim((string)($_POST['sec_user_agent'] ?? "TradingAgentsPHP/2.0 ({$adminEmail})"));

        if ($dbName === '' || $dbUser === '') {
            $errors[] = 'Database Name and Database Username are required.';
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $adminUser)) {
            $errors[] = 'Admin username must be 3-32 characters (letters, numbers, _, ., -).';
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid Administrator email address.';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = 'Administrator password must be at least 8 characters long.';
        }

        if (empty($errors)) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                // Attempt to select or create database (on shared hosting, DB is pre-created in cPanel)
                $safeDbName = str_replace('`', '``', $dbName);
                try {
                    $pdo->exec("USE `{$safeDbName}`");
                } catch (PDOException $e) {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo->exec("USE `{$safeDbName}`");
                }

                // Read and execute schema.sql
                $sql = file_get_contents(TA_SCHEMA_FILE);
                if ($sql === false) {
                    throw new RuntimeException('Unable to read schema.sql file.');
                }

                // Execute schema statements cleanly
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $pdo->exec($sql);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                // Generate cryptographic master key and cron secret token
                $masterKeyHex = bin2hex(random_bytes(32));
                $generatedCronToken = 'ta_cron_' . bin2hex(random_bytes(16));

                // Create storage directories
                $storageDirs = [
                    TA_BASE_PATH . '/storage',
                    TA_BASE_PATH . '/storage/cache',
                    TA_BASE_PATH . '/storage/logs',
                    TA_BASE_PATH . '/storage/reports',
                ];
                foreach ($storageDirs as $dir) {
                    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                        throw new RuntimeException("Failed to create directory: {$dir}");
                    }
                }
                // Protect storage directory with .htaccess
                file_put_contents(TA_BASE_PATH . '/storage/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");

                // Hash admin password using Argon2id if supported, otherwise Bcrypt
                $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
                $passHash = password_hash($adminPass, $algo);

                // Insert or update Admin account
                $stmt = $pdo->prepare("
                    INSERT INTO `users` (`username`, `email`, `password_hash`, `display_name`, `role`, `is_active`)
                    VALUES (:u, :e, :p, :d, 'admin', 1)
                    ON DUPLICATE KEY UPDATE
                        `email` = VALUES(`email`),
                        `password_hash` = VALUES(`password_hash`),
                        `role` = 'admin',
                        `is_active` = 1
                ");
                $stmt->execute([
                    ':u' => $adminUser,
                    ':e' => $adminEmail,
                    ':p' => $passHash,
                    ':d' => 'System Administrator',
                ]);
                $adminId = (int)$pdo->lastInsertId();
                if ($adminId === 0) {
                    $q = $pdo->prepare("SELECT `id` FROM `users` WHERE `username` = ?");
                    $q->execute([$adminUser]);
                    $adminId = (int)$q->fetchColumn();
                }

                // Create default $100,000 paper portfolio for admin
                $stmtPort = $pdo->prepare("SELECT COUNT(*) FROM `portfolios` WHERE `user_id` = ?");
                $stmtPort->execute([$adminId]);
                if ((int)$stmtPort->fetchColumn() === 0) {
                    $insPort = $pdo->prepare("
                        INSERT INTO `portfolios` (`user_id`, `portfolio_name`, `base_currency`, `cash_balance`, `initial_capital`, `is_default`)
                        VALUES (?, 'Primary Paper Portfolio', 'USD', 100000.00, 100000.00, 1)
                    ");
                    $insPort->execute([$adminId]);
                }

                // Update system settings
                $settingsUpdates = [
                    'site_name' => $siteName,
                    'cron_secret_token' => $generatedCronToken,
                    'sec_edgar_user_agent' => $secUserAgent,
                ];
                $stmtSet = $pdo->prepare("
                    INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`)
                    VALUES (:k, :v, 'general')
                    ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)
                ");
                foreach ($settingsUpdates as $k => $v) {
                    $stmtSet->execute([':k' => $k, ':v' => $v]);
                }

                // If user supplied an initial OpenRouter API key, encrypt and save it
                if ($openrouterKey !== '') {
                    $encKey = encryptInitialKey($openrouterKey, $masterKeyHex);
                    $hint = maskKeyHint($openrouterKey);
                    $stmtKey = $pdo->prepare("
                        INSERT INTO `api_keys`
                        (`provider_slug`, `provider_category`, `key_label`, `encrypted_key`, `key_hint`, `tier_type`, `priority`, `is_enabled`, `verification_status`, `created_by`)
                        VALUES ('openrouter', 'llm', 'Primary OpenRouter Key', :enc, :hint, 'free', 1, 1, 'unverified', :uid)
                    ");
                    $stmtKey->execute([
                        ':enc' => $encKey,
                        ':hint' => $hint,
                        ':uid' => $adminId,
                    ]);
                }

                // Build config.php content safely using var_export
                $configData = [
                    'app' => [
                        'name'     => $siteName,
                        'version'  => '2.0.0-PHP84',
                        'env'      => 'production',
                        'debug'    => false,
                        'url'      => $appUrl,
                        'timezone' => 'UTC',
                    ],
                    'db' => [
                        'host'      => $dbHost,
                        'port'      => $dbPort,
                        'database'  => $dbName,
                        'username'  => $dbUser,
                        'password'  => $dbPass,
                        'charset'   => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                    ],
                    'security' => [
                        'master_key'       => $masterKeyHex,
                        'session_name'     => 'TA_SESSID_' . substr(md5($masterKeyHex), 0, 8),
                        'session_lifetime' => 28800,
                        'csrf_token_ttl'   => 7200,
                        'cron_token'       => $generatedCronToken,
                    ],
                    'hosting' => [
                        'step_time_limit'   => 55,
                        'http_timeout'      => 28,
                        'connect_timeout'   => 10,
                        'enable_file_cache' => true,
                        'storage_path'      => __DIR__ . '/storage',
                    ],
                ];

                $configPhp = "<?php\n/**\n * TradingAgents PHP 8.4 — Auto-Generated Production Configuration\n * Generated on " . gmdate('Y-m-d H:i:s') . " UTC\n */\n\ndeclare(strict_types=1);\n\nif (!defined('TA_APP_BOOTSTRAPPED')) {\n    define('TA_APP_BOOTSTRAPPED', true);\n}\n\nreturn " . var_export($configData, true) . ";\n";

                if (file_put_contents(TA_CONFIG_FILE, $configPhp, LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write config.php. Check folder permissions.');
                }
                @chmod(TA_CONFIG_FILE, 0640);

                // Write installation lock file
                file_put_contents(TA_LOCK_FILE, json_encode([
                    'installed_at' => gmdate('c'),
                    'php_version'  => PHP_VERSION,
                    'admin_user'   => $adminUser,
                ], JSON_PRETTY_PRINT));

                // Log installation event
                $stmtLog = $pdo->prepare("
                    INSERT INTO `system_logs` (`level`, `channel`, `user_id`, `ip_address`, `message`)
                    VALUES ('SECURITY', 'system', ?, ?, 'TradingAgents PHP 8.4 platform installed successfully.')
                ");
                $stmtLog->execute([$adminId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                $success = true;
                $isInstalled = true;
            } catch (Throwable $ex) {
                $errors[] = 'Installation Error: ' . $ex->getMessage();
            }
        }
    }
}

$detectedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install TradingAgents AI Platform (PHP 8.4 + MySQL)</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen py-10 px-4 font-sans">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-8 border-b border-slate-200 pb-6">
            <div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-500/20 mb-2">
                    PHP 8.4 + MySQL 8.x Shared Hosting Edition
                </span>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900">TradingAgents AI Web Installer</h1>
                <p class="text-slate-500 text-sm mt-1">Multi-Agent LLM Financial Trading Analysis Framework — Zero-Dependency Setup</p>
            </div>
            <div class="text-right hidden sm:block">
                <div class="text-xs text-slate-500 uppercase tracking-wider">Reference Architecture</div>
                <div class="text-sm font-mono text-emerald-700">TauricResearch / TradingAgents</div>
            </div>
        </div>

        <?php if ($isInstalled && !$success): ?>
            <div class="bg-amber-50 border border-amber-500/40 rounded-xl p-6 mb-6">
                <h2 class="text-lg font-semibold text-amber-800 mb-2">Platform Already Installed</h2>
                <p class="text-sm text-amber-200/80 mb-4">
                    An active installation was detected (<code class="bg-black/30 px-1.5 py-0.5 rounded">config.php</code> and <code class="bg-black/30 px-1.5 py-0.5 rounded">storage/installed.lock</code> exist).
                    For security, the installer is locked. To reinstall, delete <code class="bg-black/30 px-1.5 py-0.5 rounded">storage/installed.lock</code> via cPanel File Manager.
                </p>
                <a href="login.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition">
                    Go to Login Dashboard &rarr;
                </a>
            </div>
        <?php elseif ($success): ?>
            <div class="bg-emerald-50 border border-emerald-500/40 rounded-xl p-6 mb-6">
                <div class="flex items-center gap-3 mb-3">
                    <span class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-700 flex items-center justify-center font-bold">✓</span>
                    <h2 class="text-xl font-bold text-emerald-700">Installation Completed Successfully!</h2>
                </div>
                <p class="text-sm text-slate-700 mb-4">
                    Database tables have been created, AES-256-GCM encryption keys initialized, and your Administrator account is active.
                </p>
                <div class="bg-white shadow-sm border border-slate-200 rounded-lg p-4 mb-4 text-xs font-mono space-y-2">
                    <div class="text-slate-500">Recommended cPanel Cron Job (Every 5 Minutes for Queue &amp; Maintenance):</div>
                    <div class="text-emerald-700 break-all select-all">/usr/local/bin/php <?= htmlspecialchars(TA_BASE_PATH . '/cron.php', ENT_QUOTES) ?> --job=all &gt;/dev/null 2&gt;&amp;1</div>
                    <div class="text-slate-500 pt-2">Or Web Cron URL:</div>
                    <div class="text-sky-700 break-all select-all"><?= htmlspecialchars($detectedUrl . '/cron.php?token=' . $generatedCronToken . '&job=all', ENT_QUOTES) ?></div>
                </div>
                <div class="bg-rose-50 border border-rose-500/30 rounded-lg p-3 mb-5 text-xs text-rose-700">
                    <strong>Security Best Practice:</strong> Although <code class="font-mono">install.php</code> is now locked via <code class="font-mono">storage/installed.lock</code>, we strongly recommend deleting or renaming <code class="font-mono">install.php</code> in cPanel File Manager.
                </div>
                <a href="login.php" class="inline-flex items-center px-5 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm shadow-lg shadow-emerald-600/20 transition">
                    Launch TradingAgents Dashboard &rarr;
                </a>
            </div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="bg-rose-50 border border-rose-500/40 rounded-xl p-4 mb-6">
                    <h3 class="text-sm font-semibold text-rose-700 mb-1">Please fix the following issues:</h3>
                    <ul class="list-disc list-inside text-xs text-rose-800 space-y-1">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err, ENT_QUOTES) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Step 1: Server Environment Diagnostics -->
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-6 mb-6">
                <h2 class="text-base font-semibold text-slate-900 mb-4 flex items-center justify-between">
                    <span>1. Server &amp; Shared Hosting Compatibility Check</span>
                    <span class="text-xs px-2.5 py-0.5 rounded-full <?= $allChecksPass ? 'bg-emerald-50 text-emerald-700 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-700 border border-rose-500/20' ?>">
                        <?= $allChecksPass ? 'All Checks Passed' : 'Action Required' ?>
                    </span>
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($envChecks as $check): ?>
                        <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 border border-slate-200">
                            <div>
                                <div class="text-xs font-medium text-slate-800"><?= htmlspecialchars($check['label'], ENT_QUOTES) ?></div>
                                <div class="text-[11px] font-mono text-slate-500 mt-0.5"><?= htmlspecialchars((string)$check['current'], ENT_QUOTES) ?></div>
                            </div>
                            <span class="px-2 py-0.5 text-[11px] font-semibold rounded <?= $check['pass'] ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                                <?= $check['pass'] ? 'PASS' : 'FAIL' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Configuration Form -->
            <form method="POST" action="install.php" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES) ?>">

                <!-- Database Credentials -->
                <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-6">
                    <h2 class="text-base font-semibold text-slate-900 mb-1">2. MySQL / MariaDB Database (cPanel / phpMyAdmin)</h2>
                    <p class="text-xs text-slate-500 mb-4">Create a MySQL database and user in cPanel &rarr; MySQL® Databases (grant ALL PRIVILEGES) and enter the details below.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-6 gap-4">
                        <div class="sm:col-span-4">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Database Host</label>
                            <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost', ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Port</label>
                            <input type="number" name="db_port" value="<?= htmlspecialchars((string)($_POST['db_port'] ?? '3306'), ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Database Name</label>
                            <input type="text" name="db_name" placeholder="cpaneluser_tradingagents" value="<?= htmlspecialchars($_POST['db_name'] ?? '', ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Database Username</label>
                            <input type="text" name="db_user" placeholder="cpaneluser_tauser" value="<?= htmlspecialchars($_POST['db_user'] ?? '', ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div class="sm:col-span-6">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Database Password</label>
                            <input type="password" name="db_pass" placeholder="Enter MySQL user password"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- Admin Account & Platform Settings -->
                <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-6">
                    <h2 class="text-base font-semibold text-slate-900 mb-4">3. Administrator Account &amp; Platform Identity</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Platform Name</label>
                            <input type="text" name="site_name" value="<?= htmlspecialchars($_POST['site_name'] ?? 'TradingAgents AI Platform', ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Public Site URL</label>
                            <input type="url" name="app_url" value="<?= htmlspecialchars($_POST['app_url'] ?? $detectedUrl, ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Admin Username</label>
                            <input type="text" name="admin_username" value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin', ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Admin Email (Also used for SEC EDGAR User-Agent)</label>
                            <input type="email" name="admin_email" placeholder="admin@yourdomain.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES) ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Admin Password (Min 8 characters, hashed with Argon2id/Bcrypt)</label>
                            <input type="password" name="admin_password" required minlength="8"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- Optional Initial OpenRouter API Key -->
                <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="text-base font-semibold text-slate-900">4. Initial OpenRouter API Key (Optional — Can Add Later in Vault)</h2>
                        <a href="https://openrouter.ai/keys" target="_blank" rel="noopener"
                           class="text-xs font-medium text-emerald-700 hover:text-emerald-700 underline">Get Free OpenRouter Key &rarr;</a>
                    </div>
                    <p class="text-xs text-slate-500 mb-3">
                        OpenRouter provides instant access to 15+ <span class="text-emerald-700 font-semibold">FREE</span> models (including <code class="font-mono">openrouter/free</code> auto-router, Gemma 4 31B Free, Nemotron 3 Ultra Free) and 450+ PAID models. You can manage multiple keys and 40+ AI providers inside the Admin API Vault after setup.
                    </p>
                    <input type="password" name="openrouter_api_key" placeholder="sk-or-v1-..." value="<?= htmlspecialchars($_POST['openrouter_api_key'] ?? '', ENT_QUOTES) ?>"
                           class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 font-mono focus:border-emerald-500 focus:outline-none">
                </div>

                <div class="flex items-center justify-end">
                    <button type="submit" <?= !$allChecksPass ? 'disabled' : '' ?>
                            class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold text-sm shadow-lg shadow-emerald-600/20 transition cursor-pointer">
                        Install TradingAgents Platform &amp; Initialize Database
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
