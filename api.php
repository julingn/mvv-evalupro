<?php
/**
 * api.php — KI-Proxy: Anthropic Claude + OpenAI GPT
 * Provider + Keys werden aus settings.json geladen.
 * OpenAI-Antworten werden in Anthropic-Format normalisiert,
 * damit das Frontend keine Unterschiede sieht.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method Not Allowed']); exit; }

// Settings laden
$settingsFile = __DIR__ . '/settings.json';
$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
// Provider: expliziter Request-Body-Parameter hat höchste Priorität (z.B. Verbindungstest),
// dann env var, dann settings.json
$provider = $body['_provider'] ?? (getenv('AI_PROVIDER') ?: ($settings['ai_provider'] ?? 'anthropic'));

// Request-Body
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);
if (!$body || !isset($body['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => ['type' => 'bad_request', 'message' => 'messages fehlt']]);
    exit;
}

// ────────────────────────────────────────────────
// ANTHROPIC
// ────────────────────────────────────────────────
if ($provider === 'anthropic') {
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: ($settings['anthropic_api_key'] ?? '');
    if (empty($apiKey)) {
        http_response_code(503);
        echo json_encode(['error' => ['type' => 'no_key', 'message' => 'Kein Anthropic API-Key hinterlegt. Bitte in den Einstellungen eintragen.']]);
        exit;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rawInput,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: interleaved-thinking-2025-05-14',
        ],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { http_response_code(502); echo json_encode(['error' => ['type' => 'curl_error', 'message' => $curlError]]); exit; }
    http_response_code($httpCode);
    echo $response;
    exit;
}

// ────────────────────────────────────────────────
// OPENAI
// ────────────────────────────────────────────────
if ($provider === 'openai') {
    $apiKey = getenv('OPENAI_API_KEY') ?: ($settings['openai_api_key'] ?? '');
    if (empty($apiKey)) {
        http_response_code(503);
        echo json_encode(['error' => ['type' => 'no_key', 'message' => 'Kein OpenAI API-Key hinterlegt. Bitte in den Einstellungen eintragen.']]);
        exit;
    }

    // Modell: vom Client übermitteltes Feld hat Priorität (z.B. beim Verbindungstest),
    // danach env var, dann settings.json
    $model = $body['model'] ?? (getenv('OPENAI_MODEL') ?: ($settings['openai_model'] ?? 'gpt-4.1'));

    // Nachrichten transformieren: Anthropic → OpenAI
    $messages = $body['messages'];

    // system-Feld → role:system als erste Nachricht einfügen
    if (!empty($body['system'])) {
        array_unshift($messages, ['role' => 'system', 'content' => $body['system']]);
    }

    // Anthropic-spezifische tools (web_search_20250305) werden für OpenAI weggelassen.
    $openAiPayload = [
        'model'      => $model,
        'max_tokens' => $body['max_tokens'] ?? 2000,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($openAiPayload),
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { http_response_code(502); echo json_encode(['error' => ['type' => 'curl_error', 'message' => $curlError]]); exit; }

    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? ('HTTP ' . $httpCode);
        http_response_code($httpCode);
        echo json_encode(['error' => ['type' => 'openai_error', 'message' => $errMsg]]);
        exit;
    }

    // OpenAI-Response → Anthropic-Format normalisieren
    $openAiData = json_decode($response, true);
    $text = $openAiData['choices'][0]['message']['content'] ?? '';
    echo json_encode([
        'id'      => $openAiData['id'] ?? '',
        'model'   => $model,
        'content' => [['type' => 'text', 'text' => $text]],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => ['type' => 'bad_provider', 'message' => 'Unbekannter Provider: ' . $provider]]);
