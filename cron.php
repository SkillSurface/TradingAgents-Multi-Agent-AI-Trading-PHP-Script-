<?php
/**
 * TradingAgents PHP 8.4 — CLI & Web Cron Entry Point (`cron.php`)
 *
 * Usage in cPanel Cron Jobs (CLI):
 *   /usr/local/bin/php /home/username/public_html/cron.php --job=all >/dev/null 2>&1
 *
 * Usage via Web Cron:
 *   https://yourdomain.com/cron.php?token=YOUR_CRON_SECRET_TOKEN&job=all
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

use TradingAgents\Bootstrap;
use TradingAgents\CronManager;
use TradingAgents\Database;

Bootstrap::init(false);
@set_time_limit(120);

$isCli = (php_sapi_name() === 'cli');
$job   = 'all';

if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--job=')) {
            $job = substr($arg, 6);
        }
    }
    $result = CronManager::run($job, 'cli');
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Web Cron verification via secret token
$providedToken = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
$expectedToken = (string)Database::getSetting('cron_secret_token', (string)Bootstrap::config('security.cron_token', ''));

if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing cron security token.']);
    exit;
}

$job = trim((string)($_GET['job'] ?? 'all'));
header('Content-Type: application/json');
echo json_encode(CronManager::run($job, 'web_cron'), JSON_PRETTY_PRINT);
