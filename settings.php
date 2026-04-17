<?php
/**
 * settings.php — Einstellungen lesen und schreiben
 * GET  → { settings: { anthropic_api_key_masked, dataforseo_login_masked } }
 * POST → Felder aktualisieren: anthropic_api_key, login_password, dataforseo_login, dataforseo_password
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('SETTINGS_FILE', __DIR__ . '/settings.json');

function loadSettings(): array {
    if (!file_exists(SETTINGS_FILE)) return [];
    $data = json_decode(file_get_contents(SETTINGS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveSettings(array $data): void {
    file_put_contents(SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function maskKey(string $key): string {
    if (strlen($key) < 8) return str_repeat('*', strlen($key));
    return substr($key, 0, 10) . str_repeat('*', max(0, strlen($key) - 14)) . substr($key, -4);
}

function maskEmail(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return '***';
    $local = $parts[0];
    return (strlen($local) > 2 ? substr($local, 0, 2) : $local) . '***@' . $parts[1];
}

// Liest Wert: env hat Priorität über settings.json
// Gibt ['value' => '...', 'source' => 'env'|'json'] zurück
function getSetting(array $s, string $envKey, string $jsonKey): array {
    $envVal = getenv($envKey);
    if ($envVal !== false && $envVal !== '') {
        return ['value' => $envVal, 'source' => 'env'];
    }
    $jsonVal = $s[$jsonKey] ?? '';
    return ['value' => $jsonVal, 'source' => 'json'];
}

// ── GET: maskierte Werte + Source zurückgeben ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = loadSettings();

    $ant    = getSetting($s, 'ANTHROPIC_API_KEY', 'anthropic_api_key');
    $oai    = getSetting($s, 'OPENAI_API_KEY',    'openai_api_key');
    $dfsLog = getSetting($s, 'DATAFORSEO_LOGIN',  'dataforseo_login');
    $dfsPw  = getSetting($s, 'DATAFORSEO_PASSWORD','dataforseo_password');
    $prov   = getSetting($s, 'AI_PROVIDER',       'ai_provider');
    $model  = getSetting($s, 'OPENAI_MODEL',      'openai_model');

    echo json_encode([
        'success'  => true,
        'settings' => [
            'anthropic_api_key_masked'  => !empty($ant['value'])    ? maskKey($ant['value'])       : '',
            'anthropic_api_key_source'  => $ant['source'],
            'openai_api_key_masked'     => !empty($oai['value'])    ? maskKey($oai['value'])       : '',
            'openai_api_key_source'     => $oai['source'],
            'dataforseo_login_masked'   => !empty($dfsLog['value']) ? maskEmail($dfsLog['value'])  : '',
            'dataforseo_login_source'   => $dfsLog['source'],
            'dataforseo_password_set'   => !empty($dfsPw['value']),
            'dataforseo_password_source'=> $dfsPw['source'],
            'ai_provider'               => !empty($prov['value'])  ? $prov['value']  : 'anthropic',
            'ai_provider_source'        => $prov['source'],
            'openai_model'              => !empty($model['value']) ? $model['value'] : 'gpt-4.1',
            'openai_model_source'       => $model['source'],
        ],
    ]);
    exit;
}

// ── POST: Einstellungen aktualisieren ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method Not Allowed']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$s    = loadSettings();
$changed = false;

if (!empty($body['anthropic_api_key'])) {
    if (getenv('ANTHROPIC_API_KEY') !== false && getenv('ANTHROPIC_API_KEY') !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'ANTHROPIC_API_KEY ist als Umgebungsvariable gesetzt und kann hier nicht überschrieben werden.']);
        exit;
    }
    $key = trim($body['anthropic_api_key']);
    if (!str_starts_with($key, 'sk-ant-')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges API-Key-Format. Muss mit sk-ant- beginnen.']);
        exit;
    }
    $s['anthropic_api_key'] = $key;
    $changed = true;
}

if (!empty($body['openai_api_key'])) {
    if (getenv('OPENAI_API_KEY') !== false && getenv('OPENAI_API_KEY') !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'OPENAI_API_KEY ist als Umgebungsvariable gesetzt und kann hier nicht überschrieben werden.']);
        exit;
    }
    $s['openai_api_key'] = trim($body['openai_api_key']);
    $changed = true;
}

if (isset($body['ai_provider']) && in_array($body['ai_provider'], ['anthropic', 'openai'])) {
    if (getenv('AI_PROVIDER') === false || getenv('AI_PROVIDER') === '') {
        $s['ai_provider'] = $body['ai_provider'];
        $changed = true;
    }
}

if (!empty($body['openai_model'])) {
    if (getenv('OPENAI_MODEL') === false || getenv('OPENAI_MODEL') === '') {
        $s['openai_model'] = trim($body['openai_model']);
        $changed = true;
    }
}

if (!empty($body['dataforseo_login'])) {
    if (getenv('DATAFORSEO_LOGIN') !== false && getenv('DATAFORSEO_LOGIN') !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'DATAFORSEO_LOGIN ist als Umgebungsvariable gesetzt.']);
        exit;
    }
    $s['dataforseo_login'] = trim($body['dataforseo_login']);
    $changed = true;
}

if (!empty($body['dataforseo_password'])) {
    if (getenv('DATAFORSEO_PASSWORD') !== false && getenv('DATAFORSEO_PASSWORD') !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'DATAFORSEO_PASSWORD ist als Umgebungsvariable gesetzt.']);
        exit;
    }
    $s['dataforseo_password'] = trim($body['dataforseo_password']);
    $changed = true;
}

if (!empty($body['login_password'])) {
    $pw = $body['login_password'];
    if (strlen($pw) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwort muss mindestens 6 Zeichen haben.']);
        exit;
    }
    $s['login_password_hash'] = password_hash($pw, PASSWORD_BCRYPT);
    $changed = true;
}

if (!$changed) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine Änderungen übergeben.']);
    exit;
}

saveSettings($s);
echo json_encode(['success' => true]);
