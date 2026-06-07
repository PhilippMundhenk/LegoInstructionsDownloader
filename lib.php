<?php
declare(strict_types=1);

/**
 * Discover all set directories under $downloadsDir and return their parsed metadata,
 * sorted by set id. Skips Synology's @eaDir and anything that doesn't look like a set.
 */
function listSets(string $downloadsDir, string $publicPrefix = '/downloads'): array {
    if (!is_dir($downloadsDir)) {
        error_log("[lib] listSets: '$downloadsDir' is not a directory (or unreadable)");
        return [];
    }
    $entries = @scandir($downloadsDir);
    if ($entries === false) {
        error_log("[lib] listSets: scandir('$downloadsDir') failed — likely permission denied (SMB mount?)");
        return [];
    }
    $sets = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '@eaDir') {
            continue;
        }
        $path = $downloadsDir . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $set = parseSet($path, $publicPrefix);
        if ($set !== null) {
            $sets[] = $set;
        }
    }
    usort($sets, function ($a, $b) {
        return strnatcmp($a['id'], $b['id']);
    });
    return $sets;
}

/**
 * Parse one set directory. Returns null if it doesn't look like a set.
 *
 * The shape returned (stable for tests + templates):
 *   [
 *     'id'           => '31099',
 *     'title'        => 'Propeller Plane',
 *     'image'        => '/downloads/31099/31099_Prod.jpg' | null,
 *     'instructions' => [['pdf' => '/downloads/.../6308552.pdf', 'thumb' => '/downloads/.../6308552.png'], ...],
 *     'version'      => 1 | 2,
 *   ]
 */
function parseSet(string $dir, string $publicPrefix = '/downloads'): ?array {
    $id = basename($dir);
    $entries = @scandir($dir) ?: [];
    $files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e === '@eaDir') {
            continue;
        }
        $full = $dir . '/' . $e;
        if (is_file($full)) {
            $files[] = $e;
        }
    }

    $hasDataJson = in_array('data.json', $files, true);
    $hasNameTxt  = in_array('name.txt', $files, true);

    // Legacy sets are migrated to name.txt at container startup (see migrate.sh),
    // so by the time we get here everything should have one of these markers.
    if (!$hasDataJson && !$hasNameTxt) {
        return null;
    }

    $title = "Set $id";
    $json  = null;
    $version = $hasDataJson ? 1 : 2;

    if ($hasDataJson) {
        $raw = @file_get_contents($dir . '/data.json');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
                $title = extractTitleFromJson($decoded, $id) ?? $title;
            }
        }
    } else {
        $name = @file_get_contents($dir . '/name.txt');
        if (is_string($name) && trim($name) !== '') {
            $title = trim($name);
        }
    }

    $image = findMainImage($files, $id);
    $instructions = findInstructions($files);

    $base = rtrim($publicPrefix, '/') . '/' . rawurlencode($id);
    return [
        'id'           => $id,
        'title'        => $title,
        'image'        => $image !== null ? $base . '/' . rawurlencode($image) : null,
        'instructions' => array_map(function ($i) use ($base) {
            return [
                'pdf'   => $base . '/' . rawurlencode($i['pdf']),
                'thumb' => $i['thumb'] !== null ? $base . '/' . rawurlencode($i['thumb']) : null,
            ];
        }, $instructions),
        'version'      => $version,
    ];
}

function extractTitleFromJson(array $json, string $id): ?string {
    $source = $json['hits']['hits'][0]['_source'] ?? null;
    if (!is_array($source)) {
        return null;
    }
    $locales = $source['locale'] ?? [];
    foreach (['de-de', 'en-us', 'en-gb'] as $preferred) {
        if (!empty($locales[$preferred]['display_title'])) {
            return (string)$locales[$preferred]['display_title'];
        }
    }
    if (is_array($locales)) {
        foreach ($locales as $entry) {
            if (!empty($entry['display_title'])) {
                return (string)$entry['display_title'];
            }
        }
    }
    return null;
}

/**
 * Pick the main product image. Preference order:
 *   1. {ID}_Prod* (case insensitive)
 *   2. *Prod*
 *   3. *WEB_PRI*
 *   4. anything box-related
 */
function findMainImage(array $files, string $id): ?string {
    $images = array_values(array_filter($files, function ($f) {
        return preg_match('/\.(png|jpe?g)$/i', $f) && stripos($f, '@eaDir') === false;
    }));
    $patterns = [
        '/^' . preg_quote($id, '/') . '_Prod.*\.(png|jpe?g)$/i',
        '/_Prod.*\.(png|jpe?g)$/i',
        '/Prod.*\.(png|jpe?g)$/i',
        '/WEB_PRI.*\.(png|jpe?g)$/i',
        '/^' . preg_quote($id, '/') . '_Box1.*\.(png|jpe?g)$/i',
    ];
    foreach ($patterns as $p) {
        foreach ($images as $img) {
            if (preg_match($p, $img)) {
                return $img;
            }
        }
    }
    return null;
}

/**
 * Find building instructions: any .pdf in the dir, paired with a .png thumb of the same basename.
 * Sorted naturally by basename so 6308552 < 6308553 < 6309236, and *Alt* lands at the end.
 */
function findInstructions(array $files): array {
    $pdfs = array_values(array_filter($files, function ($f) {
        return preg_match('/\.pdf$/i', $f);
    }));
    $instructions = [];
    foreach ($pdfs as $pdf) {
        $base = preg_replace('/\.pdf$/i', '', $pdf);
        $thumb = null;
        foreach (['.png', '.jpg', '.jpeg'] as $ext) {
            if (in_array($base . $ext, $files, true)) {
                $thumb = $base . $ext;
                break;
            }
        }
        $instructions[] = ['pdf' => $pdf, 'thumb' => $thumb];
    }
    usort($instructions, function ($a, $b) {
        return strnatcmp(basename($a['pdf']), basename($b['pdf']));
    });
    return $instructions;
}

/**
 * Inspect $downloadsDir from the PHP process's perspective. Used by the empty-state
 * UI to tell the user *why* the list is empty (missing mount, wrong perms, etc.)
 * rather than the misleading "No sets yet" message.
 *
 * Returns ['kind' => 'ok'|'missing'|'unreadable'|'empty'|'no-sets', 'detail' => string].
 */
function diagnoseDownloads(string $downloadsDir): array {
    if (!file_exists($downloadsDir)) {
        return ['kind' => 'missing', 'detail' => "$downloadsDir does not exist inside the container"];
    }
    if (!is_dir($downloadsDir)) {
        return ['kind' => 'missing', 'detail' => "$downloadsDir is not a directory"];
    }
    if (!is_readable($downloadsDir)) {
        $owner = function_exists('posix_getpwuid') && function_exists('fileowner')
            ? (posix_getpwuid(fileowner($downloadsDir))['name'] ?? '?')
            : '?';
        $perms = substr(sprintf('%o', fileperms($downloadsDir)), -4);
        $whoami = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
            : '?';
        return [
            'kind'   => 'unreadable',
            'detail' => "$downloadsDir exists but is not readable by '$whoami' (owner=$owner, mode=$perms). "
                      . "If this is an SMB/CIFS mount, set uid=<UID>,gid=<GID> in the mount options on the host.",
        ];
    }
    $entries = @scandir($downloadsDir);
    if ($entries === false) {
        return ['kind' => 'unreadable', 'detail' => "scandir($downloadsDir) failed"];
    }
    $entries = array_values(array_filter($entries, function ($e) {
        return $e !== '.' && $e !== '..' && $e !== '@eaDir';
    }));
    if (empty($entries)) {
        return ['kind' => 'empty', 'detail' => "$downloadsDir is empty"];
    }
    return ['kind' => 'no-sets', 'detail' => count($entries) . " entries in $downloadsDir, none look like set directories"];
}

/**
 * Spawn fetch.sh for one set, with logging to $logFile (which start.sh tails to stdout).
 * Returns ['ok' => bool, 'output' => string].
 */
function runFetch(string $setId, string $downloadsDir, string $scriptDir, string $logFile): array {
    $setId = trim($setId);
    if (!preg_match('/^[0-9]{1,8}$/', $setId)) {
        return ['ok' => false, 'output' => "Invalid set id: $setId"];
    }
    $script = $scriptDir . '/fetch.sh';
    if (!is_file($script)) {
        return ['ok' => false, 'output' => "fetch.sh not found at $script"];
    }
    $cmd = sprintf(
        '%s %s %s >> %s 2>&1',
        escapeshellcmd($script),
        escapeshellarg($setId),
        escapeshellarg($downloadsDir),
        escapeshellarg($logFile)
    );
    $output = [];
    $retval = 0;
    exec($cmd, $output, $retval);
    return ['ok' => $retval === 0, 'output' => implode("\n", $output)];
}
