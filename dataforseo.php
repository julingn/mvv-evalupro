<?php
/**
 * dataforseo.php — DataForSEO API Proxy
 * Liest Credentials aus settings.json
 *
 * Actions (via ?action=...):
 *   test             GET  → Verbindungstest (appendix/user_data)
 *   serp             POST → SERP-Ergebnisse { keyword, limit }
 *   serp_top10       POST → SERP Top10      { keyword }
 *   backlinks        POST → Backlinks       { target }
 *   keywords         POST → Keywords        { url } oder { domain }
 *   page_intersection POST → Content Gap   { targets:[], competitor_count }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Credentials laden
$settingsFile = __DIR__ . '/settings.json';
$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$dfsLogin    = getenv('DATAFORSEO_LOGIN')    ?: ($settings['dataforseo_login']    ?? '');
$dfsPassword = getenv('DATAFORSEO_PASSWORD') ?: ($settings['dataforseo_password'] ?? '');

if (empty($dfsLogin) || empty($dfsPassword)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'DataForSEO-Credentials nicht konfiguriert. Bitte in den Einstellungen eintragen.']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Hilfsfunktion: DataForSEO-Request ──
function dfsRequest(string $endpoint, array $payload, string $login, string $password): array {
    $ch = curl_init('https://api.dataforseo.com/v3/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $login . ':' . $password,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) return ['_curl_error' => $curlErr, '_http' => 0];
    $decoded = json_decode($resp, true);
    if (!$decoded) return ['_parse_error' => 'Ungültige JSON-Antwort', '_raw' => substr($resp, 0, 200), '_http' => $httpCode];
    $decoded['_http'] = $httpCode;
    return $decoded;
}

function dfsGet(string $endpoint, string $login, string $password): array {
    $ch = curl_init('https://api.dataforseo.com/v3/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERPWD        => $login . ':' . $password,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) return ['_curl_error' => $curlErr];
    $decoded = json_decode($resp, true);
    return $decoded ?? ['_parse_error' => 'Ungültige JSON-Antwort'];
}

// ── action=test ──
if ($action === 'test') {
    $data = dfsGet('appendix/user_data', $dfsLogin, $dfsPassword);
    if (!empty($data['_curl_error'])) {
        echo json_encode(['success' => false, 'error' => $data['_curl_error']]); exit;
    }
    $statusCode = $data['status_code'] ?? 0;
    if ($statusCode === 20000) {
        $tasks  = $data['tasks'][0] ?? [];
        $result = ($tasks['result'][0] ?? []);
        $bal    = $result['money_data']['balance'] ?? null;
        echo json_encode(['success' => true, 'balance' => $bal]);
    } else {
        $msg = $data['tasks'][0]['status_message'] ?? ($data['status_message'] ?? 'Unbekannter Fehler');
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// Alle POST-Actions lesen den Body
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$keyword = trim($body['keyword'] ?? '');
$limit   = intval($body['limit'] ?? 10);
$url     = trim($body['url']     ?? '');

// ── action=serp / serp_top10 ──
if ($action === 'serp' || $action === 'serp_top10') {
    if (empty($keyword)) { echo json_encode(['error' => 'keyword fehlt']); exit; }
    $payload = [[
        'keyword'       => $keyword,
        'location_code' => 2276, // Deutschland
        'language_code' => 'de',
        'depth'         => max(10, $limit),
    ]];
    $data = dfsRequest('serp/google/organic/live/regular', $payload, $dfsLogin, $dfsPassword);
    if (!empty($data['_curl_error'])) { echo json_encode(['error' => $data['_curl_error']]); exit; }
    echo json_encode($data);
    exit;
}

// ── action=backlinks ──
if ($action === 'backlinks') {
    $target = trim($body['target'] ?? $url);
    if (empty($target)) { echo json_encode(['error' => 'target fehlt']); exit; }
    // Domain aus URL extrahieren
    $domain = preg_replace('#^https?://(www\.)?#i', '', $target);
    $domain = explode('/', $domain)[0];
    $payload = [[
        'target'        => $domain,
        'limit'         => 20,
        'include_subdomains' => true,
    ]];
    $data = dfsRequest('backlinks/summary/live', $payload, $dfsLogin, $dfsPassword);
    if (!empty($data['_curl_error'])) { echo json_encode(['error' => $data['_curl_error']]); exit; }
    // Code 40204 = Plan unterstützt keine Backlinks → graceful skip (wird im JS ignoriert)
    echo json_encode($data);
    exit;
}

// ── action=keywords ──
if ($action === 'keywords') {
    $target = $url ?: ($body['domain'] ?? '');
    if (empty($target)) { echo json_encode(['error' => 'url/domain fehlt']); exit; }
    $domain = preg_replace('#^https?://(www\.)?#i', '', $target);
    $domain = explode('/', $domain)[0];
    $payload = [[
        'target'        => $domain,
        'location_code' => 2276,
        'language_code' => 'de',
        'limit'         => 100,
    ]];
    $data = dfsRequest('dataforseo_labs/google/ranked_keywords/live', $payload, $dfsLogin, $dfsPassword);
    if (!empty($data['_curl_error'])) { echo json_encode(['error' => $data['_curl_error']]); exit; }
    echo json_encode($data);
    exit;
}

// ── action=page_intersection ──
if ($action === 'page_intersection') {
    $targets = $body['targets'] ?? [];
    if (empty($targets)) { echo json_encode(['error' => 'targets fehlt']); exit; }
    $payload = [[
        'targets'       => $targets,
        'location_code' => 2276,
        'language_code' => 'de',
        'limit'         => 100,
        'exclude_top_domains' => false,
    ]];
    $data = dfsRequest('dataforseo_labs/google/page_intersection/live', $payload, $dfsLogin, $dfsPassword);
    if (!empty($data['_curl_error'])) { echo json_encode(['error' => $data['_curl_error']]); exit; }
    echo json_encode($data);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte action: ' . $action]);
