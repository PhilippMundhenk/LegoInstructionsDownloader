<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$id   = trim((string)($_POST['set_id'] ?? ''));
$name = (string)($_POST['name'] ?? '');
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No set ID given']);
    exit;
}

$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$r = renameSet($id, $name, $downloadsDir);
if (!$r['ok']) {
    http_response_code(400);
    error_log("[lego] rename failed for $id: " . ($r['error'] ?? ''));
    echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Rename failed']);
    exit;
}

error_log("[lego] renamed set $id to '" . $r['name'] . "'");
echo json_encode(['ok' => true, 'name' => $r['name']]);
