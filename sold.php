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
$sold = trim((string)($_POST['sold'] ?? ''));
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No set ID given']);
    exit;
}
if ($sold !== '0' && $sold !== '1') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'sold must be 0 or 1']);
    exit;
}

$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$r = setSold($id, $sold === '1', $downloadsDir);
if (!$r['ok']) {
    http_response_code(400);
    error_log("[lego] sold toggle failed for $id: " . ($r['error'] ?? ''));
    echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Update failed']);
    exit;
}

error_log("[lego] set $id marked " . ($r['sold'] ? 'sold' : 'not sold'));
echo json_encode(['ok' => true, 'sold' => $r['sold'], 'sold_on' => $r['sold_on']]);
