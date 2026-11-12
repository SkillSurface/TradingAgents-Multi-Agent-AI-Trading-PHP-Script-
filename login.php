<?php
/**
 * TradingAgents PHP 8.4 — Secure Login & Optional Self-Registration Page
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

use TradingAgents\Bootstrap;
use TradingAgents\Database;
use TradingAgents\Security;

Bootstrap::init(true);

if (Security::currentUser() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$successMsg = '';
$allowReg = (int)Database::getSetting('allow_registration', '0') === 1;
$siteName = (string)Database::getSetting('site_name', 'TradingAgents AI Platform');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf()) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? 'login');

        if ($action === 'register' && $allowReg) {
            $username = trim((string)($_POST['username'] ?? ''));
            $email    = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $display  = trim((string)($_POST['display_name'] ?? $username));

            if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
                $error = 'Username must be 3-32 alphanumeric characters.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $exists = Database::fetchOne("SELECT `id` FROM `users` WHERE `username` = :u OR `email` = :e", [':u' => $username, ':e' => $email]);
                if ($exists) {
                    $error = 'Username or email is already registered.';
                } else {
                    $uid = Database::insert(
                        "INSERT INTO `users` (`username`, `email`, `password_hash`, `display_name`, `role`, `is_active`)
                         VALUES (:u, :e, :p, :d, 'analyst', 1)",
                        [
                            ':u' => $username,
                            ':e' => $email,
                            ':p' => Security::hashPassword($password),
                            ':d' => $display,
                        ]
                    );
                    Database::insert(
                        "INSERT INTO `portfolios` (`user_id`, `portfolio_name`, `base_currency`, `cash_balance`, `initial_capital`, `is_default`)
                         VALUES (:uid, 'Primary Paper Portfolio', 'USD', 100000.00, 100000.00, 1)",
                        [':uid' => $uid]
                    );
                    $successMsg = 'Account created! You may now sign in below.';
                }
            }
        } else {
            $identifier = (string)($_POST['identifier'] ?? '');
            $password   = (string)($_POST['password'] ?? '');
            $res = Security::login($identifier, $password);
            if ($res['ok']) {
                header('Location: index.php');
                exit;
            }
            $error = $res['error'] ?? 'Authentication failed.';
        }
    }
}

$csrf = Security::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= htmlspecialchars($siteName, ENT_QUOTES) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex items-center justify-center p-4 font-sans">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 font-bold text-xl mb-3">
                TA
            </div>
            <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($siteName, ENT_QUOTES) ?></h1>
            <p class="text-xs text-slate-500 mt-1">Multi-Agent LLM Financial Trading Analysis Platform (PHP 8.4)</p>
        </div>

        <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6 shadow-2xl">
            <?php if ($error !== ''): ?>
                <div class="bg-rose-50 border border-rose-500/40 text-rose-800 text-xs rounded-lg p-3 mb-4">
                    <?= htmlspecialchars($error, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>
            <?php if ($successMsg !== ''): ?>
                <div class="bg-emerald-50 border border-emerald-500/40 text-emerald-800 text-xs rounded-lg p-3 mb-4">
                    <?= htmlspecialchars($successMsg, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="login">

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Username or Email</label>
                    <input type="text" name="identifier" required autofocus
                           class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Password</label>
                    <input type="password" name="password" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3.5 py-2.5 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                </div>
                <button type="submit"
                        class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm shadow-lg shadow-emerald-600/20 transition cursor-pointer">
                    Sign In to Trading Desk &rarr;
                </button>
            </form>
        </div>

        <p class="text-center text-[11px] text-slate-500 mt-6">
            Powered by TauricResearch TradingAgents Architecture &bull; PHP 8.4 + MySQL PDO
        </p>
    </div>
</body>
</html>
