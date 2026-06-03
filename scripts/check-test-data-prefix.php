<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * Guard for the QA test-data convention (plan §5 / AGENTS.md Testing Rule 5):
 *
 *   "All automated test data must use the prefix qa_ / qa- / QA. Tests must
 *    never create, update, or delete non-QA-prefixed business records."
 *
 *   composer test:check-data          # ratchet mode (default, used in CI)
 *   composer test:check-data -- --all # strict mode: also fail the legacy files
 *
 * It scans tests/ for the high-signal violations that mean "this test invents
 * non-QA business data or logs in with placeholder credentials":
 *
 *   A. placeholder auth emails (@example.com / @example.org / @test.com).
 *      QA personas use @selfhelp.test, so any example.com email in a test is
 *      either a created non-QA user or a hardcoded login.
 *   B. hardcoded weak dev passwords (admin123, testpassword123, ...).
 *   C. record-creation identifiers ('keyword' / 'url' / 'generated_id' => '...')
 *      whose literal value is not qa-prefixed. Read lookups (findOneBy/findBy)
 *      and string concatenations are intentionally NOT flagged — reading the
 *      system baseline is allowed (plan §4).
 *
 * Ratchet: files in LEGACY_ALLOWLIST are pre-existing tech debt. Their
 * violations are reported as warnings but do NOT fail the build, so the gate
 * is green today. Migrating such a test to the QA convention means deleting it
 * from the allowlist — the list only ever shrinks.
 */

$projectDir = dirname(__DIR__);
$testsDir = $projectDir . '/tests';

$strict = in_array('--all', array_slice($argv, 1), true);

/**
 * Pre-existing tests that predate the QA convention. Paths are relative to the
 * repository root and use forward slashes. Remove an entry once its test is
 * migrated to qa_-prefixed data + QA personas.
 *
 * The list is intentionally EMPTY: every formerly-legacy test now uses QA
 * personas / qa_-prefixed data. The ratchet only ever shrinks — do NOT add new
 * entries to silence a warning; migrate the test data instead.
 */
const LEGACY_ALLOWLIST = [
];

/** Hardcoded credentials that must never appear in test code. */
const WEAK_PASSWORDS = [
    'admin123', 'user123', 'editor123', 'guest123',
    'password123', 'secret123', 'test123', 'testpassword123',
];

if (!is_dir($testsDir)) {
    fwrite(STDERR, "check-test-data-prefix: tests/ directory not found at {$testsDir}\n");
    exit(1);
}

/** @return list<string> absolute paths to every *.php under tests/ */
function phpFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * @return list<array{line:int, message:string}>
 */
function scanFile(string $path): array
{
    $violations = [];
    $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

    foreach ($lines as $index => $line) {
        $lineNo = $index + 1;

        // Rule A: placeholder auth emails.
        if (preg_match('/[\w.+-]*@(?:example\.(?:com|org)|test\.com)/i', $line, $m) === 1) {
            $violations[] = [
                'line' => $lineNo,
                'message' => sprintf('placeholder email "%s" — use a QA persona (@selfhelp.test)', $m[0]),
            ];
        }

        // Rule B: hardcoded weak passwords.
        foreach (WEAK_PASSWORDS as $weak) {
            if (preg_match('/[\'"]' . preg_quote($weak, '/') . '[\'"]/', $line) === 1) {
                $violations[] = [
                    'line' => $lineNo,
                    'message' => sprintf('hardcoded password "%s" — use QaBaselineFixture::QA_PASSWORD', $weak),
                ];
            }
        }

        // Rule C: non-qa record-creation identifiers (skip reads + concatenation).
        if (preg_match('/findOneBy|findBy|->find\(/', $line) === 1) {
            continue;
        }
        if (preg_match_all('/[\'"](keyword|url|generated_id)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*(\.)?/', $line, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[2];
                $isConcatenation = ($match[3] ?? '') === '.';
                if ($isConcatenation) {
                    continue; // value is built from a variable, not a fixed literal
                }
                $normalized = ltrim($value, '/');
                if (stripos($normalized, 'qa') !== 0) {
                    $violations[] = [
                        'line' => $lineNo,
                        'message' => sprintf('non-QA %s "%s" — created test data must be qa_/qa-/QA prefixed', $key, $value),
                    ];
                }
            }
        }
    }

    return $violations;
}

$enforcedFailures = 0;
$legacyWarnings = 0;

foreach (phpFiles($testsDir) as $absolute) {
    $relative = str_replace('\\', '/', substr($absolute, strlen($projectDir) + 1));
    $violations = scanFile($absolute);
    if ($violations === []) {
        continue;
    }

    $isLegacy = in_array($relative, LEGACY_ALLOWLIST, true) && !$strict;
    $label = $isLegacy ? 'WARN (legacy)' : 'FAIL';

    foreach ($violations as $violation) {
        fwrite(
            $isLegacy ? STDOUT : STDERR,
            sprintf("%s %s:%d  %s\n", $label, $relative, $violation['line'], $violation['message'])
        );
        if ($isLegacy) {
            $legacyWarnings++;
        } else {
            $enforcedFailures++;
        }
    }
}

fwrite(STDOUT, "\ncheck-test-data-prefix: ");
if ($enforcedFailures > 0) {
    fwrite(STDOUT, sprintf("%d violation(s) in non-legacy tests.\n", $enforcedFailures));
    fwrite(STDOUT, "Fix the test data to use qa_/qa-/QA prefixes and QA personas.\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "OK. %d enforced violations, %d legacy warning(s)%s.\n",
    $enforcedFailures,
    $legacyWarnings,
    $strict ? ' (strict mode: legacy allowlist ignored)' : ''
));
exit(0);
