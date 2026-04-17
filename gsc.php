<?php
/**
 * gsc.php — Google Search Console Proxy (Service Account, JWT via OpenSSL)
 *
 * Actions:
 *   list   GET  → { success, domains: [...] }
 *   save   POST → Domain + Sa-JSON speichern
 *   delete POST → Domain löschen
 *   data   POST → GSC-Daten für URL abrufen { url, site_url, domain_id }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('DOMAINS_FILE', __DIR__ . '/gsc_domains.json');

function loadDomains(): array {
    if (!file_exists(DOMAINS_FILE)) return [];
    $data = json_decode(file_get_contents(DOMAINS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveDomains(array $data): void {
    file_put_contents(DOMAINS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$action = $_GET['action'] ?? '';

// ── action=list ──
if ($action === 'list') {
    $domains = loadDomains();
    // Service-Account-JSON aus Antwort entfernen (Security)
    $safe = array_map(function($d) {
        return ['id' => $d['id'], 'domain' => $d['domain'], 'site_url' => $d['site_url'], 'sa_email' => $d['sa_email'] ?? ''];
    }, $domains);
    echo json_encode(['success' => true, 'domains' => $safe]);
    exit;
}

// ── action=delete ──
if ($action === 'delete') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = $body['id'] ?? '';
    $domains = loadDomains();
    $domains = array_values(array_filter($domains, fn($d) => ($d['id'] ?? '') !== $id));
    saveDomains($domains);
    echo json_encode(['success' => true]);
    exit;
}

// ── action=save ──
if ($action === 'save') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $domain = trim($body['domain'] ?? '');
    $siteUrl = trim($body['site_url'] ?? '');
    $saJson  = $body['sa_json'] ?? null; // JSON-String oder Array

    if (empty($domain) || empty($siteUrl)) {
        http_response_code(400);
        echo json_encode(['error' => 'domain und site_url sind Pflichtfelder']);
        exit;
    }

    $domains = loadDomains();
    $id      = $body['id'] ?? uniqid('gsc_', true);

    $saData = null;
    if ($saJson) {
        $saData = is_array($saJson) ? $saJson : json_decode($saJson, true);
    }

    $entry = [
        'id'       => $id,
        'domain'   => $domain,
        'site_url' => $siteUrl,
        'sa_email' => $saData['client_email'] ?? '',
        'sa_json'  => $saData,
    ];

    // Vorhandene Domain aktualisieren
    $replaced = false;
    foreach ($domains as &$d) {
        if ($d['id'] === $id || $d['domain'] === $domain) { $d = $entry; $replaced = true; break; }
    }
    unset($d);
    if (!$replaced) $domains[] = $entry;
    saveDomains($domains);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

// ── action=data ──
if ($action === 'data') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $url    = trim($body['url'] ?? '');
    $domainId = $body['domain_id'] ?? null;

    if (empty($url)) { http_response_code(400); echo json_encode(['error' => 'url fehlt']); exit; }

    // Passende Domain finden
    $domains = loadDomains();
    $match   = null;

    if ($domainId) {
        foreach ($domains as $d) { if ($d['id'] === $domainId) { $match = $d; break; } }
    }
    if (!$match) {
        // Automatisch nach URL-Host suchen
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        foreach ($domains as $d) {
            $dHost = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', $d['site_url'] ?? ''));
            $dHost = rtrim($dHost, '/');
            if (str_contains($host, str_replace('sc-domain:', '', $dHost)) || str_contains($dHost, $host)) {
                $match = $d; break;
            }
        }
    }

    if (!$match || empty($match['sa_json'])) {
        echo json_encode(['success' => false, 'error' => 'Keine GSC-Domain konfiguriert für diese URL']);
        exit;
    }

    $saJson  = $match['sa_json'];
    $siteUrl = $match['site_url'];

    // JWT erstellen und Access-Token holen
    $accessToken = getGscAccessToken($saJson);
    if (is_string($accessToken) && str_starts_with($accessToken, 'ERROR:')) {
        echo json_encode(['success' => false, 'error' => $accessToken]);
        exit;
    }

    // GSC Search Analytics API
    $endDate   = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-90 days'));
    $payload   = json_encode([
        'startDate'  => $startDate,
        'endDate'    => $endDate,
        'dimensions' => ['query'],
        'dimensionFilterGroups' => [[
            'filters' => [['dimension' => 'page', 'operator' => 'equals', 'expression' => $url]],
        ]],
        'rowLimit'  => 50,
        'startRow'  => 0,
    ]);

    $apiUrl = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
    $ch     = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) { echo json_encode(['success' => false, 'error' => $curlErr]); exit; }

    $gscData = json_decode($resp, true);
    if ($httpCode !== 200 || isset($gscData['error'])) {
        $msg = $gscData['error']['message'] ?? 'HTTP ' . $httpCode;
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    $rows     = $gscData['rows'] ?? [];
    $keywords = array_map(function($r) {
        return [
            'query'       => $r['keys'][0] ?? '',
            'clicks'      => round($r['clicks'] ?? 0),
            'impressions' => round($r['impressions'] ?? 0),
            'ctr'         => round(($r['ctr'] ?? 0) * 100, 1),
            'position'    => round($r['position'] ?? 0, 1),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'keywords' => $keywords, 'site_url' => $siteUrl]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte action: ' . $action]);

// ── JWT / OAuth2 für Google Service Account ──
function getGscAccessToken(array $sa): string {
    $privateKey = $sa['private_key'] ?? '';
    $clientEmail = $sa['client_email'] ?? '';
    $tokenUri    = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';

    if (empty($privateKey) || empty($clientEmail)) return 'ERROR: Ungültiger Service Account (private_key oder client_email fehlt)';

    $now    = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64UrlEncode(json_encode([
        'iss'   => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'aud'   => $tokenUri,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $sigInput = $header . '.' . $claims;
    $pkRes    = openssl_pkey_get_private($privateKey);
    if (!$pkRes) return 'ERROR: OpenSSL konnte private_key nicht laden';

    $sig = '';
    if (!openssl_sign($sigInput, $sig, $pkRes, OPENSSL_ALGO_SHA256)) {
        return 'ERROR: JWT-Signierung fehlgeschlagen';
    }

    $jwt = $sigInput . '.' . base64UrlEncode($sig);

    $ch = curl_init($tokenUri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return 'ERROR: cURL-Fehler bei Token-Request: ' . $curlErr;
    $data = json_decode($resp, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        return 'ERROR: ' . ($data['error_description'] ?? $data['error'] ?? 'Token-Request fehlgeschlagen (HTTP ' . $httpCode . ')');
    }
    return $data['access_token'];
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
