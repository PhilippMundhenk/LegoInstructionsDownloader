<?php
declare(strict_types=1);
require_once __DIR__ . '/i18n.php';
// Resolve the locale eagerly so getLocale() can set the cookie before we send
// any output for plain-text mode below.
getLocale();
header('Content-Type: text/plain; charset=utf-8');
$logFile = getenv('LEGO_LOG_FILE') ?: '/var/log/lego.log';
if (!is_file($logFile)) {
    echo t('log.title') . "\n";
    echo "Log file not found: $logFile\n";
    exit;
}
$size = filesize($logFile);
$max  = 256 * 1024;
$fh   = fopen($logFile, 'rb');
if (!$fh) {
    echo "Cannot read log file\n";
    exit;
}
if ($size > $max) {
    fseek($fh, $size - $max);
    fgets($fh);
    echo "... (truncated) ...\n";
}
while (!feof($fh)) {
    echo fread($fh, 8192);
}
fclose($fh);
