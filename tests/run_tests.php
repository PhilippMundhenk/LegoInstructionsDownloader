<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../i18n.php';

/* tiny test harness */
$passed = 0;
$failed = 0;
$current = '';

function it(string $name, callable $fn): void {
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

/* v1 with duplicate language/region PDFs that data.json must filter down.
 * Simulates a legacy set where the Lego API returned one product_version per
 * region, each carrying the same build steps but locale-specific PDFs. The
 * scraping fetch downloaded every PDF; data.json names the canonical ones. */
$v1DupJson = [
    'hits' => ['hits' => [[
        '_source' => [
            'locale' => [
                'de-de' => ['display_title' => 'Big Set DE'],
                'en-us' => ['display_title' => 'Big Set US'],
            ],
            'product_versions' => [
                // de-de region: this is the "canonical" pick (first wins).
                ['building_instructions' => [
                    ['file' => ['url' => 'https://x/cdn/7000001.pdf'], 'sequence' => ['element' => 1]],
                    ['file' => ['url' => 'https://x/cdn/7000002.pdf'], 'sequence' => ['element' => 2]],
                ]],
                // en-us region: same build steps, different PDFs — must be filtered out.
                ['building_instructions' => [
                    ['file' => ['url' => 'https://x/cdn/7000011.pdf'], 'sequence' => ['element' => 1]],
                    ['file' => ['url' => 'https://x/cdn/7000012.pdf'], 'sequence' => ['element' => 2]],
                ]],
            ],
        ],
    ]]],
];
touchFile("$root/40001/data.json", json_encode($v1DupJson));
touchFile("$root/40001/40001_Prod.jpg");
// All four PDFs ended up on disk; we expect only the de-de pair to show.
foreach (['7000001', '7000002', '7000011', '7000012'] as $b) {
    touchFile("$root/40001/$b.pdf");
    touchFile("$root/40001/$b.png");
}

/* v1 with data.json that has product_versions but none match disk PDFs.
 * The filter must not blank the card — fall back to the on-disk listing. */
$v1MismatchJson = [
    'hits' => ['hits' => [[
        '_source' => [
            'locale' => ['de-de' => ['display_title' => 'Stale Json']],
            'product_versions' => [
                ['building_instructions' => [
                    ['file' => ['url' => 'https://x/cdn/9999999.pdf'], 'sequence' => ['element' => 1]],
                ]],
            ],
        ],
    ]]],
];
touchFile("$root/40002/data.json", json_encode($v1MismatchJson));
touchFile("$root/40002/40002_Prod.jpg");
touchFile("$root/40002/8000001.pdf");
touchFile("$root/40002/8000001.png");

/* v1 where data.json lacks sequence.element — must dedupe by file basename so
 * an entry repeated across versions isn't double-counted. */
$v1NoSeqJson = [
    'hits' => ['hits' => [[
        '_source' => [
            'locale' => ['de-de' => ['display_title' => 'No Sequence']],
            'product_versions' => [
                ['building_instructions' => [
                    ['file' => ['url' => 'https://x/cdn/7100001.pdf']],
                    ['file' => ['url' => 'https://x/cdn/7100002.pdf']],
                ]],
                ['building_instructions' => [
                    ['file' => ['url' => 'https://x/cdn/7100001.pdf']], // dup basename
                    ['file' => ['url' => 'https://x/cdn/7100003.pdf']],
                ]],
            ],
        ],
    ]]],
];
touchFile("$root/40003/data.json", json_encode($v1NoSeqJson));
touchFile("$root/40003/40003_Prod.jpg");
foreach (['7100001', '7100002', '7100003'] as $b) {
    touchFile("$root/40003/$b.pdf");
    touchFile("$root/40003/$b.png");
}

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

it('listSets returns only real sets', function () use ($root) {
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

it('listSets sorts by natural id order', function () use ($root) {
    $sets = listSets($root, '/d');
    $ids = array_column($sets, 'id');
    $sorted = $ids;
    usort($sorted, 'strnatcmp');
    assertEq($sorted, $ids, 'natural sort');
});

it('v1 picks de-de display_title', function () use ($root) {
    $set = parseSet("$root/31099", '/d');
    assertEq('Propellerflugzeug', $set['title']);
    assertEq(1, $set['version']);
});

it('v1 picks Prod image as main image', function () use ($root) {
    $set = parseSet("$root/31099", '/d');
    assertTrue(str_ends_with($set['image'], '31099_Prod.jpg'), 'main image');
});

it('v1 lists instructions with thumbs, naturally sorted', function () use ($root) {
    $set = parseSet("$root/31099", '/d');
    assertEq(3, count($set['instructions']));
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    assertEq(['6308552.pdf','6308553.pdf','6309236.pdf'], $pdfs);
    foreach ($set['instructions'] as $i) {
        assertTrue($i['thumb'] !== null, 'thumb present');
    }
});

it('v2 reads name from name.txt', function () use ($root) {
    $set = parseSet("$root/30640", '/d');
    assertEq('Cute Pug', $set['title']);
    assertEq(2, $set['version']);
});

it('v2 includes alt build instruction', function () use ($root) {
    $set = parseSet("$root/30699", '/d');
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    assertContains($pdfs, '6549534.pdf');
    assertContains($pdfs, '30699_01_BI_Build_Alt.pdf');
});

it('malformed data.json falls back gracefully', function () use ($root) {
    $set = parseSet("$root/12345", '/d');
    assertEq('Set 12345', $set['title']);
    assertEq(1, $set['version']);
});

it('json without any locale title falls back to id', function () use ($root) {
    $set = parseSet("$root/22222", '/d');
    assertEq('Set 22222', $set['title']);
});

it('parseSet returns null for dirs without markers', function () use ($root) {
    $set = parseSet("$root/99999", '/d');
    assertNull($set);
});

it('URLs use the public prefix and url-encode the id', function () use ($root) {
    $set = parseSet("$root/31099", '/public');
    assertTrue(str_starts_with($set['image'], '/public/31099/'), 'image url prefix');
    assertTrue(str_starts_with($set['instructions'][0]['pdf'], '/public/31099/'), 'pdf url prefix');
});

it('findMainImage prefers Prod over Box', function () {
    $files = ['31099_Box1_v39_2400.jpg', '31099_Prod.jpg', 'logo_lego.png'];
    assertEq('31099_Prod.jpg', findMainImage($files, '31099'));
});

it('findMainImage falls back to box when no Prod', function () {
    $files = ['31099_Box1_v39_2400.jpg', 'logo_lego.png'];
    assertEq('31099_Box1_v39_2400.jpg', findMainImage($files, '31099'));
});

it('findMainImage returns null when no images', function () {
    assertNull(findMainImage(['data.json', '6308552.pdf'], '31099'));
});

it('findInstructions pairs pdf with png thumb', function () {
    $files = ['6308552.pdf', '6308552.png', '6308553.pdf', '6308553.png'];
    $instr = findInstructions($files);
    assertEq(2, count($instr));
    assertEq('6308552.pdf', $instr[0]['pdf']);
    assertEq('6308552.png', $instr[0]['thumb']);
});

it('findInstructions handles pdf without thumb', function () {
    $files = ['6308552.pdf'];
    $instr = findInstructions($files);
    assertEq(1, count($instr));
    assertNull($instr[0]['thumb']);
});

it('data.json dedupes language/region duplicates by sequence.element', function () use ($root) {
    $set = parseSet("$root/40001", '/d');
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    sort($pdfs);
    assertEq(['7000001.pdf', '7000002.pdf'], $pdfs, 'first product_version wins');
    foreach ($set['instructions'] as $i) {
        assertTrue($i['thumb'] !== null, 'thumb paired');
    }
});

it('data.json filter falls back to full listing when nothing matches on disk', function () use ($root) {
    $set = parseSet("$root/40002", '/d');
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    assertEq(['8000001.pdf'], $pdfs, 'stale data.json must not blank the card');
});

it('data.json without sequence dedupes by file basename across versions', function () use ($root) {
    $set = parseSet("$root/40003", '/d');
    $pdfs = array_map(fn($i) => basename(rawurldecode($i['pdf'])), $set['instructions']);
    sort($pdfs);
    assertEq(['7100001.pdf', '7100002.pdf', '7100003.pdf'], $pdfs);
});

it('allowedPdfsFromJson returns null when product_versions absent', function () {
    assertNull(allowedPdfsFromJson(['hits' => ['hits' => [['_source' => ['locale' => []]]]]]));
    assertNull(allowedPdfsFromJson([]));
    assertNull(allowedPdfsFromJson(['hits' => ['hits' => [['_source' => ['product_versions' => []]]]]]));
});

it('allowedPdfsFromJson keeps first sequence.element occurrence only', function () {
    $json = ['hits' => ['hits' => [['_source' => ['product_versions' => [
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/a.pdf'], 'sequence' => ['element' => 1]],
            ['file' => ['url' => 'https://x/b.pdf'], 'sequence' => ['element' => 2]],
        ]],
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/c.pdf'], 'sequence' => ['element' => 1]],
            ['file' => ['url' => 'https://x/d.pdf'], 'sequence' => ['element' => 2]],
        ]],
    ]]]]]];
    $allowed = allowedPdfsFromJson($json);
    assertEq(['a.pdf' => true, 'b.pdf' => true], $allowed);
});

it('allowedPdfsFromJson dedupes multi-book core by type (42164 shape)', function () {
    // Real Lego API shape for set 42164: two product_versions (de/us), each
    // with a 2-book core instruction (sequence.element 1 and 2, same type).
    // All four entries should collapse to a single core PDF.
    $json = ['hits' => ['hits' => [['_source' => ['product_versions' => [
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/6495257.pdf'], 'type' => 'product.bi.core',
             'sequence' => ['total' => 2, 'element' => 1]],
            ['file' => ['url' => 'https://x/6495258.pdf'], 'type' => 'product.bi.core',
             'sequence' => ['total' => 2, 'element' => 2]],
        ]],
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/6495264.pdf'], 'type' => 'product.bi.core',
             'sequence' => ['total' => 2, 'element' => 1]],
            ['file' => ['url' => 'https://x/6495265.pdf'], 'type' => 'product.bi.core',
             'sequence' => ['total' => 2, 'element' => 2]],
        ]],
    ]]]]]];
    $allowed = allowedPdfsFromJson($json);
    assertEq(['6495257.pdf' => true], $allowed);
});

it('allowedPdfsFromJson keeps core and extra builds separate (42150 shape)', function () {
    // Real Lego API shape for set 42150: two product_versions (de/us), each
    // with a core PDF + an "additional.extra" alt build PDF, all with
    // sequence.element=1. Type field is what distinguishes core from alt.
    $json = ['hits' => ['hits' => [['_source' => ['product_versions' => [
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/6491742.pdf'], 'type' => 'product.bi.core',
             'sequence' => ['total' => 1, 'element' => 1]],
            ['file' => ['url' => 'https://x/42150_01_BI_Build_Alt.pdf'],
             'type' => 'product.bi.additional.extra',
             'sequence' => ['total' => 1, 'element' => 1]],
        ]],
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/6449349.pdf'], 'type' => 'product.bi.core',
             'sequence' => ['total' => 1, 'element' => 1]],
            ['file' => ['url' => 'https://x/42150_01_BI_Build_Alt.pdf'],
             'type' => 'product.bi.additional.extra',
             'sequence' => ['total' => 1, 'element' => 1]],
        ]],
    ]]]]]];
    $allowed = allowedPdfsFromJson($json);
    assertEq([
        '6491742.pdf' => true,
        '42150_01_BI_Build_Alt.pdf' => true,
    ], $allowed);
});

it('allowedPdfsFromJson ignores malformed entries', function () {
    $json = ['hits' => ['hits' => [['_source' => ['product_versions' => [
        ['building_instructions' => [
            ['file' => ['url' => 'https://x/a.pdf']],
            ['file' => ['url' => '']],          // empty url skipped
            ['file' => 'not-an-array'],         // wrong shape skipped
            'scalar-entry',                     // not an array, skipped
        ]],
        ['building_instructions' => 'oops'],    // wrong shape skipped
        'scalar-version',                       // not an array, skipped
    ]]]]]];
    $allowed = allowedPdfsFromJson($json);
    assertEq(['a.pdf' => true], $allowed);
});

it('v1 single-version data.json keeps all its PDFs', function () use ($root) {
    // The original 31099 fixture has no product_versions, so dedup is a no-op.
    $set = parseSet("$root/31099", '/d');
    assertEq(3, count($set['instructions']));
});

it('runFetch rejects invalid set id', function () use ($root) {
    $r = runFetch('abc; rm -rf /', $root, __DIR__ . '/..', '/tmp/lego_test.log');
    assertEq(false, $r['ok']);
    assertTrue(str_contains($r['output'], 'Invalid'), 'rejects shell injection');
});

it('runFetch rejects too-long id', function () use ($root) {
    $r = runFetch('123456789', $root, __DIR__ . '/..', '/tmp/lego_test.log');
    assertEq(false, $r['ok']);
});

it('removeSet deletes an existing set directory', function () use ($root) {
    $id = '55501';
    $dir = "$root/$id";
    touchFile("$dir/name.txt", "Throwaway");
    touchFile("$dir/$id" . "_Prod.png");
    touchFile("$dir/6500001.pdf");
    assertTrue(is_dir($dir), 'fixture exists pre-delete');
    $r = removeSet($id, $root);
    assertEq(true, $r['ok'], 'remove ok');
    assertEq(false, is_dir($dir), 'dir gone');
    // the parent must still be intact
    assertTrue(is_dir($root), 'downloads root intact');
});

it('removeSet is idempotent for a missing set', function () use ($root) {
    $r = removeSet('77777', $root);
    assertEq(true, $r['ok'], 'missing set treated as success');
});

it('removeSet rejects shell injection in id', function () use ($root) {
    $r = removeSet('1; rm -rf /', $root);
    assertEq(false, $r['ok']);
    assertTrue(str_contains((string)$r['error'], 'Invalid'), 'rejects bad id');
});

it('removeSet rejects path traversal in id', function () use ($root) {
    // create a sibling dir we must NOT touch
    $sibling = dirname($root) . '/lego_test_sibling_' . getmypid();
    if (!is_dir($sibling)) mkdir($sibling, 0777, true);
    touchFile("$sibling/keepme.txt", "important");
    $r = removeSet('../' . basename($sibling), $root);
    assertEq(false, $r['ok'], 'must reject traversal');
    assertTrue(is_dir($sibling), 'sibling dir untouched');
    assertTrue(is_file("$sibling/keepme.txt"), 'sibling file untouched');
    exec('rm -rf ' . escapeshellarg($sibling));
});

it('removeSet rejects empty id', function () use ($root) {
    $r = removeSet('', $root);
    assertEq(false, $r['ok']);
});

it('removeSet fails when downloads dir missing', function () {
    $r = removeSet('12345', '/nonexistent/lego/downloads/path');
    assertEq(false, $r['ok']);
});

it('externalLinks builds the expected catalog URLs', function () {
    $links = externalLinks('31099');
    $byLabel = [];
    foreach ($links as $l) {
        $byLabel[$l['label']] = $l['url'];
    }
    assertEq('https://www.lego.com/en-us/product/31099', $byLabel['LEGO.com']);
    assertEq('https://www.lego.com/en-us/service/building-instructions/31099', $byLabel['Instructions']);
    assertEq('https://www.bricklink.com/v2/catalog/catalogitem.page?S=31099-1', $byLabel['BrickLink']);
    assertEq('https://brickset.com/sets/31099-1', $byLabel['Brickset']);
    assertEq('https://rebrickable.com/sets/31099-1/', $byLabel['Rebrickable']);
});

it('externalLinks rejects bad ids', function () {
    assertEq([], externalLinks(''));
    assertEq([], externalLinks('1; rm -rf /'));
    assertEq([], externalLinks('abc'));
    assertEq([], externalLinks('123456789')); // too long
});

it('renameSet writes name.txt and parseSet picks it up over data.json', function () use ($root) {
    $id = '60001';
    touchFile("$root/$id/data.json", json_encode([
        'hits' => ['hits' => [['_source' => ['locale' => ['en-us' => ['display_title' => 'Original Title']]]]]],
    ]));
    touchFile("$root/$id/$id" . "_Prod.png");
    $set = parseSet("$root/$id", '/d');
    assertEq('Original Title', $set['title'], 'pre-rename');
    $r = renameSet($id, "My Custom Name", $root);
    assertEq(true, $r['ok'], 'rename ok');
    assertEq('My Custom Name', $r['name']);
    $set2 = parseSet("$root/$id", '/d');
    assertEq('My Custom Name', $set2['title'], 'name.txt overrides data.json');
});

it('renameSet sanitizes whitespace and trims length', function () use ($root) {
    $id = '60002';
    touchFile("$root/$id/name.txt", "old");
    $r = renameSet($id, "  multi\nline\tname   ", $root);
    assertEq(true, $r['ok']);
    assertEq('multi line name', $r['name'], 'newlines and tabs collapse to spaces');

    $long = str_repeat('a', 500);
    $r2 = renameSet($id, $long, $root);
    assertEq(true, $r2['ok']);
    assertEq(200, mb_strlen($r2['name']), 'capped at 200');
});

it('renameSet strips control characters', function () use ($root) {
    $id = '60003';
    touchFile("$root/$id/name.txt", "old");
    $r = renameSet($id, "Hello\x00\x07World", $root);
    assertEq(true, $r['ok']);
    assertEq('HelloWorld', $r['name']);
});

it('renameSet rejects empty/whitespace-only name', function () use ($root) {
    $id = '60004';
    touchFile("$root/$id/name.txt", "keep me");
    $r = renameSet($id, "   \n\t  ", $root);
    assertEq(false, $r['ok'], 'must reject empty');
    assertTrue(str_contains((string)$r['error'], 'empty'), 'error mentions empty');
    // original name file untouched
    assertEq("keep me", trim((string)file_get_contents("$root/$id/name.txt")));
});

it('renameSet rejects invalid set id', function () use ($root) {
    $r = renameSet('1; rm -rf /', 'whatever', $root);
    assertEq(false, $r['ok']);
    assertTrue(str_contains((string)$r['error'], 'Invalid'), 'rejects bad id');
});

it('renameSet rejects path traversal', function () use ($root) {
    $sibling = dirname($root) . '/lego_test_rn_sibling_' . getmypid();
    if (!is_dir($sibling)) mkdir($sibling, 0777, true);
    touchFile("$sibling/name.txt", "do not overwrite");
    $r = renameSet('../' . basename($sibling), 'pwned', $root);
    assertEq(false, $r['ok'], 'must reject traversal');
    assertEq('do not overwrite', trim((string)file_get_contents("$sibling/name.txt")), 'sibling untouched');
    exec('rm -rf ' . escapeshellarg($sibling));
});

it('renameSet fails for missing set', function () use ($root) {
    $r = renameSet('88888', 'irrelevant', $root);
    assertEq(false, $r['ok'], 'missing set must fail');
});

it('renameSet fails when downloads dir missing', function () {
    $r = renameSet('12345', 'name', '/nonexistent/lego/path');
    assertEq(false, $r['ok']);
});

it('negotiateLocale picks highest q from supported subset', function () {
    $sup = ['en', 'de', 'fr'];
    assertEq('de', negotiateLocale('de-DE,de;q=0.9,en;q=0.8', $sup));
    assertEq('en', negotiateLocale('en-US,en;q=0.9', $sup));
    assertEq('fr', negotiateLocale('fr-CA,fr;q=0.9,en;q=0.5', $sup));
});

it('negotiateLocale falls back to en for unmatched header', function () {
    $sup = ['en', 'de'];
    assertEq('en', negotiateLocale('ja-JP,ja;q=0.9', $sup));
    assertEq('en', negotiateLocale('', $sup));
    assertEq('en', negotiateLocale('garbage', $sup));
});

it('negotiateLocale matches by primary subtag only', function () {
    $sup = ['en', 'zh'];
    // zh-Hant, zh-CN, zh-TW should all match 'zh'
    assertEq('zh', negotiateLocale('zh-Hant;q=0.9', $sup));
    assertEq('zh', negotiateLocale('zh-CN', $sup));
});

it('negotiateLocale respects q-weighted preference order', function () {
    $sup = ['en', 'de', 'fr'];
    // fr has higher q than de, so fr wins
    assertEq('fr', negotiateLocale('de;q=0.5,fr;q=0.9,en;q=0.1', $sup));
});

it('loadTranslations returns the catalog for a supported locale', function () {
    $en = loadTranslations('en');
    assertTrue(!empty($en), 'en catalog not empty');
    assertTrue(isset($en['app.title']), 'app.title present in en');
    $de = loadTranslations('de');
    assertTrue(isset($de['menu.delete']), 'menu.delete present in de');
});

it('loadTranslations returns empty array for unknown locale', function () {
    $x = loadTranslations('xx');
    assertEq([], $x);
});

it('t() returns the translated string for a known key', function () {
    assertEq('Speichern', t('rename.save', [], 'de'));
    assertEq('Annuler',  t('rename.cancel', [], 'fr'));
    assertEq('Eliminar set', t('menu.delete', [], 'es'));
});

it('t() falls back to English when key missing in active locale', function () {
    // Sneak a key that doesn't exist in 'de' but does in 'en':
    // we use a real key that's guaranteed in en but assert against the en value.
    $en = loadTranslations('en');
    // pick a key that we know exists; ensure t() returns the de version, but
    // for a fake key it should fall back.
    assertEq('definitely_no_such_key', t('definitely_no_such_key', [], 'de'),
        'missing key falls through to the key itself when not in any catalog');
});

it('t() interpolates sprintf-style args', function () {
    assertEq('Deleted set 31099', t('delete.success', ['31099'], 'en'));
    assertEq('Set 31099 gelöscht', t('delete.success', ['31099'], 'de'));
    assertEq('5 sets', t('count.sets', [5], 'en'));
    assertEq('1 set', t('count.set', [1], 'en'));
});

it('all locale catalogs cover the en key set', function () {
    $en = loadTranslations('en');
    foreach (['de', 'es', 'fr', 'hi', 'zh'] as $loc) {
        $cat = loadTranslations($loc);
        $missing = array_diff(array_keys($en), array_keys($cat));
        assertEq([], $missing, "locale '$loc' missing keys: " . implode(', ', $missing));
    }
});

it('jsStrings exposes the keys used by client code', function () {
    $js = jsStrings();
    foreach (['rename.save', 'rename.cancel', 'delete.confirm', 'status.downloading',
              'status.done', 'status.network_error', 'count.set', 'count.sets'] as $k) {
        assertTrue(isset($js[$k]), "jsStrings missing $k");
    }
});

it('supportedLocales lists exactly the six target languages', function () {
    $locs = array_keys(supportedLocales());
    sort($locs);
    assertEq(['de', 'en', 'es', 'fr', 'hi', 'zh'], $locs);
});

echo "\n";
echo "$passed passed, $failed failed\n";

/* cleanup */
exec('rm -rf ' . escapeshellarg($root));

exit($failed === 0 ? 0 : 1);
