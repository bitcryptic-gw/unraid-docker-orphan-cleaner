<?php
declare(strict_types=1);

/**
 * Fixture test for Request::isRealAction()/isDryRun(), the fail-safe gate that
 * decides whether a destructive action runs for real.
 *
 * Run: php tests/request_test.php
 */

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Request.php';

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

$cases = [
    'missing dryRun'          => [[], true],
    'null dryRun'             => [['dryRun' => null], true],
    'true dryRun'             => [['dryRun' => true], true],
    'explicit false dryRun'   => [['dryRun' => false], false],
    'string "false"'          => [['dryRun' => 'false'], true],
    'string "0"'              => [['dryRun' => '0'], true],
    'integer 0'               => [['dryRun' => 0], true],
    'integer 1'               => [['dryRun' => 1], true],
    'empty array dryRun'      => [['dryRun' => []], true],
    'other keys only'         => [['ids' => ['sha256:' . str_repeat('a', 64)]], true],
];

foreach ($cases as $label => $case) {
    [$body, $expectedDryRun] = $case;
    check(Request::isDryRun($body) === $expectedDryRun, "{$label} => dryRun=" . ($expectedDryRun ? 'true' : 'false'));
    check(Request::isRealAction($body) === !$expectedDryRun, "{$label} => realAction=" . ($expectedDryRun ? 'false' : 'true'));
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed\n");
    exit(1);
}
echo "\nAll request fail-safe checks passed\n";
exit(0);
