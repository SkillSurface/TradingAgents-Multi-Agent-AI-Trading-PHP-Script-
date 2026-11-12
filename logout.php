<?php
/**
 * TradingAgents PHP 8.4 — Secure Session Logout
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

use TradingAgents\Bootstrap;
use TradingAgents\Logger;

Bootstrap::init(false);

$uid = $_SESSION['ta_user']['id'] ?? null;
$uname = $_SESSION['ta_user']['username'] ?? 'user';

if ($uid) {
    Logger::log('INFO', 'auth', "User {$uname} signed out.", (int)$uid);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit;
