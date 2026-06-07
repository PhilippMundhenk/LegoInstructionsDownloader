<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw = $_POST['set_id'] ?? '';
$raw = trim((string)$raw);
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No set ID given']);
    exit;
}

$ids = array_values(array_filter(array_map('trim', explode(',', $raw))));
$downloadsDir = getenv('DOWNLOADS_DIR') ?: '/downloads';
$scriptDir    = __DIR__;
$logFile      = getenv('LEGO_LOG_FILE') ?: '/var/log/lego.log';

$results = [];
$allOk = true;
foreach ($ids as $id) {
    $r = runFetch($id, $downloadsDir, $scriptDir, $logFile);
    $results[$id] = $r['ok'];
    if (!$r['ok']) {
        $allOk = false;
        error_log("[lego] download failed for $id: " . $r['output']);
    }
}

if (!$allOk) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'One or more downloads failed. See log.',
        'results' => $results,
    ]);
    exit;
}

echo json_encode(['ok' => true, 'results' => $results]);
