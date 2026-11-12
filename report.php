<?php
/**
 * TradingAgents PHP 8.4 — Standalone HTML / Print-to-PDF Report Endpoint (`report.php`)
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

use TradingAgents\AgentOrchestrator;
use TradingAgents\Bootstrap;
use TradingAgents\Database;
use TradingAgents\ReportExporter;
use TradingAgents\Security;

Bootstrap::init(true);
$user = Security::requireAuth();

$analysisId = (int)($_GET['id'] ?? 0);
$format     = strtolower(trim((string)($_GET['format'] ?? 'html')));
$download   = isset($_GET['download']) && $_GET['download'] === '1';

$analysis = Database::fetchOne("SELECT * FROM `analyses` WHERE `id` = :id LIMIT 1", [':id' => $analysisId]);
if (!$analysis) {
    http_response_code(404);
    die('Analysis report not found.');
}

// Viewers/Analysts can view their own or public reports; Admins can view all
if ($user['role'] !== 'admin' && (int)$analysis['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    die('Access denied to this analysis report.');
}

$reports = AgentOrchestrator::getReports($analysisId);

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if ($download) {
        $fname = sprintf('TradingAgents_%s_%s.json', $analysis['ticker'], $analysis['trade_date']);
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }
    echo json_encode([
        'analysis' => $analysis,
        'reports'  => $reports,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$autoPrint = ($format === 'pdf');
$html = ReportExporter::renderHtmlReport($analysis, $reports, $autoPrint);

header('Content-Type: text/html; charset=utf-8');
if ($download && !$autoPrint) {
    $fname = sprintf('TradingAgents_%s_%s_%s.html', $analysis['ticker'], $analysis['trade_date'], $analysis['final_rating'] ?: 'Report');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
}

echo $html;
