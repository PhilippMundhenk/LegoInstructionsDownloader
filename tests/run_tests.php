<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

/* tiny test harness */
$passed = 0;
$failed = 0;
$current = '';

function t(string $name, callable $fn): void {
    global $passed, $failed, $current;
    $current = $name;
    try {
        $fn();
        echo "  ok  $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assertEq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException("$msg expected " . var_export($expected, true) . " got " . var_export($actual, true));
    }
}

function assertTrue(bool $cond, string $msg = ''): void {
    if (!$cond) throw new RuntimeException("$msg expected true");
}

function assertNull($value, string $msg = ''): void {
    if ($value !== null) throw new RuntimeException("$msg expected null, got " . var_export($value, true));
}

function assertContains(array $haystack, $needle, string $msg = ''): void {
    if (!in_array($needle, $haystack, true)) {
        throw new RuntimeException("$msg expected to contain " . var_export($needle, true));
    }
}

/* build fixture tree */
$root = sys_get_temp_dir() . '/lego_test_' . getmypid();
echo "fixtures: $root\n";
if (is_dir($root)) {
    exec('rm -rf ' . escapeshellarg($root));
}
mkdir($root, 0777, true);

function touchFile(string $path, string $content = ''): void {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents($path, $content);
}

/* v1: data.json based set, like 31099 */
$v1Json = [
    'hits' => ['hits' => [[
        '_source' => [
            'locale' => [
                'de-de' => ['display_title' => 'Propellerflugzeug'],
                'en-us' => ['display_title' => 'Propeller Plane'],
            ],
        ],
    ]]],
];
touchFile("$root/31099/data.json", json_encode($v1Json));
touchFile("$root/31099/31099_Prod.jpg");
touchFile("$root/31099/31099_Box1_v39_2400.jpg");
touchFile("$root/31099/6308552.pdf");
touchFile("$root/31099/6308552.png");
touchFile("$root/31099/6308553.pdf");
touchFile("$root/31099/6308553.png");
touchFile("$root/31099/6309236.pdf"); // alt, sorts after by natural order
touchFile("$root/31099/6309236.png");

/* v2: name.txt based set, like 30640 */
touchFile("$root/30640/name.txt", "Cute Pug\n");
touchFile("$root/30640/id.txt", "raw html");
touchFile("$root/30640/30640_Prod.png");
touchFile("$root/30640/6447079.pdf");
touchFile("$root/30640/6447079.png");

/* v2 with alt instruction (like 30699) */
touchFile("$root/30699/name.txt", "Hedgehog Habitat");
touchFile("$root/30699/30699_Prod.png");
touchFile("$root/30699/6549534.pdf");
touchFile("$root/30699/6549534.png");
touchFile("$root/30699/30699_01_BI_Build_Alt.pdf");
touchFile("$root/30699/30699_01_BI_Build_Alt.png");

/* @eaDir noise: should be ignored */
touchFile("$root/@eaDir/should_be_ignored.png");
touchFile("$root/31099/@eaDir/some_thumb.png");

/* malformed data.json: should not blow up, falls back to "Set XXXX" */
touchFile("$root/12345/data.json", "{not valid json");
touchFile("$root/12345/12345_Prod.png");

/* directory with neither marker file: should be skipped
 * (legacy sets are migrated to name.txt at startup by migrate.sh) */
mkdir("$root/99999", 0777, true);
touchFile("$root/99999/whatever.txt");

/* json without title for any locale: falls back to "Set XXXX" */
touchFile("$root/22222/data.json", json_encode(['hits' => ['hits' => [['_source' => ['locale' => []]]]]]));
touchFile("$root/22222/22222_Prod.jpg");

/* stray file at root */
touchFile("$root/notes.txt", "should be ignored");

echo "\nrunning tests...\n";

t('listSets returns only real sets', function () use ($root) {
    $sets = listSets($root, '/d');
    $ids = array_column($sets, 'id');
    assertContains($ids, '31099');
    assertContains($ids, '30640');
    assertContains($ids, '30699');
    assertContains($ids, '12345');
    assertContains($ids, '22222');
    assertEq(false, in_array('@eaDir', $ids, true), '@eaDir leaked');
    assertEq(false, in_array('99999', $ids, true), '99999 (no markers) leaked');
});

t('listSets sorts by natural id order', function () use ($root) {
    $sets = listSets($root, '/d');
    $ids = array_column($sets, 'id');
    $sorted = $ids;
    usort($sorted, 'strnatcmp');
    assertEq($sorted, $ids, 'natural sort');
});

t('v1 picks de-de display_title', function () use ($root) {
    $set = parseSet("$root/31099", '/d');
    assertEq('Propellerflugzeug', $set['title']);
    assertEq(1, $set['version']);
});

t('v1 picks Prod image as main image', function () use ($root) {
    $set = parseSet("$root/31099", '/d');
    assertTrue(str_ends_with($set['image'], '31099_Prod.jpg'), 'main image');
});

t('v1 lists instructions with thumbs, naturally sorted', function () use ($root) {
    $set = parseSet("$root/31099", '/d');
    assertEq(3, count($set['instructions']));
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    assertEq(['6308552.pdf','6308553.pdf','6309236.pdf'], $pdfs);
    foreach ($set['instructions'] as $i) {
        assertTrue($i['thumb'] !== null, 'thumb present');
    }
});

t('v2 reads name from name.txt', function () use ($root) {
    $set = parseSet("$root/30640", '/d');
    assertEq('Cute Pug', $set['title']);
    assertEq(2, $set['version']);
});

t('v2 includes alt build instruction', function () use ($root) {
    $set = parseSet("$root/30699", '/d');
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    assertContains($pdfs, '6549534.pdf');
    assertContains($pdfs, '30699_01_BI_Build_Alt.pdf');
});

t('malformed data.json falls back gracefully', function () use ($root) {
    $set = parseSet("$root/12345", '/d');
    assertEq('Set 12345', $set['title']);
    assertEq(1, $set['version']);
});

t('json without any locale title falls back to id', function () use ($root) {
    $set = parseSet("$root/22222", '/d');
    assertEq('Set 22222', $set['title']);
});

t('parseSet returns null for dirs without markers', function () use ($root) {
    $set = parseSet("$root/99999", '/d');
    assertNull($set);
});

t('URLs use the public prefix and url-encode the id', function () use ($root) {
    $set = parseSet("$root/31099", '/public');
    assertTrue(str_starts_with($set['image'], '/public/31099/'), 'image url prefix');
    assertTrue(str_starts_with($set['instructions'][0]['pdf'], '/public/31099/'), 'pdf url prefix');
});

t('findMainImage prefers Prod over Box', function () {
    $files = ['31099_Box1_v39_2400.jpg', '31099_Prod.jpg', 'logo_lego.png'];
    assertEq('31099_Prod.jpg', findMainImage($files, '31099'));
});

t('findMainImage falls back to box when no Prod', function () {
    $files = ['31099_Box1_v39_2400.jpg', 'logo_lego.png'];
    assertEq('31099_Box1_v39_2400.jpg', findMainImage($files, '31099'));
});

t('findMainImage returns null when no images', function () {
    assertNull(findMainImage(['data.json', '6308552.pdf'], '31099'));
});

t('findInstructions pairs pdf with png thumb', function () {
    $files = ['6308552.pdf', '6308552.png', '6308553.pdf', '6308553.png'];
    $instr = findInstructions($files);
    assertEq(2, count($instr));
    assertEq('6308552.pdf', $instr[0]['pdf']);
    assertEq('6308552.png', $instr[0]['thumb']);
});

t('findInstructions handles pdf without thumb', function () {
    $files = ['6308552.pdf'];
    $instr = findInstructions($files);
    assertEq(1, count($instr));
    assertNull($instr[0]['thumb']);
});

t('runFetch rejects invalid set id', function () use ($root) {
    $r = runFetch('abc; rm -rf /', $root, __DIR__ . '/..', '/tmp/lego_test.log');
    assertEq(false, $r['ok']);
    assertTrue(str_contains($r['output'], 'Invalid'), 'rejects shell injection');
});

t('runFetch rejects too-long id', function () use ($root) {
    $r = runFetch('123456789', $root, __DIR__ . '/..', '/tmp/lego_test.log');
    assertEq(false, $r['ok']);
});

echo "\n";
echo "$passed passed, $failed failed\n";

/* cleanup */
exec('rm -rf ' . escapeshellarg($root));

exit($failed === 0 ? 0 : 1);
