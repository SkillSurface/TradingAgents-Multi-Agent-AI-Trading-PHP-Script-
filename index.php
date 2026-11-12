<?php
/**
 * TradingAgents PHP 8.4 — Production Multi-Agent Financial Trading Dashboard & Admin Panel (`index.php`)
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

use TradingAgents\AgentOrchestrator;
use TradingAgents\ApiKeyManager;
use TradingAgents\Bootstrap;
use TradingAgents\CronManager;
use TradingAgents\Database;
use TradingAgents\ModelCatalog;
use TradingAgents\ReportExporter;
use TradingAgents\Security;

Bootstrap::init(true);
$user = Security::requireAuth();
$userId = (int)$user['id'];
$isAdmin = ($user['role'] === 'admin');
$csrf = Security::csrfToken();
Bootstrap::releaseSessionLock();

// Built-in Report Exporter (HTML, Print-to-PDF, and State JSON) directly inside index.php
// Eliminates any 404 Not Found if report.php is missing or blocked on shared hosting
if (!empty($_GET['export_report'])) {
    $expId = (int)($_GET['id'] ?? 0);
    $expFormat = strtolower(trim((string)($_GET['format'] ?? 'html')));
    $expDownload = isset($_GET['download']) && $_GET['download'] === '1';
    $expAnalysis = Database::fetchOne("SELECT * FROM `analyses` WHERE `id` = :id LIMIT 1", [':id' => $expId]);
    if (!$expAnalysis) {
        http_response_code(404);
        die('Analysis report not found.');
    }
    $expReports = AgentOrchestrator::getReports($expId);
    if ($expFormat === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        if ($expDownload) {
            $fname = sprintf('TradingAgents_%s_%s.json', $expAnalysis['ticker'], $expAnalysis['trade_date']);
            header('Content-Disposition: attachment; filename="' . $fname . '"');
        }
        echo json_encode(['analysis' => $expAnalysis, 'reports' => $expReports], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $autoPrint = ($expFormat === 'pdf');
    $htmlOut = ReportExporter::renderHtmlReport($expAnalysis, $expReports, $autoPrint);
    header('Content-Type: text/html; charset=utf-8');
    if ($expDownload && !$autoPrint) {
        $fname = sprintf('TradingAgents_%s_%s_%s.html', $expAnalysis['ticker'], $expAnalysis['trade_date'], $expAnalysis['final_rating'] ?: 'Report');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }
    echo $htmlOut;
    exit;
}

$settings = Database::getSettings();
$siteName = (string)($settings['site_name'] ?? 'TradingAgents AI Platform');
$activeTab = trim((string)($_GET['tab'] ?? 'dashboard'));

$totalAnalyses = (int)Database::fetchColumn("SELECT COUNT(*) FROM `analyses`");
$completedAnalyses = (int)Database::fetchColumn("SELECT COUNT(*) FROM `analyses` WHERE `status` = 'completed'");
$activeApiKeys = (int)Database::fetchColumn("SELECT COUNT(*) FROM `api_keys` WHERE `is_enabled` = 1");
$cachedItems = (int)Database::fetchColumn("SELECT COUNT(*) FROM `market_cache` WHERE `expires_at` > NOW()");

$recentAnalyses = Database::fetchAll(
    "SELECT a.`id`, a.`run_uuid`, a.`user_id`, a.`ticker`, a.`company_name`, a.`asset_type`,
            a.`trade_date`, a.`status`, a.`current_step`, a.`progress_pct`,
            a.`research_recommendation`, a.`trader_action`, a.`entry_price`, a.`stop_loss`,
            a.`price_target`, a.`final_rating`, a.`sentiment_band`, a.`sentiment_score`,
            a.`market_price_at_analysis`, a.`currency`, a.`executive_summary`,
            a.`total_tokens_used`, a.`execution_time_ms`, a.`created_at`, u.`username`
     FROM `analyses` a
     LEFT JOIN `users` u ON u.id = a.user_id
     ORDER BY a.id DESC
     LIMIT 50"
);

$viewAnalysisId = (int)($_GET['id'] ?? ($recentAnalyses[0]['id'] ?? 0));
$viewAnalysis = $viewAnalysisId > 0
    ? Database::fetchOne("SELECT * FROM `analyses` WHERE `id` = :id", [':id' => $viewAnalysisId])
    : null;
$viewReports = $viewAnalysis ? AgentOrchestrator::getReports((int)$viewAnalysis['id']) : [];

$portfolio = Database::fetchOne(
    "SELECT * FROM `portfolios` WHERE `user_id` = :uid ORDER BY `is_default` DESC LIMIT 1",
    [':uid' => $userId]
);
if (!$portfolio) {
    $pid = Database::insert(
        "INSERT INTO `portfolios` (`user_id`, `portfolio_name`, `base_currency`, `cash_balance`, `initial_capital`, `is_default`)
         VALUES (:uid, 'Primary Paper Portfolio', 'USD', 100000.00, 100000.00, 1)",
        [':uid' => $userId]
    );
    $portfolio = Database::fetchOne("SELECT * FROM `portfolios` WHERE `id` = ?", [$pid]);
}
$openPositions = Database::fetchAll(
    "SELECT * FROM `portfolio_positions` WHERE `portfolio_id` = :pid AND `status` = 'open' ORDER BY `id` DESC",
    [':pid' => $portfolio['id']]
);
$closedPositions = Database::fetchAll(
    "SELECT * FROM `portfolio_positions` WHERE `portfolio_id` = :pid AND `status` = 'closed' ORDER BY `closed_at` DESC LIMIT 25",
    [':pid' => $portfolio['id']]
);

$watchlist = Database::fetchAll("SELECT * FROM `watchlists` WHERE `user_id` = :uid ORDER BY `ticker` ASC", [':uid' => $userId]);
$memories = Database::fetchAll("SELECT * FROM `trading_memory` ORDER BY `id` DESC LIMIT 30");

$apiKeysList      = $isAdmin ? ApiKeyManager::listKeys() : [];
$providersCatalog = ModelCatalog::getProviders();
$freeOrModels     = ModelCatalog::getOpenRouterFreeModels();
$fullModelCatalog = ModelCatalog::getFullModelCatalog();
$cronJobs         = $isAdmin ? CronManager::getJobDefinitions() : [];
$cronLogs         = $isAdmin ? Database::fetchAll("SELECT * FROM `cron_logs` ORDER BY `id` DESC LIMIT 25") : [];
$sysLogs          = $isAdmin ? Database::fetchAll("SELECT * FROM `system_logs` ORDER BY `id` DESC LIMIT 40") : [];
$allUsers         = $isAdmin ? Database::fetchAll("SELECT `id`, `username`, `email`, `display_name`, `role`, `is_active`, `last_login_at`, `created_at` FROM `users` ORDER BY `id` ASC") : [];

function ratingBadgeClass(?string $rating): string {
    return match ($rating) {
        'Buy'         => 'bg-emerald-50 text-emerald-700 border-emerald-300',
        'Overweight'  => 'bg-teal-50 text-teal-700 border-teal-300',
        'Hold'        => 'bg-amber-50 text-amber-800 border-amber-300',
        'Underweight' => 'bg-orange-50 text-orange-800 border-orange-300',
        'Sell'        => 'bg-rose-50 text-rose-700 border-rose-300',
        default       => 'bg-slate-100 text-slate-700 border-slate-300',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName, ENT_QUOTES) ?> — Multi-Agent AI Trading Desk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        .agent-prose h4, .agent-prose h5 { font-weight: 700; margin-top: 1rem; margin-bottom: 0.4rem; color: #047857; }
        .agent-prose p { margin-bottom: 0.75rem; line-height: 1.65; }
        .agent-prose ul { list-style: disc; padding-left: 1.25rem; margin-bottom: 0.75rem; }
        .agent-prose li { margin-bottom: 0.25rem; }
        .agent-prose .table-wrap { overflow-x: auto; margin: 0.85rem 0; }
        .agent-prose table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .agent-prose th, .agent-prose td { padding: 0.45rem 0.65rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
        .agent-prose th { background: #f1f5f9; font-weight: 600; color: #0f172a; }
        body { background-color: #f8fafc; color: #0f172a; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex flex-col font-sans antialiased">

    <header class="sticky top-0 z-30 bg-white/95 shadow-sm backdrop-blur border-b border-slate-200 px-4 lg:px-8 py-3">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
            <a href="index.php" class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 border border-emerald-500/40 flex items-center justify-center text-emerald-700 font-bold text-base">TA</div>
                <div>
                    <div class="font-bold text-slate-900 text-base leading-tight flex items-center gap-2">
                        <?= htmlspecialchars($siteName, ENT_QUOTES) ?>
                        <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-500/20">PHP 8.4</span>
                    </div>
                    <div class="text-[11px] text-slate-500">Multi-Agent LLM Financial Trading Framework</div>
                </div>
            </a>

            <nav class="flex flex-wrap items-center gap-1 text-xs font-medium">
                <?php
                $navItems = [
                    'dashboard' => 'Run Analysis',
                    'report'    => 'Agent Reports',
                    'history'   => 'History & Compare',
                    'portfolio' => 'Paper Portfolio',
                    'watchlist' => 'Watchlist & Memory',
                ];
                if ($isAdmin) {
                    $navItems['apikeys']  = 'AI API Keys & Models';
                    $navItems['cron']     = 'Cron Jobs';
                    $navItems['settings'] = 'Admin & Settings';
                }
                foreach ($navItems as $tabKey => $tabLabel):
                    $isActive = ($activeTab === $tabKey);
                ?>
                    <a href="?tab=<?= $tabKey ?>"
                       class="px-3 py-2 rounded-lg transition <?= $isActive ? 'bg-emerald-600 text-white font-semibold shadow-sm' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-950' ?>">
                        <?= htmlspecialchars($tabLabel, ENT_QUOTES) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <div class="text-xs font-semibold text-slate-900"><?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?></div>
                    <div class="text-[10px] uppercase tracking-wider text-emerald-700"><?= htmlspecialchars($user['role'], ENT_QUOTES) ?></div>
                </div>
                <a href="logout.php" class="px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-medium">Sign Out</a>
            </div>
        </div>
    </header>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 lg:px-8 py-6 space-y-6">

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-4">
                <div class="text-xs text-slate-500">Completed Analyses</div>
                <div class="text-2xl font-bold text-slate-900 mt-1"><?= $completedAnalyses ?> <span class="text-xs font-normal text-slate-500">/ <?= $totalAnalyses ?> total</span></div>
            </div>
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-4">
                <div class="text-xs text-slate-500">Paper Portfolio Cash</div>
                <div class="text-2xl font-bold text-emerald-700 mt-1">$<?= number_format((float)$portfolio['cash_balance'], 2) ?></div>
            </div>
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-4">
                <div class="text-xs text-slate-500">Active AI &amp; Data API Keys</div>
                <div class="text-2xl font-bold text-sky-700 mt-1"><?= $activeApiKeys ?> <span class="text-xs font-normal text-slate-500">AES-256-GCM</span></div>
            </div>
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-4">
                <div class="text-xs text-slate-500">Hot Market Data Cache</div>
                <div class="text-2xl font-bold text-amber-700 mt-1"><?= $cachedItems ?> <span class="text-xs font-normal text-slate-500">active records</span></div>
            </div>
        </div>

        <?php if ($activeTab === 'dashboard'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-5 bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Launch Multi-Agent Analysis</h2>
                        <p class="text-xs text-slate-500">9+ Specialized AI Agents with Real Yahoo, SEC EDGAR, StockTwits &amp; Reddit Data</p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-500/20">Live Engine</span>
                </div>

                <form onsubmit="return startMultiAgentAnalysis(event)" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Ticker Symbol</label>
                            <div class="flex gap-1.5">
                                <input type="text" id="inputTicker" value="NVDA" required placeholder="NVDA, AAPL, BTC-USD"
                                       class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono uppercase text-slate-900 focus:border-emerald-500 focus:outline-none">
                                <button type="button" onclick="previewLiveQuote()" class="px-2.5 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs text-emerald-700 font-medium shrink-0">Quote</button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Analysis Date</label>
                            <input type="date" id="inputTradeDate" value="<?= gmdate('Y-m-d') ?>" max="<?= gmdate('Y-m-d') ?>" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1.5">1. Select Analyst Team Nodes</label>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <label class="flex items-center gap-2 p-2.5 rounded-lg bg-slate-50 border border-slate-200 cursor-pointer">
                                <input type="checkbox" name="analyst_nodes" value="market" checked class="accent-emerald-500">
                                <span>Technical / Market Analyst</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg bg-slate-50 border border-slate-200 cursor-pointer">
                                <input type="checkbox" name="analyst_nodes" value="social" checked class="accent-emerald-500">
                                <span>Sentiment Analyst</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg bg-slate-50 border border-slate-200 cursor-pointer">
                                <input type="checkbox" name="analyst_nodes" value="news" checked class="accent-emerald-500">
                                <span>News &amp; Macro Analyst</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg bg-slate-50 border border-slate-200 cursor-pointer">
                                <input type="checkbox" name="analyst_nodes" value="fundamentals" checked class="accent-emerald-500">
                                <span>Fundamentals (SEC EDGAR)</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Bull vs. Bear Debate Rounds</label>
                            <select id="inputDebateRounds" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900">
                                <option value="1">1 Round (Fast — Shared Hosting Optimal)</option>
                                <option value="2">2 Rounds (Standard Deep Debate)</option>
                                <option value="3">3 Rounds (Exhaustive Debate)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Risk Team Evaluation Rounds</label>
                            <select id="inputRiskRounds" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900">
                                <option value="1">1 Round (Aggressive + Conservative + Neutral)</option>
                                <option value="2">2 Rounds (6 Risk Rebuttals)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Quick-Think Provider &amp; Model</label>
                            <select id="inputQuickProvider" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 mb-1.5">
                                <?php foreach ($providersCatalog as $pSlug => $pMeta): if ($pMeta['category'] !== 'llm') continue; ?>
                                    <option value="<?= htmlspecialchars($pSlug, ENT_QUOTES) ?>" <?= ($settings['QuickThinkProvider'] ?? 'openrouter') === $pSlug ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pMeta['name'], ENT_QUOTES) ?> [<?= htmlspecialchars($pMeta['tier_badge'], ENT_QUOTES) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="inputQuickModel" list="modelSuggestionsList"
                                   value="<?= htmlspecialchars((string)($settings['QuickThinkModel'] ?? 'openrouter/free'), ENT_QUOTES) ?>"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-mono text-emerald-700">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Deep-Think Provider &amp; Model</label>
                            <select id="inputDeepProvider" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 mb-1.5">
                                <?php foreach ($providersCatalog as $pSlug => $pMeta): if ($pMeta['category'] !== 'llm') continue; ?>
                                    <option value="<?= htmlspecialchars($pSlug, ENT_QUOTES) ?>" <?= ($settings['DeepThinkProvider'] ?? 'openrouter') === $pSlug ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pMeta['name'], ENT_QUOTES) ?> [<?= htmlspecialchars($pMeta['tier_badge'], ENT_QUOTES) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="inputDeepModel" list="modelSuggestionsList"
                                   value="<?= htmlspecialchars((string)($settings['DeepThinkModel'] ?? 'openrouter/free'), ENT_QUOTES) ?>"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-mono text-sky-700">
                        </div>
                    </div>

                    <datalist id="modelSuggestionsList">
                        <?php foreach ($fullModelCatalog as $family => $models): ?>
                            <?php foreach ($models as $m): ?>
                                <option value="<?= htmlspecialchars($m['id'], ENT_QUOTES) ?>">[<?= $m['tier'] ?>] <?= htmlspecialchars($m['name'], ENT_QUOTES) ?> (<?= htmlspecialchars($family, ENT_QUOTES) ?>)</option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </datalist>

                    <button type="submit" id="btnStartAnalysis"
                            class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm shadow-lg shadow-emerald-600/20 transition cursor-pointer">
                        Run Full Multi-Agent Trading Analysis &rarr;
                    </button>
                </form>
            </div>

            <div class="lg:col-span-7 space-y-6">
                <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900" id="chartTitle">Live Yahoo Finance OHLCV &amp; Moving Averages</h3>
                            <p class="text-xs text-slate-500" id="chartSubtitle">Click "Quote" or launch an analysis to inspect verified price &amp; indicator levels.</p>
                        </div>
                        <div id="quoteIndicatorsBadge" class="flex flex-wrap gap-1.5 text-[11px] font-mono"></div>
                    </div>
                    <div class="h-56 w-full"><canvas id="marketPriceChart"></canvas></div>
                </div>

                <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">Multi-Agent Workflow Pipeline (Shared-Hosting Progressive Runner)</h3>
                            <p class="text-xs text-slate-500" id="runnerStatusText">Ready — Each agent node runs in an isolated AJAX step so shared hosting never times out.</p>
                        </div>
                        <span id="runnerProgressBadge" class="px-2.5 py-1 rounded-full text-xs font-mono bg-slate-100 text-slate-700">0%</span>
                    </div>
                    <div class="w-full h-2.5 bg-slate-50 rounded-full overflow-hidden mb-4 border border-slate-200">
                        <div id="runnerProgressBar" class="h-full bg-emerald-500 transition-all duration-300" style="width: 0%"></div>
                    </div>
                    <div id="runnerStepsContainer" class="space-y-2 max-h-80 overflow-y-auto pr-1 text-xs">
                        <div class="text-slate-500 text-center py-6">
                            Enter a ticker symbol (e.g., <code class="text-emerald-700">NVDA</code>, <code class="text-emerald-700">AAPL</code>, <code class="text-emerald-700">MSFT</code>, <code class="text-emerald-700">BTC-USD</code>) and click <strong>Run Full Multi-Agent Trading Analysis</strong>.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($activeTab === 'report'): ?>
        <div class="flex flex-wrap items-center justify-between gap-4 bg-white shadow-sm border border-slate-200 rounded-2xl p-5">
            <div class="flex items-center gap-3">
                <label class="text-xs text-slate-500">Select Analysis Run:</label>
                <select onchange="window.location.search='?tab=report&id='+this.value"
                        class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono">
                    <?php foreach ($recentAnalyses as $ra): ?>
                        <option value="<?= (int)$ra['id'] ?>" <?= ((int)$ra['id'] === (int)($viewAnalysis['id'] ?? 0)) ? 'selected' : '' ?>>
                            #<?= (int)$ra['id'] ?> — <?= htmlspecialchars($ra['ticker'], ENT_QUOTES) ?> (<?= htmlspecialchars($ra['trade_date'], ENT_QUOTES) ?>) [<?= htmlspecialchars($ra['final_rating'] ?: $ra['status'], ENT_QUOTES) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($viewAnalysis): ?>
                <div class="flex flex-wrap items-center gap-2">
                    <?php if ($viewAnalysis['status'] !== 'completed'): ?>
                        <button onclick="resumeAnalysisRun(<?= (int)$viewAnalysis['id'] ?>)"
                                class="px-3.5 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold">▶ Resume / Continue Queued Steps</button>
                    <?php endif; ?>
                    <a href="?export_report=1&id=<?= (int)$viewAnalysis['id'] ?>&format=html&download=1"
                       class="px-3.5 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-medium">⬇ Download HTML Report</a>
                    <a href="?export_report=1&id=<?= (int)$viewAnalysis['id'] ?>&format=pdf" target="_blank"
                       class="px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">🖨 Print / Save as PDF</a>
                    <a href="?export_report=1&id=<?= (int)$viewAnalysis['id'] ?>&format=json&download=1"
                       class="px-3.5 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-mono">{ } State JSON</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$viewAnalysis): ?>
            <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-10 text-center text-slate-500 text-sm">
                No analysis reports found yet. Launch your first analysis from the <strong>Run Analysis</strong> tab.
            </div>
        <?php else: ?>
            <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                    <div>
                        <div class="flex items-center gap-3">
                            <h2 class="text-2xl font-bold text-slate-900 font-mono"><?= htmlspecialchars($viewAnalysis['ticker'], ENT_QUOTES) ?></h2>
                            <span class="text-sm text-slate-500"><?= htmlspecialchars((string)($viewAnalysis['company_name'] ?: ''), ENT_QUOTES) ?></span>
                            <span class="px-3 py-1 rounded-full text-xs font-bold border <?= ratingBadgeClass($viewAnalysis['final_rating']) ?>">
                                Final PM Rating: <?= htmlspecialchars((string)($viewAnalysis['final_rating'] ?: strtoupper((string)$viewAnalysis['status'])), ENT_QUOTES) ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1.5"><?= htmlspecialchars((string)($viewAnalysis['instrument_context'] ?: ''), ENT_QUOTES) ?></p>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-right font-mono text-xs">
                        <div class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <div class="text-[10px] text-slate-500">Trader Action</div>
                            <div class="font-bold text-slate-900"><?= htmlspecialchars((string)($viewAnalysis['trader_action'] ?: 'Pending'), ENT_QUOTES) ?></div>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <div class="text-[10px] text-slate-500">Entry Target</div>
                            <div class="font-bold text-emerald-700"><?= $viewAnalysis['entry_price'] !== null ? htmlspecialchars((string)$viewAnalysis['entry_price'], ENT_QUOTES) : '—' ?></div>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <div class="text-[10px] text-slate-500">Stop-Loss</div>
                            <div class="font-bold text-rose-700"><?= $viewAnalysis['stop_loss'] !== null ? htmlspecialchars((string)$viewAnalysis['stop_loss'], ENT_QUOTES) : '—' ?></div>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <div class="text-[10px] text-slate-500">PM Price Target</div>
                            <div class="font-bold text-sky-700"><?= $viewAnalysis['price_target'] !== null ? htmlspecialchars((string)$viewAnalysis['price_target'], ENT_QUOTES) : '—' ?></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($viewAnalysis['executive_summary'])): ?>
                    <div class="mt-4 bg-emerald-50/70 border border-emerald-500/20 rounded-xl p-4">
                        <div class="text-xs font-bold uppercase tracking-wider text-emerald-700 mb-1">Portfolio Manager Executive Summary</div>
                        <p class="text-sm text-slate-800 leading-relaxed"><?= nl2br(htmlspecialchars((string)$viewAnalysis['executive_summary'], ENT_QUOTES)) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="space-y-4">
                <?php foreach ($viewReports as $rep): ?>
                    <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3 mb-4">
                            <div class="flex items-center gap-2.5">
                                <span class="px-2.5 py-0.5 rounded text-[11px] font-mono uppercase bg-emerald-50 text-emerald-700 border border-emerald-500/20">
                                    <?= htmlspecialchars((string)$rep['stage_group'], ENT_QUOTES) ?>
                                </span>
                                <h3 class="text-base font-bold text-slate-900"><?= htmlspecialchars((string)$rep['agent_role'], ENT_QUOTES) ?></h3>
                            </div>
                            <div class="flex items-center gap-2 text-[11px] font-mono text-slate-500">
                                <span class="bg-slate-50 border border-slate-200 px-2 py-0.5 rounded"><?= htmlspecialchars((string)$rep['provider_used'] . ' / ' . (string)$rep['model_used'], ENT_QUOTES) ?></span>
                                <span><?= (int)$rep['tokens_used'] ?> tok</span>
                                <span><?= number_format(((int)$rep['latency_ms']) / 1000, 1) ?>s</span>
                            </div>
                        </div>
                        <div class="agent-prose text-sm text-slate-800">
                            <?= ReportExporter::markdownToHtml((string)$rep['report_markdown']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'history'): ?>
        <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Historical Analyses &amp; Side-by-Side Comparison</h2>
                    <p class="text-xs text-slate-500">Select any 2–4 analyses below and click "Compare Selected" to evaluate multi-agent consensus side by side.</p>
                </div>
                <button onclick="compareSelectedAnalyses()" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">⚖ Compare Selected Analyses</button>
            </div>
            <div id="comparisonGridContainer" class="hidden mb-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-500">
                            <th class="py-3 px-2"><input type="checkbox" onclick="toggleAllCompare(this)"></th>
                            <th class="py-3 px-2">ID</th>
                            <th class="py-3 px-2">Ticker</th>
                            <th class="py-3 px-2">Date</th>
                            <th class="py-3 px-2">Status</th>
                            <th class="py-3 px-2">Research Plan</th>
                            <th class="py-3 px-2">Trader Action</th>
                            <th class="py-3 px-2">Final PM Rating</th>
                            <th class="py-3 px-2">Entry / Stop / Target</th>
                            <th class="py-3 px-2">Sentiment</th>
                            <th class="py-3 px-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($recentAnalyses as $row): ?>
                            <tr class="hover:bg-slate-100/70">
                                <td class="py-3 px-2">
                                    <input type="checkbox" class="compare-check accent-emerald-500" value="<?= (int)$row['id'] ?>"
                                           data-summary='<?= htmlspecialchars(json_encode([
                                               'id' => $row['id'],
                                               'ticker' => $row['ticker'],
                                               'date' => $row['trade_date'],
                                               'rating' => $row['final_rating'] ?: 'Pending',
                                               'research' => $row['research_recommendation'] ?: '—',
                                               'trader' => $row['trader_action'] ?: '—',
                                               'entry' => $row['entry_price'] ?? '—',
                                               'stop' => $row['stop_loss'] ?? '—',
                                               'target' => $row['price_target'] ?? '—',
                                               'sentiment' => ($row['sentiment_band'] ?? '—') . ($row['sentiment_score'] !== null ? " ({$row['sentiment_score']}/10)" : ''),
                                               'summary' => $row['executive_summary'] ?: 'No summary generated yet.',
                                           ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'>
                                </td>
                                <td class="py-3 px-2 font-mono text-slate-500">#<?= (int)$row['id'] ?></td>
                                <td class="py-3 px-2 font-mono font-bold text-slate-900"><?= htmlspecialchars($row['ticker'], ENT_QUOTES) ?></td>
                                <td class="py-3 px-2 font-mono"><?= htmlspecialchars($row['trade_date'], ENT_QUOTES) ?></td>
                                <td class="py-3 px-2"><?= htmlspecialchars($row['status'], ENT_QUOTES) ?> (<?= (int)$row['progress_pct'] ?>%)</td>
                                <td class="py-3 px-2"><?= htmlspecialchars((string)($row['research_recommendation'] ?: '—'), ENT_QUOTES) ?></td>
                                <td class="py-3 px-2 font-semibold"><?= htmlspecialchars((string)($row['trader_action'] ?: '—'), ENT_QUOTES) ?></td>
                                <td class="py-3 px-2">
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold border <?= ratingBadgeClass($row['final_rating']) ?>">
                                        <?= htmlspecialchars((string)($row['final_rating'] ?: '—'), ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-2 font-mono text-[11px]">E: <?= $row['entry_price'] ?? '—' ?> | S: <?= $row['stop_loss'] ?? '—' ?> | T: <?= $row['price_target'] ?? '—' ?></td>
                                <td class="py-3 px-2"><?= htmlspecialchars((string)($row['sentiment_band'] ?: '—'), ENT_QUOTES) ?></td>
                                <td class="py-3 px-2 text-right space-x-1">
                                    <a href="?tab=report&id=<?= (int)$row['id'] ?>" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-emerald-700 font-medium">View</a>
                                    <a href="?export_report=1&id=<?= (int)$row['id'] ?>&format=html&download=1" class="px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700">HTML</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($activeTab === 'portfolio'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-4 bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-4">
                <h2 class="text-base font-bold text-slate-900">Open Paper / Manual Position</h2>
                <form onsubmit="return addPortfolioPosition(event)" class="space-y-3 text-xs">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block text-slate-700 mb-1">Ticker</label><input type="text" id="posTicker" required placeholder="NVDA" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono uppercase text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Side</label><select id="posSide" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"><option value="LONG">LONG (Buy)</option><option value="SHORT">SHORT (Sell)</option></select></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block text-slate-700 mb-1">Quantity</label><input type="number" step="0.0001" id="posQty" required placeholder="50" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Avg Entry Price ($)</label><input type="number" step="0.0001" id="posEntry" required placeholder="135.50" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block text-slate-700 mb-1">Stop-Loss ($)</label><input type="number" step="0.0001" id="posStop" placeholder="124.00" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Take-Profit ($)</label><input type="number" step="0.0001" id="posTarget" placeholder="165.00" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                    </div>
                    <div><label class="block text-slate-700 mb-1">Strategy Notes</label><input type="text" id="posNotes" placeholder="Per TradingAgents signal" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">+ Record Position in Portfolio</button>
                </form>
            </div>
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6">
                    <h3 class="text-base font-bold text-slate-900 mb-4">Active Open Positions (<?= count($openPositions) ?>)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead><tr class="border-b border-slate-200 text-slate-500"><th class="py-2 px-2">Ticker</th><th class="py-2 px-2">Side</th><th class="py-2 px-2">Qty</th><th class="py-2 px-2">Entry</th><th class="py-2 px-2">Stop / Target</th><th class="py-2 px-2 text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php foreach ($openPositions as $pos): ?>
                                    <tr>
                                        <td class="py-2.5 px-2 font-mono font-bold text-slate-900"><?= htmlspecialchars($pos['ticker'], ENT_QUOTES) ?></td>
                                        <td class="py-2.5 px-2"><?= htmlspecialchars($pos['side'], ENT_QUOTES) ?></td>
                                        <td class="py-2.5 px-2 font-mono"><?= (float)$pos['quantity'] ?></td>
                                        <td class="py-2.5 px-2 font-mono">$<?= number_format((float)$pos['avg_entry_price'], 2) ?></td>
                                        <td class="py-2.5 px-2 font-mono text-[11px]">SL: <?= $pos['stop_loss'] ?? '—' ?> | TP: <?= $pos['take_profit'] ?? '—' ?></td>
                                        <td class="py-2.5 px-2 text-right"><button onclick="closePortfolioPosition(<?= (int)$pos['id'] ?>)" class="px-2.5 py-1 rounded bg-rose-50 text-rose-700 border border-rose-200 text-[11px]">Close @ Market</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($activeTab === 'watchlist'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-5 bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-4">
                <h2 class="text-base font-bold text-slate-900">Ticker Watchlist &amp; Alert Thresholds</h2>
                <form onsubmit="return addWatchlistItem(event)" class="grid grid-cols-2 gap-3 text-xs">
                    <div><label class="block text-slate-700 mb-1">Ticker</label><input type="text" id="wlTicker" required placeholder="AAPL" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono uppercase text-slate-900"></div>
                    <div><label class="block text-slate-700 mb-1">Notes</label><input type="text" id="wlNotes" placeholder="Earnings catalyst" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                    <div><label class="block text-slate-700 mb-1">Alert Above ($)</label><input type="number" step="0.01" id="wlAbove" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                    <div><label class="block text-slate-700 mb-1">Alert Below ($)</label><input type="number" step="0.01" id="wlBelow" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                    <div class="col-span-2"><button type="submit" class="w-full py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">+ Add Symbol to Watchlist</button></div>
                </form>
                <div class="divide-y divide-slate-200 text-xs pt-2">
                    <?php foreach ($watchlist as $w): ?>
                        <div class="py-3 flex items-center justify-between">
                            <div>
                                <div class="font-mono font-bold text-slate-900 text-sm"><?= htmlspecialchars($w['ticker'], ENT_QUOTES) ?> <span class="text-xs font-normal text-slate-500"><?= htmlspecialchars((string)$w['company_name'], ENT_QUOTES) ?></span></div>
                                <div class="text-slate-500 text-[11px]">Last Price: <?= $w['last_price'] !== null ? '$' . number_format((float)$w['last_price'], 2) : '—' ?> | Rating: <?= htmlspecialchars((string)($w['last_rating'] ?: 'None'), ENT_QUOTES) ?></div>
                            </div>
                            <button onclick="deleteWatchlistItem(<?= (int)$w['id'] ?>)" class="px-2 py-1 rounded bg-rose-50 text-rose-700 border border-rose-200">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="lg:col-span-7 bg-white shadow-sm border border-slate-200 rounded-2xl p-6">
                <h2 class="text-base font-bold text-slate-900 mb-1">Trading Memory Log &amp; Post-Trade AI Reflections</h2>
                <p class="text-xs text-slate-500 mb-4">Tracks every Portfolio Manager decision, settles raw return &amp; Alpha vs. Benchmark after the holding window, and feeds lessons into future runs.</p>
                <div class="space-y-3 max-h-[520px] overflow-y-auto pr-1 text-xs">
                    <?php foreach ($memories as $mem): ?>
                        <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1.5">
                            <div class="flex items-center justify-between">
                                <span class="font-mono font-bold text-slate-900"><?= htmlspecialchars($mem['ticker'], ENT_QUOTES) ?> &bull; <?= htmlspecialchars($mem['trade_date'], ENT_QUOTES) ?> [<?= htmlspecialchars($mem['rating'], ENT_QUOTES) ?>]</span>
                                <span class="text-[11px] font-mono text-emerald-700"><?= strtoupper(htmlspecialchars($mem['outcome_status'], ENT_QUOTES)) ?></span>
                            </div>
                            <p class="text-slate-700"><?= htmlspecialchars((string)$mem['decision_summary'], ENT_QUOTES) ?></p>
                            <?php if (!empty($mem['reflection_lesson'])): ?>
                                <div class="p-2.5 rounded-lg bg-emerald-50 border border-emerald-500/20 text-emerald-800 text-[11px]">
                                    <strong>Post-Trade Reflection Lesson:</strong> <?= htmlspecialchars((string)$mem['reflection_lesson'], ENT_QUOTES) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php elseif ($activeTab === 'apikeys' && $isAdmin): ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-5 bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Add AI or Market Data API Key</h2>
                        <p class="text-xs text-slate-500">Encrypted at rest with AES-256-GCM. Supports multi-key rotation &amp; automatic HTTP 429 failover.</p>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-emerald-50 text-emerald-700 border border-emerald-500/20">AES-256-GCM</span>
                </div>
                <form onsubmit="return addVaultApiKey(event)" class="space-y-3 text-xs">
                    <div>
                        <label class="block text-slate-700 mb-1">Select Provider (44 Global AI &amp; Market Data Providers)</label>
                        <select id="vaultProviderSlug" onchange="updateProviderGetKeyLink()" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900">
                            <?php foreach ($providersCatalog as $slug => $p): ?>
                                <option value="<?= htmlspecialchars($slug, ENT_QUOTES) ?>" data-url="<?= htmlspecialchars($p['get_key_url'], ENT_QUOTES) ?>" data-notes="<?= htmlspecialchars($p['notes'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($p['name'], ENT_QUOTES) ?> [<?= htmlspecialchars($p['tier_badge'], ENT_QUOTES) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="flex items-center justify-between mt-1.5">
                            <span id="providerNotesHint" class="text-[11px] text-slate-500">Supports automatic Free Model Rotation across all 15 free models.</span>
                            <a id="providerGetKeyBtn" href="https://openrouter.ai/keys" target="_blank" rel="noopener"
                               class="inline-flex items-center px-2.5 py-1 rounded bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-700 font-semibold text-[11px] shrink-0 ml-2">🔑 Get API Key &rarr;</a>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block text-slate-700 mb-1">Key Label</label><input type="text" id="vaultKeyLabel" required placeholder="Primary OpenRouter Key" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                        <div>
                            <label class="block text-slate-700 mb-1">Tier Type &amp; Priority</label>
                            <div class="flex gap-1.5">
                                <select id="vaultTierType" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-slate-900"><option value="free">FREE Tier</option><option value="paid">PAID Tier</option><option value="trial">FREE Trial</option></select>
                                <input type="number" id="vaultPriority" value="10" min="1" max="999" class="w-16 bg-slate-50 border border-slate-200 rounded-lg px-2 py-2 text-slate-900 font-mono">
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-slate-700">Secret API Key</label>
                            <button type="button" onclick="generateRandomInternalKey()" class="text-[11px] text-sky-700 hover:underline">⚡ Generate Random Key</button>
                        </div>
                        <input type="password" id="vaultSecretKey" required placeholder="sk-or-v1-..." class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-slate-900">
                    </div>
                    <div><label class="block text-slate-700 mb-1">Custom Endpoint Base URL (Optional)</label><input type="url" id="vaultBaseUrl" placeholder="Leave blank for provider default" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-slate-900"></div>
                    <label class="flex items-center gap-2 text-slate-700 cursor-pointer"><input type="checkbox" id="vaultVerifyNow" checked class="accent-emerald-500"><span>Immediately test &amp; validate key upon saving</span></label>
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">🔒 Encrypt, Validate &amp; Save API Key</button>
                </form>
            </div>

            <div class="lg:col-span-7 bg-white shadow-sm border border-slate-200 rounded-2xl p-6">
                <h2 class="text-base font-bold text-slate-900 mb-1">Encrypted API Key Vault (<?= count($apiKeysList) ?> Keys)</h2>
                <p class="text-xs text-slate-500 mb-4">Keys are masked and never exposed in browser source or logs.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead><tr class="border-b border-slate-200 text-slate-500"><th class="py-2 px-2">Provider &amp; Label</th><th class="py-2 px-2">Masked Key</th><th class="py-2 px-2">Tier</th><th class="py-2 px-2">Status</th><th class="py-2 px-2">Usage</th><th class="py-2 px-2 text-right">Manage</th></tr></thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php foreach ($apiKeysList as $kr): ?>
                                <tr>
                                    <td class="py-2.5 px-2"><div class="font-bold text-slate-900"><?= htmlspecialchars($kr['key_label'], ENT_QUOTES) ?></div><div class="font-mono text-[10px] text-slate-500"><?= htmlspecialchars($kr['provider_slug'], ENT_QUOTES) ?></div></td>
                                    <td class="py-2.5 px-2 font-mono text-slate-700"><?= htmlspecialchars($kr['key_hint'], ENT_QUOTES) ?></td>
                                    <td class="py-2.5 px-2">
                                        <?php if ($kr['tier_type'] === 'free' || $kr['tier_type'] === 'trial'): ?>
                                            <span class="px-2 py-0.5 rounded font-bold text-[10px] bg-emerald-500/20 text-emerald-700 border border-emerald-500/30">FREE</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded font-bold text-[10px] bg-sky-500/20 text-sky-700 border border-sky-500/30">PAID</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-2"><?= htmlspecialchars($kr['verification_status'], ENT_QUOTES) ?></td>
                                    <td class="py-2.5 px-2 font-mono text-[11px]"><?= (int)$kr['total_requests'] ?> req / <?= number_format((int)$kr['total_tokens']) ?> tok</td>
                                    <td class="py-2.5 px-2 text-right space-x-1 whitespace-nowrap">
                                        <button onclick="verifyVaultKey(<?= (int)$kr['id'] ?>)" class="px-2 py-1 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">Verify</button>
                                        <button onclick="toggleVaultKey(<?= (int)$kr['id'] ?>, <?= (int)$kr['is_enabled'] === 1 ? 0 : 1 ?>)" class="px-2 py-1 rounded bg-slate-100 text-slate-800"><?= (int)$kr['is_enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
                                        <button onclick="resetVaultKey(<?= (int)$kr['id'] ?>)" class="px-2 py-1 rounded bg-slate-100 text-amber-800">Reset</button>
                                        <button onclick="deleteVaultKey(<?= (int)$kr['id'] ?>)" class="px-2 py-1 rounded bg-rose-50 text-rose-700 border border-rose-200">×</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Worldwide AI Providers &amp; OpenRouter Model Directory (FREE &amp; PAID Badges)</h2>
                    <p class="text-xs text-slate-500">Every supported provider includes a direct "Get API Key" link and green FREE / blue PAID badges.</p>
                </div>
                <input type="text" oninput="filterModelCards(this.value)" placeholder="Filter providers &amp; models..." class="w-full sm:w-72 bg-slate-50 border border-slate-200 rounded-lg px-3.5 py-2 text-xs text-slate-900">
            </div>

            <div>
                <h3 class="text-sm font-bold text-emerald-700 mb-3">OpenRouter 15 Free Models (Automatic Rate-Limit Rotation Pool)</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($freeOrModels as $fm): ?>
                        <div class="model-card p-3.5 rounded-xl bg-slate-50 border border-emerald-500/25 flex items-center justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded font-bold text-[10px] bg-emerald-500/20 text-emerald-700 border border-emerald-500/30">FREE</span>
                                    <span class="text-xs font-bold text-slate-900"><?= htmlspecialchars($fm['name'], ENT_QUOTES) ?></span>
                                </div>
                                <div class="text-[11px] font-mono text-slate-500 mt-1"><?= htmlspecialchars($fm['id'], ENT_QUOTES) ?></div>
                            </div>
                            <a href="https://openrouter.ai/keys" target="_blank" rel="noopener" class="px-2.5 py-1 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-medium shrink-0">Get API Key &rarr;</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-900 mb-3">All 44 AI Company API Websites &amp; Market Data Providers</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($providersCatalog as $slug => $prov): ?>
                        <div class="model-card p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-col justify-between gap-3">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <span class="font-bold text-xs text-slate-900"><?= htmlspecialchars($prov['name'], ENT_QUOTES) ?></span>
                                    <?php if ($prov['has_free_tier']): ?>
                                        <span class="px-2 py-0.5 rounded font-bold text-[10px] bg-emerald-500/20 text-emerald-700 border border-emerald-500/30 shrink-0">FREE</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded font-bold text-[10px] bg-sky-500/20 text-sky-700 border border-sky-500/30 shrink-0">PAID</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[11px] text-slate-500"><?= htmlspecialchars($prov['notes'], ENT_QUOTES) ?></p>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200 text-[11px]">
                                <span class="font-mono text-slate-500 truncate max-w-[160px]"><?= htmlspecialchars($prov['website'], ENT_QUOTES) ?></span>
                                <a href="<?= htmlspecialchars($prov['get_key_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-emerald-600 text-slate-700 hover:text-white font-semibold">Get API Key &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php elseif ($activeTab === 'cron' && $isAdmin): ?>
        <div class="bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Cron Jobs &amp; Shared Hosting Maintenance Center</h2>
                    <p class="text-xs text-slate-500">Automated background queue processing, market cache cleanup, trading memory settlement, log rotation, and InnoDB optimization.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="runCronJobNow('all')" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">▶ Run All Maintenance Tasks Now</button>
                    <button onclick="flushAllMarketCache()" class="px-3.5 py-2 rounded-xl bg-amber-500/15 text-amber-800 border border-amber-500/30 text-xs font-medium">🧹 Flush Market Cache</button>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($cronJobs as $jobKey => $jobMeta): ?>
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($jobMeta['name'], ENT_QUOTES) ?></span>
                            <button onclick="runCronJobNow('<?= htmlspecialchars($jobKey, ENT_QUOTES) ?>')" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-emerald-600 text-emerald-700 hover:text-white font-medium">Run Now</button>
                        </div>
                        <div class="text-emerald-700 font-mono text-[11px]">Schedule: <?= htmlspecialchars($jobMeta['schedule'], ENT_QUOTES) ?></div>
                        <p class="text-slate-500 text-[11px]"><?= htmlspecialchars($jobMeta['purpose'], ENT_QUOTES) ?></p>
                        <div class="bg-white shadow-sm border border-slate-200 rounded p-2 font-mono text-[11px] text-slate-800 break-all select-all"><?= htmlspecialchars($jobMeta['cli_cmd'], ENT_QUOTES) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'settings' && $isAdmin): ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-7 bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <h2 class="text-base font-bold text-slate-900">Platform, Workflow &amp; OpenRouter Fallback Settings</h2>
                    <a href="api.php?action=settings_export" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs text-emerald-700 font-medium">⬇ Export Settings JSON</a>
                </div>
                <form onsubmit="return saveSystemSettings(event)" class="space-y-4 text-xs">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><label class="block text-slate-700 mb-1">Platform Title</label><input type="text" name="site_name" value="<?= htmlspecialchars((string)($settings['site_name'] ?? ''), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Default Report Language</label><input type="text" name="output_language" value="<?= htmlspecialchars((string)($settings['output_language'] ?? 'English'), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Default Quick-Think Provider</label><input type="text" name="QuickThinkProvider" value="<?= htmlspecialchars((string)($settings['QuickThinkProvider'] ?? 'openrouter'), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Default Quick-Think Model</label><input type="text" name="QuickThinkModel" value="<?= htmlspecialchars((string)($settings['QuickThinkModel'] ?? 'openrouter/free'), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-emerald-700"></div>
                        <div><label class="block text-slate-700 mb-1">Default Deep-Think Provider</label><input type="text" name="DeepThinkProvider" value="<?= htmlspecialchars((string)($settings['DeepThinkProvider'] ?? 'openrouter'), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-slate-900"></div>
                        <div><label class="block text-slate-700 mb-1">Default Deep-Think Model</label><input type="text" name="DeepThinkModel" value="<?= htmlspecialchars((string)($settings['DeepThinkModel'] ?? 'openrouter/free'), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-sky-700"></div>
                        <div class="sm:col-span-2"><label class="block text-slate-700 mb-1">OpenRouter Free Models Automatic Fallback Chain</label><input type="text" name="openrouter_free_fallback_chain" value="<?= htmlspecialchars((string)($settings['openrouter_free_fallback_chain'] ?? ''), ENT_QUOTES) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-slate-900"></div>
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">Save Platform Configuration</button>
                </form>
            </div>
            <div class="lg:col-span-5 bg-white shadow-sm border border-slate-200 rounded-2xl p-6 space-y-4">
                <h2 class="text-base font-bold text-slate-900">User Management (RBAC)</h2>
                <form onsubmit="return createPlatformUser(event)" class="grid grid-cols-2 gap-2.5 text-xs">
                    <input type="text" id="newUsername" required placeholder="Username" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900">
                    <input type="email" id="newEmail" required placeholder="Email" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900">
                    <input type="password" id="newPassword" required minlength="8" placeholder="Password (8+ chars)" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900">
                    <select id="newRole" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-900"><option value="analyst">Analyst</option><option value="admin">Administrator</option><option value="viewer">Viewer</option></select>
                    <button type="submit" class="col-span-2 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">+ Create User Account</button>
                </form>
                <div class="divide-y divide-slate-200 text-xs">
                    <?php foreach ($allUsers as $u): ?>
                        <div class="py-2.5 flex items-center justify-between">
                            <div><span class="font-bold text-slate-900"><?= htmlspecialchars($u['username'], ENT_QUOTES) ?></span> <span class="text-slate-500">(<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>)</span></div>
                            <?php if ((int)$u['id'] !== $userId): ?>
                                <button onclick="togglePlatformUser(<?= (int)$u['id'] ?>, <?= (int)$u['is_active'] === 1 ? 0 : 1 ?>)" class="px-2 py-1 rounded bg-slate-100 text-[11px]"><?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <div id="toastBox" class="fixed bottom-5 right-5 z-50 hidden max-w-sm rounded-xl bg-white shadow-sm border border-emerald-500/40 px-4 py-3 text-xs text-slate-900 shadow-2xl"></div>

    <script>
        const CSRF_TOKEN = <?= json_encode($csrf) ?>;
        let priceChartInstance = null;

        function showToast(msg, isError = false) {
            const box = document.getElementById('toastBox');
            box.textContent = msg;
            box.className = 'fixed bottom-5 right-5 z-50 max-w-sm rounded-xl px-4 py-3 text-xs font-medium shadow-2xl border ' +
                (isError ? 'bg-rose-50 border-rose-500/50 text-rose-800' : 'bg-white shadow-sm border-emerald-500/50 text-emerald-700');
            box.classList.remove('hidden');
            setTimeout(() => box.classList.add('hidden'), 4500);
        }

        async function apiCall(action, payload = {}) {
            const res = await fetch('api.php?action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ ...payload, action, csrf_token: CSRF_TOKEN })
            });
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (err) {
                return { ok: false, transient: true, error: 'Server busy or timed out (HTTP ' + res.status + '). Retrying step...' };
            }
        }

        async function previewLiveQuote() {
            const tickerEl = document.getElementById('inputTicker');
            const dateEl = document.getElementById('inputTradeDate');
            if (!tickerEl) return;
            const ticker = tickerEl.value.trim().toUpperCase();
            const tradeDate = dateEl ? dateEl.value : '';
            const res = await apiCall('live_quote', { ticker, trade_date: tradeDate });
            if (!res.ok) return;
            const lat = res.latest || {};
            const bars = res.bars || [];
            document.getElementById('chartTitle').textContent = `${res.ticker} — Verified Close: ${lat.close ?? 'N/A'} (${lat.day_change_pct ?? 0}%)`;
            document.getElementById('chartSubtitle').textContent = `52W Range: ${lat.low_52w ?? '-'} – ${lat.high_52w ?? '-'} | 20D Support: ${lat.support_20d ?? '-'} | Resistance: ${lat.resistance_20d ?? '-'}`;
            document.getElementById('quoteIndicatorsBadge').innerHTML = `
                <span class="px-2 py-0.5 rounded bg-slate-50 border border-slate-200 text-emerald-700">RSI(14): ${lat.rsi ?? '-'}</span>
                <span class="px-2 py-0.5 rounded bg-slate-50 border border-slate-200 text-sky-700">50 SMA: ${lat.close_50_sma ?? '-'}</span>
                <span class="px-2 py-0.5 rounded bg-slate-50 border border-slate-200 text-amber-700">ATR(14): ${lat.atr ?? '-'}</span>
            `;
            const ctx = document.getElementById('marketPriceChart');
            if (ctx && bars.length > 0) {
                if (priceChartInstance) priceChartInstance.destroy();
                priceChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: bars.map(b => b.date),
                        datasets: [{ label: res.ticker, data: bars.map(b => b.close), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.12)', fill: true, tension: 0.25, pointRadius: 1.5 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });
            }
        }

        async function startMultiAgentAnalysis(e) {
            e.preventDefault();
            const btn = document.getElementById('btnStartAnalysis');
            const ticker = document.getElementById('inputTicker').value.trim();
            const tradeDate = document.getElementById('inputTradeDate').value;
            const analysts = Array.from(document.querySelectorAll('input[name="analyst_nodes"]:checked')).map(el => el.value);
            btn.disabled = true;
            btn.textContent = 'Initializing Multi-Agent Graph & Queue...';
            previewLiveQuote();

            const initRes = await apiCall('start_analysis', {
                ticker, trade_date: tradeDate, analysts,
                max_debate_rounds: document.getElementById('inputDebateRounds').value,
                max_risk_rounds: document.getElementById('inputRiskRounds').value,
                quick_provider: document.getElementById('inputQuickProvider').value,
                quick_model: document.getElementById('inputQuickModel').value,
                deep_provider: document.getElementById('inputDeepProvider').value,
                deep_model: document.getElementById('inputDeepModel').value
            });

            if (!initRes.ok) {
                btn.disabled = false;
                btn.textContent = 'Run Full Multi-Agent Trading Analysis →';
                showToast(initRes.error || 'Failed to initialize analysis', true);
                return false;
            }
            renderQueueSteps(initRes.queue || []);
            await runProgressiveLoop(initRes.data.analysis_id);
            return false;
        }

        function renderQueueSteps(queue) {
            const box = document.getElementById('runnerStepsContainer');
            if (!box) return;
            box.innerHTML = queue.map(item => {
                const icon = item.status === 'completed' ? '✅' : (item.status === 'processing' ? '⏳' : (item.status === 'failed' ? '❌' : '⚪'));
                return `<div class="p-2.5 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-between">
                    <span>${icon} <strong>Step ${item.step_order}:</strong> ${item.step_label}</span>
                    <span class="font-mono text-[10px] uppercase">${item.status}</span>
                </div>`;
            }).join('');
        }

        async function runProgressiveLoop(analysisId) {
            let transientRetries = 0;
            while (true) {
                let stepRes;
                try {
                    stepRes = await apiCall('step_analysis', { analysis_id: analysisId });
                } catch (netErr) {
                    stepRes = { ok: false, transient: true, error: 'Network hiccup, retrying...' };
                }
                if (stepRes.queue) renderQueueSteps(stepRes.queue);
                const pct = stepRes.progress_pct ?? 0;
                if (pct > 0) {
                    document.getElementById('runnerProgressBar').style.width = pct + '%';
                    document.getElementById('runnerProgressBadge').textContent = pct + '%';
                }
                if (!stepRes.ok) {
                    if (stepRes.transient && transientRetries < 4) {
                        transientRetries++;
                        document.getElementById('runnerStatusText').textContent = `Reconnecting for next agent step (attempt ${transientRetries}/4)...`;
                        await new Promise(r => setTimeout(r, 1500));
                        continue;
                    }
                    showToast(stepRes.error || 'Step failed', true);
                    const btn = document.getElementById('btnStartAnalysis');
                    if (btn) { btn.disabled = false; btn.textContent = 'Run Full Multi-Agent Trading Analysis →'; }
                    break;
                }
                transientRetries = 0;
                document.getElementById('runnerStatusText').textContent = `Completed: ${stepRes.step_label || stepRes.completed_step} (${pct}%)`;
                if (stepRes.status === 'completed' || pct >= 100) {
                    const a = stepRes.analysis || {};
                    const box = document.getElementById('runnerStepsContainer');
                    if (box) {
                        box.insertAdjacentHTML('afterbegin', `
                            <div class="p-4 mb-3 rounded-xl bg-emerald-50 border border-emerald-300 text-slate-900 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-sm text-emerald-800">✅ Analysis #${analysisId} (${a.ticker || ''}) Completed!</span>
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-600 text-white">Rating: ${a.final_rating || 'Done'}</span>
                                </div>
                                <p class="text-xs text-slate-700">${a.executive_summary || 'All 11 agent reports have been generated and stored in MySQL.'}</p>
                                <div class="flex flex-wrap gap-2 pt-1">
                                    <a href="?tab=report&id=${analysisId}" class="px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">📄 Open Full Agent Reports &rarr;</a>
                                    <a href="?export_report=1&id=${analysisId}&format=html&download=1" class="px-3 py-1.5 rounded-lg bg-white border border-slate-300 text-slate-700 text-xs font-medium">⬇ Download HTML</a>
                                    <a href="?export_report=1&id=${analysisId}&format=pdf" target="_blank" class="px-3 py-1.5 rounded-lg bg-white border border-slate-300 text-slate-700 text-xs font-medium">🖨 Print / PDF</a>
                                </div>
                            </div>
                        `);
                    }
                    showToast('Multi-Agent Analysis Completed! Opening Full Report...');
                    setTimeout(() => { window.location.search = '?tab=report&id=' + analysisId; }, 1200);
                    break;
                }
            }
        }

        async function resumeAnalysisRun(analysisId) {
            const stepRes = await apiCall('step_analysis', { analysis_id: analysisId });
            if (stepRes.ok) window.location.reload();
            else showToast(stepRes.error || 'Resume failed', true);
        }

        function toggleAllCompare(master) { document.querySelectorAll('.compare-check').forEach(cb => cb.checked = master.checked); }

        function compareSelectedAnalyses() {
            const checked = Array.from(document.querySelectorAll('.compare-check:checked'));
            if (checked.length < 2) { showToast('Select at least 2 analyses to compare.', true); return; }
            const grid = document.getElementById('comparisonGridContainer');
            grid.innerHTML = checked.map(cb => {
                const d = JSON.parse(cb.getAttribute('data-summary'));
                return `<div class="p-4 rounded-xl bg-slate-50 border border-emerald-500/30 space-y-2 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                        <span class="font-mono font-bold text-slate-900">#${d.id} ${d.ticker} (${d.date})</span>
                        <span class="px-2.5 py-0.5 rounded-full font-bold bg-emerald-100 text-emerald-800">${d.rating}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1 font-mono text-[11px]">
                        <div>Research: <strong>${d.research}</strong></div><div>Trader: <strong>${d.trader}</strong></div>
                        <div>Entry: <strong class="text-emerald-700">${d.entry}</strong></div><div>Stop: <strong class="text-rose-700">${d.stop}</strong></div>
                        <div>Target: <strong class="text-sky-700">${d.target}</strong></div><div>Sentiment: <strong>${d.sentiment}</strong></div>
                    </div>
                    <p class="text-slate-700 text-[11px] pt-1 border-t border-slate-200">${d.summary}</p>
                </div>`;
            }).join('');
            grid.classList.remove('hidden');
        }

        async function addPortfolioPosition(e) {
            e.preventDefault();
            const res = await apiCall('portfolio_add_position', {
                ticker: document.getElementById('posTicker').value, side: document.getElementById('posSide').value,
                quantity: document.getElementById('posQty').value, avg_entry_price: document.getElementById('posEntry').value,
                stop_loss: document.getElementById('posStop').value, take_profit: document.getElementById('posTarget').value,
                notes: document.getElementById('posNotes').value
            });
            if (res.ok) window.location.reload(); else showToast(res.error, true);
            return false;
        }

        async function closePortfolioPosition(posId) {
            const res = await apiCall('portfolio_close_position', { position_id: posId });
            if (res.ok) window.location.reload(); else showToast(res.error, true);
        }

        async function addWatchlistItem(e) {
            e.preventDefault();
            const res = await apiCall('watchlist_add', {
                ticker: document.getElementById('wlTicker').value, notes: document.getElementById('wlNotes').value,
                alert_above: document.getElementById('wlAbove').value, alert_below: document.getElementById('wlBelow').value
            });
            if (res.ok) window.location.reload(); else showToast(res.error, true);
            return false;
        }

        async function deleteWatchlistItem(id) {
            const res = await apiCall('watchlist_delete', { id });
            if (res.ok) window.location.reload();
        }

        function updateProviderGetKeyLink() {
            const sel = document.getElementById('vaultProviderSlug');
            const opt = sel.options[sel.selectedIndex];
            document.getElementById('providerGetKeyBtn').href = opt.getAttribute('data-url') || 'https://openrouter.ai/keys';
            document.getElementById('providerNotesHint').textContent = opt.getAttribute('data-notes') || '';
        }

        async function generateRandomInternalKey() {
            const res = await apiCall('api_key_generate');
            if (res.ok && res.generated_key) {
                const inp = document.getElementById('vaultSecretKey');
                inp.type = 'text'; inp.value = res.generated_key;
            }
        }

        async function addVaultApiKey(e) {
            e.preventDefault();
            const res = await apiCall('api_key_add', {
                provider_slug: document.getElementById('vaultProviderSlug').value,
                key_label: document.getElementById('vaultKeyLabel').value,
                tier_type: document.getElementById('vaultTierType').value,
                priority: document.getElementById('vaultPriority').value,
                api_key: document.getElementById('vaultSecretKey').value,
                base_url: document.getElementById('vaultBaseUrl').value,
                verify_now: document.getElementById('vaultVerifyNow').checked ? 1 : 0
            });
            if (res.ok) window.location.reload(); else showToast(res.error, true);
            return false;
        }

        async function verifyVaultKey(keyId) {
            showToast('Testing API Key #' + keyId + '...');
            const res = await apiCall('api_key_verify', { key_id: keyId });
            showToast(res.message || res.error, !res.ok);
            setTimeout(() => window.location.reload(), 1000);
        }

        async function toggleVaultKey(keyId, enable) { await apiCall('api_key_toggle', { key_id: keyId, enable }); window.location.reload(); }
        async function resetVaultKey(keyId) { await apiCall('api_key_reset', { key_id: keyId }); window.location.reload(); }
        async function deleteVaultKey(keyId) { if (confirm('Delete key #' + keyId + '?')) { await apiCall('api_key_delete', { key_id: keyId }); window.location.reload(); } }

        function filterModelCards(q) {
            const term = q.trim().toLowerCase();
            document.querySelectorAll('.model-card').forEach(card => {
                card.style.display = card.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        }

        async function runCronJobNow(job) {
            const res = await apiCall('cron_run', { job });
            if (res.ok) window.location.reload(); else showToast(res.error, true);
        }

        async function flushAllMarketCache() {
            const res = await apiCall('cache_flush');
            if (res.ok) window.location.reload();
        }

        async function saveSystemSettings(e) {
            e.preventDefault();
            const payload = Object.fromEntries(new FormData(e.target).entries());
            const res = await apiCall('settings_save', payload);
            if (res.ok) showToast('Settings saved!'); else showToast(res.error, true);
            return false;
        }

        async function createPlatformUser(e) {
            e.preventDefault();
            const res = await apiCall('user_create', {
                username: document.getElementById('newUsername').value,
                email: document.getElementById('newEmail').value,
                password: document.getElementById('newPassword').value,
                role: document.getElementById('newRole').value
            });
            if (res.ok) window.location.reload(); else showToast(res.error, true);
            return false;
        }

        async function togglePlatformUser(uid, active) { await apiCall('user_toggle', { user_id: uid, is_active: active }); window.location.reload(); }

        window.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('marketPriceChart')) previewLiveQuote();
        });
    </script>
</body>
</html>
