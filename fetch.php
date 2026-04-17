<?php
/**
 * fetch.php — HTML-Fetch-Proxy
 * Lädt die HTML-Quelle einer URL via cURL und gibt sie zurück.
 * Antwortet mit { success, html, url, size, truncated } oder { error }
 * Ping-Erkennung: url === 'ping' → 400 + error:'Invalid URL' (Verbindungstest)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method Not Allowed']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$url  = trim($body['url'] ?? '');

// Ping / Verbindungstest
if (empty($url) || $url === 'ping' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

// Maximale HTML-Größe: 800 KB
define('MAX_BYTES', 800 * 1024);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => '',   // Alle Encodings akzeptieren
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (compatible; EvaluPro/4.5; +https://evalupro.de)',
        'Accept: text/html,application/xhtml+xml,*/*;q=0.9',
        'Accept-Language: de,en;q=0.9',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_BUFFERSIZE     => 65536,
]);

$html      = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL-Fehler: ' . $curlError]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'Zielserver antwortete mit HTTP ' . $httpCode]);
    exit;
}

if ($html === false || $html === '') {
    http_response_code(502);
    echo json_encode(['error' => 'Leere Antwort vom Zielserver']);
    exit;
}

$truncated = false;
if (strlen($html) > MAX_BYTES) {
    $html      = substr($html, 0, MAX_BYTES);
    $truncated = true;
}

echo json_encode([
    'success'   => true,
    'html'      => $html,
    'url'       => $finalUrl ?: $url,
    'size'      => strlen($html),
    'truncated' => $truncated,
]);
