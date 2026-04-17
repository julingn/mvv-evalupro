<?php
/**
 * pagespeed.php — Google PageSpeed Insights Proxy
 * POST { url, strategy: 'mobile'|'desktop' }
 * → { success, perf_score, lcp, fid, cls, fcp, ttfb, ... }
 * PSI ist öffentlich zugänglich (kein Key erforderlich, optional)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method Not Allowed']); exit; }

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$url      = trim($body['url'] ?? '');
$strategy = in_array($body['strategy'] ?? '', ['desktop', 'mobile']) ? $body['strategy'] : 'mobile';

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige URL']);
    exit;
}

// Optionaler PSI-API-Key aus settings.json
$settingsFile = __DIR__ . '/settings.json';
$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$psiKey = getenv('PAGESPEED_API_KEY') ?: ($settings['pagespeed_api_key'] ?? '');

$psiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
    . '?url=' . rawurlencode($url)
    . '&strategy=' . $strategy
    . '&category=PERFORMANCE'
    . ($psiKey ? '&key=' . rawurlencode($psiKey) : '');

$ch = curl_init($psiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { echo json_encode(['success' => false, 'error' => $curlErr]); exit; }

$data = json_decode($resp, true);
if ($httpCode !== 200 || isset($data['error'])) {
    $msg = $data['error']['message'] ?? 'HTTP ' . $httpCode;
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Relevante Metriken extrahieren
$cats    = $data['lighthouseResult']['categories'] ?? [];
$audits  = $data['lighthouseResult']['audits'] ?? [];

function metricVal(array $audits, string $key): ?string {
    return $audits[$key]['displayValue'] ?? null;
}
function metricScore(array $audits, string $key): ?float {
    $s = $audits[$key]['score'] ?? null;
    return $s !== null ? round((float)$s * 100) : null;
}

echo json_encode([
    'success'    => true,
    'strategy'   => $strategy,
    'perf_score' => isset($cats['performance']['score']) ? round($cats['performance']['score'] * 100) : null,
    'fcp'        => metricVal($audits, 'first-contentful-paint'),
    'lcp'        => metricVal($audits, 'largest-contentful-paint'),
    'tbt'        => metricVal($audits, 'total-blocking-time'),
    'cls'        => metricVal($audits, 'cumulative-layout-shift'),
    'si'         => metricVal($audits, 'speed-index'),
    'tti'        => metricVal($audits, 'interactive'),
    'fcp_score'  => metricScore($audits, 'first-contentful-paint'),
    'lcp_score'  => metricScore($audits, 'largest-contentful-paint'),
    'cls_score'  => metricScore($audits, 'cumulative-layout-shift'),
]);
