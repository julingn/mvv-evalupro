<?php
/**
 * archive.php — Analyse-Archiv (sqeg_archive.json, max. 12 Einträge)
 * GET               → alle Einträge laden
 * POST action=save  → neuen Eintrag speichern
 * POST action=delete → Eintrag löschen (via id)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ARCHIVE_FILE', __DIR__ . '/sqeg_archive.json');
define('MAX_ENTRIES', 12);

function loadArchive(): array {
    if (!file_exists(ARCHIVE_FILE)) return [];
    $data = json_decode(file_get_contents(ARCHIVE_FILE), true);
    return is_array($data) ? $data : [];
}

function saveArchive(array $data): void {
    file_put_contents(ARCHIVE_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── GET: Archiv laden ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadArchive());
    exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method Not Allowed']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// Eintrag speichern
if ($action === 'save') {
    $entry = $body['entry'] ?? null;
    if (!$entry || empty($entry['url'])) {
        http_response_code(400); echo json_encode(['error' => 'entry.url fehlt']); exit;
    }
    $entry['id']        = $entry['id'] ?? uniqid('sqeg_', true);
    $entry['timestamp'] = $entry['timestamp'] ?? date('c');

    $archive = loadArchive();
    // Doppelte URL aktualisieren statt neu anlegen
    $replaced = false;
    foreach ($archive as &$e) {
        if (($e['url'] ?? '') === $entry['url']) { $e = $entry; $replaced = true; break; }
    }
    unset($e);
    if (!$replaced) array_unshift($archive, $entry);
    // Max. 12 Einträge
    $archive = array_slice($archive, 0, MAX_ENTRIES);
    saveArchive($archive);
    echo json_encode(['success' => true, 'id' => $entry['id']]);
    exit;
}

// Eintrag löschen
if ($action === 'delete') {
    $id      = $body['id'] ?? '';
    $archive = loadArchive();
    $archive = array_values(array_filter($archive, fn($e) => ($e['id'] ?? '') !== $id));
    saveArchive($archive);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte action: ' . $action]);
