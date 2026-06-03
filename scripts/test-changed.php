<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * Fast local feedback loop: run only the PHPUnit test files that changed
 * relative to origin/main (plus any uncommitted changes).
 *
 *   composer test:changed
 *
 * "Changed" = union of:
 *   - committed changes since the merge-base with origin/main
 *   - staged changes
 *   - unstaged working-tree changes
 *
 * Only files under tests/ ending in Test.php are run. If a source file changed
 * but no test file did, nothing is run (use `composer test` for the full tier).
 * This is intentionally conservative so the loop stays fast; the pre-push tier
 * (`composer test:release`) is the safety net.
 */

$projectDir = dirname(__DIR__);
chdir($projectDir);

/**
 * @return list<string>
 */
function gitLines(string $command): array
{
    $output = [];
    $exit = 0;
    exec($command . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'), $output, $exit);

    if ($exit !== 0) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $output), static fn (string $l): bool => $l !== ''));
}

// Resolve a diff base. Prefer the merge-base with origin/main; fall back to
// HEAD so the command still works on a fresh clone without a remote ref.
$base = 'HEAD';
$mergeBase = gitLines('git merge-base origin/main HEAD');
if ($mergeBase !== []) {
    $base = $mergeBase[0];
}

$changed = array_merge(
    gitLines(sprintf('git diff --name-only %s', escapeshellarg($base))),
    gitLines('git diff --name-only'),
    gitLines('git diff --name-only --cached'),
    // New, not-yet-committed test files (git diff does not list untracked files).
    gitLines('git ls-files --others --exclude-standard'),
);

$testFiles = [];
foreach (array_unique($changed) as $file) {
    if (preg_match('#^tests/.+Test\.php$#', $file) === 1 && is_file($projectDir . '/' . $file)) {
        $testFiles[$file] = $file;
    }
}
$testFiles = array_values($testFiles);

if ($testFiles === []) {
    fwrite(STDOUT, "test:changed — no changed test files under tests/**Test.php. Nothing to run.\n");
    exit(0);
}

fwrite(STDOUT, "test:changed — running " . count($testFiles) . " changed test file(s):\n");
foreach ($testFiles as $file) {
    fwrite(STDOUT, "  - {$file}\n");
}

$phpunit = $projectDir . '/bin/phpunit';
$command = array_merge([PHP_BINARY, $phpunit, '--testdox'], $testFiles);

$descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
$process = proc_open($command, $descriptors, $pipes, $projectDir);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start PHPUnit.\n");
    exit(1);
}

exit(proc_close($process));
