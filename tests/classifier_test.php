<?php
declare(strict_types=1);

/**
 * Fixture-based test for the orphan classifier. Feeds canned /images/json and
 * /containers/json style payloads into Orphans::buildReport() and checks the
 * orphan membership and classification rules.
 *
 * Run: php tests/classifier_test.php
 */

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Orphans.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function id(string $char): string
{
    return 'sha256:' . str_repeat($char, 64);
}

function digest(string $repo, string $hexChar): string
{
    return $repo . '@sha256:' . str_repeat($hexChar, 64);
}

$now = 1_800_000_000;

$untaggedWithDigest = id('a');
$multiDigest        = id('b');
$tagged             = id('c');
$inUse              = id('d');
$plainDangling      = id('e');

$images = [
    // Untagged image that carries a digest (a superseded pull): must be listed
    // as untagged, not hidden.
    [
        'Id'          => $untaggedWithDigest,
        'RepoTags'    => [],
        'RepoDigests' => [digest('wordpress', '1')],
        'Created'     => $now - 30 * 86400,
        'Size'        => 111,
    ],
    // Several digests, still one row.
    [
        'Id'          => $multiDigest,
        'RepoTags'    => [],
        'RepoDigests' => [digest('mariadb', '2'), digest('mariadb', '3')],
        'Created'     => $now - 30 * 86400,
        'Size'        => 222,
    ],
    // Tagged image.
    [
        'Id'          => $tagged,
        'RepoTags'    => ['nginx:latest'],
        'RepoDigests' => [digest('nginx', '4')],
        'Created'     => $now - 30 * 86400,
        'Size'        => 333,
    ],
    // Used by a container: must not be listed.
    [
        'Id'          => $inUse,
        'RepoTags'    => ['inuse:latest'],
        'RepoDigests' => [digest('inuse', '5')],
        'Created'     => $now - 30 * 86400,
        'Size'        => 444,
    ],
    // Plain dangling image (no tags, no digests): untagged.
    [
        'Id'          => $plainDangling,
        'RepoTags'    => [],
        'RepoDigests' => [],
        'Created'     => $now - 1 * 86400,
        'Size'        => 555,
    ],
];

$referenced = [$inUse => true];

$report = Orphans::buildReport($images, $referenced, [], [], [], [], $now);

$byId = [];
$counts = [];
foreach ($report['orphans'] as $orphan) {
    $byId[$orphan['id']] = $orphan;
    $counts[$orphan['id']] = ($counts[$orphan['id']] ?? 0) + 1;
}

check(isset($byId[$untaggedWithDigest]) && $byId[$untaggedWithDigest]['class'] === 'untagged',
    'untagged image with digests is listed as untagged');
check(isset($byId[$untaggedWithDigest]) && $byId[$untaggedWithDigest]['preselect'] === true,
    'untagged image with digests is pre-selected');
check(($byId[$untaggedWithDigest]['digestLabel'] ?? '') === 'wordpress@sha256:111111111111',
    'untagged image shows a repository@shortdigest label');

check(isset($byId[$multiDigest]) && ($counts[$multiDigest] ?? 0) === 1,
    'image with several digests appears exactly once');
check(($byId[$multiDigest]['digestLabel'] ?? '') === 'mariadb@sha256:222222222222',
    'multi-digest label uses the first digest');

check(isset($byId[$tagged]) && $byId[$tagged]['class'] === 'tagged',
    'tagged image is listed as tagged');
check(isset($byId[$tagged]) && $byId[$tagged]['preselect'] === false,
    'tagged image is not pre-selected');

check(!isset($byId[$inUse]),
    'image used by a container is not listed');

check(isset($byId[$plainDangling]) && $byId[$plainDangling]['class'] === 'untagged',
    'plain dangling image is listed as untagged');

check($report['totals']['count'] === 4, 'exactly four orphans are reported');
check($report['totals']['classes']['untagged'] === 3, 'three untagged orphans');
check($report['totals']['classes']['tagged'] === 1, 'one tagged orphan');

// A pin on the multi-digest image's digest must be honoured (precedence 1).
$pinned = Orphans::buildReport($images, $referenced, [], [], [], ['mariadb@sha256:22222222222*'], $now);
$pinnedById = [];
foreach ($pinned['orphans'] as $orphan) {
    $pinnedById[$orphan['id']] = $orphan;
}
check(($pinnedById[$multiDigest]['class'] ?? '') === 'pinned',
    'untagged image matching a pin is classified pinned');

// SharedSize handling: present => unique = Size - SharedSize; -1 (or absent)
// => unique stays unknown so the UI falls back to Size.
$sharedSizes = [$untaggedWithDigest => 40, $multiDigest => -1];
$sized = Orphans::buildReport($images, $referenced, [], [], [], [], $now, $sharedSizes);
$sizedById = [];
foreach ($sized['orphans'] as $orphan) {
    $sizedById[$orphan['id']] = $orphan;
}
check(($sizedById[$untaggedWithDigest]['sharedSize'] ?? null) === 40
    && ($sizedById[$untaggedWithDigest]['uniqueSize'] ?? null) === 71,
    'uniqueSize = Size - SharedSize when SharedSize is present (111 - 40 = 71)');
check(array_key_exists('uniqueSize', $sizedById[$multiDigest]) && $sizedById[$multiDigest]['uniqueSize'] === null,
    'uniqueSize stays unknown when SharedSize is -1');
check(array_key_exists('uniqueSize', $sizedById[$tagged]) && $sizedById[$tagged]['uniqueSize'] === null,
    'uniqueSize stays unknown when SharedSize is missing');
check(($sized['totals']['uniqueSize'] ?? null) === 71,
    'unique total sums only rows with a known unique size');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed\n");
    exit(1);
}
echo "\nAll classifier checks passed\n";
exit(0);
