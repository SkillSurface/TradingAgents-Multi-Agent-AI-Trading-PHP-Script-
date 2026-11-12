<?php
/**
 * TradingAgents PHP 8.4 — AJAX & REST JSON Controller (`api.php`)
 *
 * Optimized for Namecheap Shared Hosting / LiteSpeed:
 * - Calls `Bootstrap::releaseSessionLock()` immediately after CSRF/auth validation so
 *   concurrent page clicks (`index.php?tab=report`) never hang waiting for the session file lock!
 * - Keeps `step_analysis` JSON responses lightweight so ModSecurity / LiteSpeed response body limits
 *   never trigger a false 404/503 error.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

use TradingAgents\AgentOrchestrator;
use TradingAgents\ApiKeyManager;
use TradingAgents\Bootstrap;
use TradingAgents\Cache;
use TradingAgents\CronManager;
use TradingAgents\Database;
use TradingAgents\Logger;
use TradingAgents\MarketDataService;
use TradingAgents\ReportExporter;
use TradingAgents\Security;

Bootstrap::init(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = Security::requireAuth();
$userId = (int)$user['id'];

$rawInput = file_get_contents('php://input');
$jsonBody = ($rawInput !== false && $rawInput !== '') ? json_decode($rawInput, true) : null;
$input    = is_array($jsonBody) ? array_merge($_GET, $_POST, $jsonBody) : array_merge($_GET, $_POST);

$action = trim((string)($input['action'] ?? ''));

$readOnlyActions = ['get_analysis', 'live_quote', 'settings_export'];
if (!in_array($action, $readOnlyActions, true)) {
    $csrf = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!Security::verifyCsrf($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF security token. Please reload the page.']);
        exit;
    }
}

// CRITICAL FOR SHARED HOSTING: Release PHP session file lock before running any network/LLM task!
Bootstrap::releaseSessionLock();
@ignore_user_abort(true);

try {
    switch ($action) {
        case 'start_analysis':
            if ($user['role'] === 'viewer') {
                throw new RuntimeException('Viewer accounts cannot launch new AI analyses.');
            }
            $ticker   = (string)($input['ticker'] ?? '');
            $date     = (string)($input['trade_date'] ?? gmdate('Y-m-d'));
            $analysts = isset($input['analysts']) && is_array($input['analysts'])
                ? $input['analysts']
                : explode(',', (string)($input['analysts'] ?? 'market,social,news,fundamentals'));
            $debateRounds = (int)($input['max_debate_rounds'] ?? Database::getSetting('max_debate_rounds', '1'));
            $riskRounds   = (int)($input['max_risk_rounds'] ?? Database::getSetting('max_risk_discuss_rounds', '1'));
            $qProv = trim((string)($input['quick_provider'] ?? ''));
            $qMod  = trim((string)($input['quick_model'] ?? ''));
            $dProv = trim((string)($input['deep_provider'] ?? ''));
            $dMod  = trim((string)($input['deep_model'] ?? ''));
            $lang  = trim((string)($input['output_language'] ?? Database::getSetting('output_language', 'English')));

            $created = AgentOrchestrator::createAnalysis(
                $userId,
                $ticker,
                $date,
                $analysts,
                $debateRounds,
                $riskRounds,
                $qProv ?: null,
                $qMod ?: null,
                $dProv ?: null,
                $dMod ?: null,
                $lang
            );
            $queue = AgentOrchestrator::getQueue((int)$created['analysis_id']);
            echo json_encode(['ok' => true, 'data' => $created, 'queue' => $queue]);
            break;

        case 'step_analysis':
            $analysisId = (int)($input['analysis_id'] ?? 0);
            $res = AgentOrchestrator::executeNextStep($analysisId);
            // Strip bulky full_state_json and full reports list from intermediate step polling
            // to keep AJAX payloads small and fast on shared hosting
            if (isset($res['analysis']['full_state_json'])) {
                unset($res['analysis']['full_state_json']);
            }
            if (isset($res['analysis']['investment_thesis'])) {
                unset($res['analysis']['investment_thesis']);
            }
            unset($res['reports']);
            echo json_encode($res);
            break;

        case 'get_analysis':
            $analysisId = (int)($input['analysis_id'] ?? 0);
            $analysis = Database::fetchOne(
                "SELECT `id`, `run_uuid`, `user_id`, `ticker`, `company_name`, `asset_type`, `trade_date`,
                        `status`, `current_step`, `progress_pct`, `selected_analysts`, `max_debate_rounds`,
                        `max_risk_rounds`, `quick_think_provider`, `quick_think_model`, `deep_think_provider`,
                        `deep_think_model`, `instrument_context`, `research_recommendation`, `trader_action`,
                        `entry_price`, `stop_loss`, `price_target`, `position_sizing`, `time_horizon`,
                        `final_rating`, `sentiment_band`, `sentiment_score`, `market_price_at_analysis`,
                        `currency`, `executive_summary`, `total_llm_calls`, `total_tokens_used`,
                        `execution_time_ms`, `error_message`, `created_at`, `completed_at`
                 FROM `analyses` WHERE `id` = :id",
                [':id' => $analysisId]
            );
            if (!$analysis) {
                throw new RuntimeException('Analysis not found.');
            }
            $reports = AgentOrchestrator::getReports($analysisId);
            foreach ($reports as &$rep) {
                $rep['html'] = ReportExporter::markdownToHtml((string)$rep['report_markdown']);
            }
            $queue = AgentOrchestrator::getQueue($analysisId);
            echo json_encode([
                'ok'       => true,
                'analysis' => $analysis,
                'reports'  => $reports,
                'queue'    => $queue,
            ]);
            break;

        case 'live_quote':
            $ticker = Security::cleanTicker((string)($input['ticker'] ?? 'NVDA'));
            $date   = (string)($input['trade_date'] ?? gmdate('Y-m-d'));
            $data   = MarketDataService::getOhlcvAndQuote($ticker, $date);
            echo json_encode([
                'ok'     => true,
                'ticker' => $ticker,
                'meta'   => $data['meta'] ?? [],
                'latest' => $data['latest'] ?? [],
                'bars'   => array_slice($data['bars'] ?? [], -60),
            ]);
            break;

        case 'api_key_add':
            Security::requireAdmin();
            $id = ApiKeyManager::addKey(
                (string)($input['provider_slug'] ?? 'openrouter'),
                (string)($input['key_label'] ?? ''),
                (string)($input['api_key'] ?? ''),
                (string)($input['tier_type'] ?? 'free'),
                (int)($input['priority'] ?? 10),
                !empty($input['base_url']) ? (string)$input['base_url'] : null,
                $userId
            );
            $verify = !empty($input['verify_now']) ? ApiKeyManager::verifyKey($id) : null;
            echo json_encode(['ok' => true, 'key_id' => $id, 'verification' => $verify]);
            break;

        case 'api_key_verify':
            Security::requireAdmin();
            $id = (int)($input['key_id'] ?? 0);
            echo json_encode(ApiKeyManager::verifyKey($id));
            break;

        case 'api_key_toggle':
            Security::requireAdmin();
            $id = (int)($input['key_id'] ?? 0);
            $en = (int)($input['enable'] ?? 1) === 1;
            ApiKeyManager::toggleKey($id, $en);
            echo json_encode(['ok' => true]);
            break;

        case 'api_key_reset':
            Security::requireAdmin();
            $id = (int)($input['key_id'] ?? 0);
            ApiKeyManager::resetKeyStats($id);
            echo json_encode(['ok' => true]);
            break;

        case 'api_key_delete':
            Security::requireAdmin();
            $id = (int)($input['key_id'] ?? 0);
            ApiKeyManager::deleteKey($id);
            echo json_encode(['ok' => true]);
            break;

        case 'api_key_generate':
            Security::requireAdmin();
            $randKey = Security::generateRandomKey('ta_sk_', 24);
            echo json_encode(['ok' => true, 'generated_key' => $randKey]);
            break;

        case 'portfolio_add_position':
            $ticker = Security::cleanTicker((string)($input['ticker'] ?? ''));
            $side   = strtoupper(trim((string)($input['side'] ?? 'LONG'))) === 'SHORT' ? 'SHORT' : 'LONG';
            $qty    = (float)($input['quantity'] ?? 0);
            $entry  = (float)($input['avg_entry_price'] ?? 0);
            $stop   = !empty($input['stop_loss']) ? (float)$input['stop_loss'] : null;
            $tp     = !empty($input['take_profit']) ? (float)$input['take_profit'] : null;
            $notes  = trim((string)($input['notes'] ?? ''));

            if ($ticker === '' || $qty <= 0 || $entry <= 0) {
                throw new RuntimeException('Ticker, positive quantity, and entry price are required.');
            }

            $port = Database::fetchOne("SELECT * FROM `portfolios` WHERE `user_id` = :uid ORDER BY `is_default` DESC LIMIT 1", [':uid' => $userId]);
            if (!$port) {
                $pid = Database::insert(
                    "INSERT INTO `portfolios` (`user_id`, `portfolio_name`, `base_currency`, `cash_balance`, `initial_capital`, `is_default`)
                     VALUES (:uid, 'Primary Paper Portfolio', 'USD', 100000.00, 100000.00, 1)",
                    [':uid' => $userId]
                );
                $port = Database::fetchOne("SELECT * FROM `portfolios` WHERE `id` = ?", [$pid]);
            }

            $cost = $qty * $entry;
            Database::insert(
                "INSERT INTO `portfolio_positions`
                 (`portfolio_id`, `user_id`, `ticker`, `side`, `quantity`, `avg_entry_price`, `current_price`, `stop_loss`, `take_profit`, `notes`, `status`)
                 VALUES (:pid, :uid, :t, :s, :q, :e, :e2, :sl, :tp, :n, 'open')",
                [
                    ':pid' => $port['id'],
                    ':uid' => $userId,
                    ':t'   => $ticker,
                    ':s'   => $side,
                    ':q'   => $qty,
                    ':e'   => $entry,
                    ':e2'  => $entry,
                    ':sl'  => $stop,
                    ':tp'  => $tp,
                    ':n'   => mb_substr($notes, 0, 500),
                ]
            );

            if ($side === 'LONG') {
                Database::query("UPDATE `portfolios` SET `cash_balance` = GREATEST(0, `cash_balance` - :c) WHERE `id` = :pid", [':c' => $cost, ':pid' => $port['id']]);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'portfolio_close_position':
            $posId = (int)($input['position_id'] ?? 0);
            $pos = Database::fetchOne("SELECT * FROM `portfolio_positions` WHERE `id` = :id AND `user_id` = :uid", [':id' => $posId, ':uid' => $userId]);
            if (!$pos || $pos['status'] !== 'open') {
                throw new RuntimeException('Open position not found.');
            }
            $quote = MarketDataService::getOhlcvAndQuote((string)$pos['ticker'], gmdate('Y-m-d'));
            $exitPrice = !empty($input['exit_price'])
                ? (float)$input['exit_price']
                : (float)($quote['latest']['close'] ?? $pos['avg_entry_price']);

            $qty = (float)$pos['quantity'];
            $entry = (float)$pos['avg_entry_price'];
            $pnl = ($pos['side'] === 'LONG')
                ? ($exitPrice - $entry) * $qty
                : ($entry - $exitPrice) * $qty;

            Database::query(
                "UPDATE `portfolio_positions` SET `status` = 'closed', `current_price` = :px, `realized_pnl` = :pnl, `closed_at` = NOW() WHERE `id` = :id",
                [':px' => $exitPrice, ':pnl' => $pnl, ':id' => $posId]
            );
            $proceeds = ($pos['side'] === 'LONG') ? ($qty * $exitPrice) : (($qty * $entry) + $pnl);
            Database::query(
                "UPDATE `portfolios` SET `cash_balance` = `cash_balance` + :pr WHERE `id` = :pid",
                [':pr' => max(0, $proceeds), ':pid' => $pos['portfolio_id']]
            );
            echo json_encode(['ok' => true, 'exit_price' => $exitPrice, 'realized_pnl' => round($pnl, 2)]);
            break;

        case 'watchlist_add':
            $ticker = Security::cleanTicker((string)($input['ticker'] ?? ''));
            if ($ticker === '') {
                throw new RuntimeException('Ticker symbol is required.');
            }
            $identity = MarketDataService::resolveInstrumentContext($ticker, gmdate('Y-m-d'));
            Database::query(
                "INSERT INTO `watchlists` (`user_id`, `ticker`, `company_name`, `asset_type`, `alert_above`, `alert_below`, `last_price`, `notes`)
                 VALUES (:uid, :t, :cn, :at, :aa, :ab, :lp, :n)
                 ON DUPLICATE KEY UPDATE `alert_above` = VALUES(`alert_above`), `alert_below` = VALUES(`alert_below`), `last_price` = VALUES(`last_price`), `notes` = VALUES(`notes`)",
                [
                    ':uid' => $userId,
                    ':t'   => $ticker,
                    ':cn'  => $identity['company_name'],
                    ':at'  => $identity['asset_type'],
                    ':aa'  => !empty($input['alert_above']) ? (float)$input['alert_above'] : null,
                    ':ab'  => !empty($input['alert_below']) ? (float)$input['alert_below'] : null,
                    ':lp'  => $identity['latest_price'],
                    ':n'   => trim((string)($input['notes'] ?? '')),
                ]
            );
            echo json_encode(['ok' => true]);
            break;

        case 'watchlist_delete':
            $wid = (int)($input['id'] ?? 0);
            Database::query("DELETE FROM `watchlists` WHERE `id` = :id AND `user_id` = :uid", [':id' => $wid, ':uid' => $userId]);
            echo json_encode(['ok' => true]);
            break;

        case 'cron_run':
            Security::requireAdmin();
            $job = trim((string)($input['job'] ?? 'all'));
            $res = CronManager::run($job, 'admin_manual');
            echo json_encode($res);
            break;

        case 'cache_flush':
            Security::requireAdmin();
            $count = Cache::flushAll();
            echo json_encode(['ok' => true, 'flushed' => $count]);
            break;

        case 'settings_save':
            Security::requireAdmin();
            $allowedKeys = [
                'site_name', 'site_tagline', 'default_theme', 'allow_registration',
                'QuickThinkProvider', 'QuickThinkModel', 'DeepThinkProvider', 'DeepThinkModel',
                'openrouter_auto_fallback', 'openrouter_free_fallback_chain',
                'llm_temperature', 'llm_max_tokens', 'llm_max_retries',
                'max_debate_rounds', 'max_risk_discuss_rounds', 'default_analysts',
                'output_language', 'holding_period_days', 'benchmark_ticker',
                'cache_ttl_ohlcv', 'cache_ttl_fundamentals', 'cache_ttl_news', 'cache_ttl_sec_edgar',
                'log_retention_days', 'sec_edgar_user_agent', 'telegram_bot_token',
            ];
            foreach ($allowedKeys as $k) {
                if (array_key_exists($k, $input)) {
                    Database::setSetting($k, trim((string)$input[$k]));
                }
            }
            Logger::log('INFO', 'system', 'Updated platform settings.', $userId);
            echo json_encode(['ok' => true]);
            break;

        case 'settings_export':
            Security::requireAdmin();
            $settings = Database::getSettings(true);
            unset($settings['cron_secret_token'], $settings['telegram_bot_token']);
            header('Content-Disposition: attachment; filename="tradingagents_settings_' . gmdate('Ymd') . '.json"');
            echo json_encode([
                'exported_at' => gmdate('c'),
                'platform'    => 'TradingAgents PHP 8.4',
                'settings'    => $settings,
            ], JSON_PRETTY_PRINT);
            break;

        case 'user_create':
            Security::requireAdmin();
            $uname = trim((string)($input['username'] ?? ''));
            $email = trim((string)($input['email'] ?? ''));
            $pass  = (string)($input['password'] ?? '');
            $role  = in_array($input['role'] ?? '', ['admin', 'analyst', 'viewer'], true) ? $input['role'] : 'analyst';
            $disp  = trim((string)($input['display_name'] ?? $uname));

            if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $uname) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                throw new RuntimeException('Valid username (3-32 chars), email, and password (8+ chars) required.');
            }
            $newUid = Database::insert(
                "INSERT INTO `users` (`username`, `email`, `password_hash`, `display_name`, `role`, `is_active`)
                 VALUES (:u, :e, :p, :d, :r, 1)",
                [
                    ':u' => $uname,
                    ':e' => $email,
                    ':p' => Security::hashPassword($pass),
                    ':d' => $disp,
                    ':r' => $role,
                ]
            );
            Database::insert(
                "INSERT INTO `portfolios` (`user_id`, `portfolio_name`, `base_currency`, `cash_balance`, `initial_capital`, `is_default`)
                 VALUES (:uid, 'Primary Paper Portfolio', 'USD', 100000.00, 100000.00, 1)",
                [':uid' => $newUid]
            );
            echo json_encode(['ok' => true, 'user_id' => $newUid]);
            break;

        case 'user_toggle':
            Security::requireAdmin();
            $targetId = (int)($input['user_id'] ?? 0);
            if ($targetId === $userId) {
                throw new RuntimeException('You cannot disable your own active admin account.');
            }
            $en = (int)($input['is_active'] ?? 1);
            Database::query("UPDATE `users` SET `is_active` = :a WHERE `id` = :id", [':a' => $en ? 1 : 0, ':id' => $targetId]);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Unknown API action: {$action}"]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => Logger::scrub($e->getMessage()),
    ]);
}
