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
    $considered = 0;
    $rejected = [];
    $sets = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '@eaDir') {
            continue;
        }
        $path = $downloadsDir . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $considered++;
        $set = parseSet($path, $publicPrefix);
        if ($set !== null) {
            $sets[] = $set;
        } else {
            $rejected[] = $entry;
        }
    }
    if (!empty($rejected)) {
        error_log("[lib] listSets: " . count($sets) . "/$considered shown; rejected (no marker file): "
            . implode(', ', array_slice($rejected, 0, 50))
            . (count($rejected) > 50 ? ' …' : ''));
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
    }
    // name.txt always wins when present, even on v1 sets: it's how the user
    // renames a set from the UI and we don't want data.json to clobber it.
    if ($hasNameTxt) {
        $name = @file_get_contents($dir . '/name.txt');
        if (is_string($name) && trim($name) !== '') {
            $title = trim($name);
        }
    }

    $image = findMainImage($files, $id);
    $instructions = findInstructions($files);
    if ($json !== null) {
        $allowed = allowedPdfsFromJson($json);
        if ($allowed !== null) {
            $filtered = array_values(array_filter($instructions, function ($i) use ($allowed) {
                return isset($allowed[$i['pdf']]);
            }));
            if (!empty($filtered)) {
                $instructions = $filtered;
            }
        }
    }

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
 * Build the set of canonical PDF basenames from a parsed data.json so we can
 * drop duplicate language/region copies that the scraping fetch may have
 * downloaded. The legacy Lego API (still cached as data.json on disk for older
 * sets) returns one entry under product_versions[] per region, each containing
 * its own building_instructions[] — same build, different PDF per locale.
 *
 * We dedupe by the building_instruction `type` (e.g. product.bi.core vs
 * product.bi.additional.extra) so that a multi-book core instruction collapses
 * into a single entry while genuinely different kinds of instructions (core +
 * alt build) remain distinct. Falls back to sequence.element, then to file
 * basename, when `type` is absent. First occurrence wins. Returns null when
 * the json has no usable product_versions structure (caller then keeps the
 * on-disk listing untouched).
 */
function allowedPdfsFromJson(array $json): ?array {
    $source = $json['hits']['hits'][0]['_source'] ?? null;
    if (!is_array($source)) {
        return null;
    }
    $versions = $source['product_versions'] ?? null;
    if (!is_array($versions) || empty($versions)) {
        return null;
    }
    $allowed = [];
    $seenKinds = [];
    foreach ($versions as $version) {
        if (!is_array($version)) continue;
        $instr = $version['building_instructions'] ?? null;
        if (!is_array($instr)) continue;
        foreach ($instr as $bi) {
            if (!is_array($bi)) continue;
            $url = $bi['file']['url'] ?? null;
            if (!is_string($url) || $url === '') continue;
            $base = basename($url);
            $type = $bi['type'] ?? null;
            $element = $bi['sequence']['element'] ?? null;
            if (is_string($type) && $type !== '') {
                $key = 'type:' . $type;
            } elseif ($element !== null) {
                $key = 'seq:' . (string)$element;
            } else {
                $key = 'file:' . $base;
            }
            if (isset($seenKinds[$key])) {
                continue;
            }
            $seenKinds[$key] = true;
            $allowed[$base] = true;
        }
    }
    return empty($allowed) ? null : $allowed;
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
 * External catalog URLs for a set, shown in the per-card menu so the user can
 * jump from a downloaded set to its BrickLink / Brickset / Rebrickable /
 * LEGO.com page. Returns an empty list for an id that doesn't match the same
 * shape we accept everywhere else.
 *
 * BrickLink / Brickset / Rebrickable all suffix the id with "-1" because their
 * catalog keys are <set>-<variant>; -1 is the original release and is the
 * right default for the vast majority of sets.
 *
 * Each entry: ['label' => string, 'url' => string].
 */
function externalLinks(string $setId): array {
    $setId = trim($setId);
    if (!preg_match('/^[0-9]{1,8}$/', $setId)) {
        return [];
    }
    return [
        ['label' => 'LEGO.com',     'url' => 'https://www.lego.com/en-us/product/' . rawurlencode($setId)],
        ['label' => 'Instructions', 'url' => 'https://www.lego.com/en-us/service/building-instructions/' . rawurlencode($setId)],
        ['label' => 'BrickLink',    'url' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . rawurlencode($setId) . '-1'],
        ['label' => 'Brickset',     'url' => 'https://brickset.com/sets/' . rawurlencode($setId) . '-1'],
        ['label' => 'Rebrickable',  'url' => 'https://rebrickable.com/sets/' . rawurlencode($setId) . '-1/'],
    ];
}

/**
 * Delete a set's directory and everything inside it. The set id must match the
 * same /^[0-9]{1,8}$/ shape we accept on download so we can never resolve to
 * anything outside $downloadsDir. Missing set is treated as success — the
 * caller's "remove this card" intent has already been met.
 * Returns ['ok' => bool, 'error' => string|null].
 */
function removeSet(string $setId, string $downloadsDir): array {
    $setId = trim($setId);
    if (!preg_match('/^[0-9]{1,8}$/', $setId)) {
        return ['ok' => false, 'error' => "Invalid set id: $setId"];
    }
    if (!is_dir($downloadsDir)) {
        return ['ok' => false, 'error' => "Downloads dir does not exist"];
    }
    $target = $downloadsDir . '/' . $setId;
    // Belt-and-suspenders: resolve the path and make sure it really sits under
    // $downloadsDir before we hand it to rm. realpath() collapses any .. that
    // somehow slipped past the regex.
    $realDl  = realpath($downloadsDir);
    $realTgt = realpath($target);
    if ($realDl === false) {
        return ['ok' => false, 'error' => "Cannot resolve downloads dir"];
    }
    if ($realTgt === false) {
        return ['ok' => true, 'error' => null];
    }
    if (strpos($realTgt, rtrim($realDl, '/') . '/') !== 0) {
        return ['ok' => false, 'error' => "Refusing to delete outside downloads dir"];
    }
    $cmd = 'rm -rf ' . escapeshellarg($realTgt) . ' 2>&1';
    $output = [];
    $retval = 0;
    exec($cmd, $output, $retval);
    if ($retval !== 0) {
        return ['ok' => false, 'error' => 'rm failed: ' . implode("\n", $output)];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Rename a set by writing a sanitized name into its name.txt. parseSet() prefers
 * name.txt over data.json's title, so this works for both v1 (api-cached) and v2
 * (scraped) sets without touching the underlying data.json.
 *
 * Sanitization: trim, collapse internal whitespace (incl. newlines and tabs)
 * into single spaces, strip control chars, cap at 200 chars. Empty after
 * sanitizing is rejected — use removeSet to delete a set.
 *
 * Returns ['ok' => bool, 'error' => string|null, 'name' => string|null].
 */
function renameSet(string $setId, string $name, string $downloadsDir): array {
    $setId = trim($setId);
    if (!preg_match('/^[0-9]{1,8}$/', $setId)) {
        return ['ok' => false, 'error' => "Invalid set id: $setId", 'name' => null];
    }
    if (!is_dir($downloadsDir)) {
        return ['ok' => false, 'error' => "Downloads dir does not exist", 'name' => null];
    }
    $target = $downloadsDir . '/' . $setId;
    $realDl  = realpath($downloadsDir);
    $realTgt = realpath($target);
    if ($realDl === false || $realTgt === false) {
        return ['ok' => false, 'error' => "Set not found", 'name' => null];
    }
    if (strpos($realTgt, rtrim($realDl, '/') . '/') !== 0) {
        return ['ok' => false, 'error' => "Refusing to write outside downloads dir", 'name' => null];
    }
    if (!is_dir($realTgt)) {
        return ['ok' => false, 'error' => "Set directory missing", 'name' => null];
    }
    // Order matters: collapse whitespace (incl. \n, \t) first so they become
    // spaces, then strip remaining non-whitespace control chars.
    $clean = preg_replace('/\s+/u', ' ', $name);
    $clean = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string)$clean);
    $clean = trim((string)$clean);
    if ($clean === '') {
        return ['ok' => false, 'error' => 'Name cannot be empty', 'name' => null];
    }
    if (mb_strlen($clean) > 200) {
        $clean = mb_substr($clean, 0, 200);
    }
    $path = $realTgt . '/name.txt';
    if (@file_put_contents($path, $clean . "\n") === false) {
        return ['ok' => false, 'error' => 'Could not write name.txt', 'name' => null];
    }
    return ['ok' => true, 'error' => null, 'name' => $clean];
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
