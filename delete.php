<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw = trim((string)($_POST['set_id'] ?? ''));
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No set ID given']);
    exit;
}

$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$r = removeSet($raw, $downloadsDir);
if (!$r['ok']) {
    http_response_code(400);
    error_log("[lego] delete failed for $raw: " . ($r['error'] ?? ''));
    echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Delete failed']);
    exit;
}

error_log("[lego] deleted set $raw");
echo json_encode(['ok' => true]);
