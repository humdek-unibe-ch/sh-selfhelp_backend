<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 *
 * Adds (checks / removes) the SPDX license header on every PHP source
 * file under the configured directories. The header text is read from
 * `header.txt` at the repo root and wrapped in a C-style block comment
 * placed immediately after the opening `<?php` tag.
 *
 * Usage:
 *   php scripts/add-license-headers.php             # add headers in place
 *   php scripts/add-license-headers.php --check     # exit 1 if any missing
 *   php scripts/add-license-headers.php --remove    # strip the SPDX block
 *   php scripts/add-license-headers.php --dry-run   # report what would change
 *
 * Detection: a file is considered "already headered" if it contains the
 * literal `SPDX-License-Identifier:` token anywhere in its body. That
 * matches both this tool's output and any pre-existing manual headers.
 *
 * Insertion is idempotent: running --add twice never duplicates a header.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve project root from " . __DIR__ . "\n");
    exit(2);
}

// ---- args ----
$action = 'add';
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    switch ($arg) {
        case '--check':   $action = 'check'; break;
        case '--remove':  $action = 'remove'; break;
        case '--add':     $action = 'add'; break;
        case '--dry-run': $dryRun = true; break;
        case '-h':
        case '--help':
            echo "Usage: php scripts/add-license-headers.php [--add|--check|--remove] [--dry-run]\n";
            exit(0);
        default:
            fwrite(STDERR, "Unknown argument: $arg\n");
            exit(2);
    }
}

// ---- header block ----
$headerFile = $root . '/header.txt';
if (!is_readable($headerFile)) {
    fwrite(STDERR, "header.txt not found at $headerFile\n");
    exit(2);
}
$lines = file($headerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) {
    fwrite(STDERR, "header.txt is empty\n");
    exit(2);
}
$blockLines = ['/*'];
foreach ($lines as $line) {
    $blockLines[] = ' * ' . rtrim($line);
}
$blockLines[] = ' */';
$block = implode("\n", $blockLines);

// ---- scan config ----
// Source-bearing directories. Symfony config/, public/, and migrations/
// often contain hand-written .php files we want to license too.
$includeDirs = ['src', 'tests', 'public', 'config', 'migrations', 'scripts'];
// Path fragments that disqualify a file (anywhere in its absolute path).
$excludeFragments = ['/vendor/', '/var/', '/node_modules/', '/.git/', '/cache/', '/.phpunit.cache/'];

// ---- collect files ----
$files = [];
foreach ($includeDirs as $rel) {
    $abs = $root . '/' . $rel;
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'php') continue;
        $path = str_replace('\\', '/', $file->getPathname());
        $skip = false;
        foreach ($excludeFragments as $f) {
            if (strpos($path, $f) !== false) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

// ---- regexes ----
$markerRe = '/SPDX-License-Identifier:\s*[\w.\-]+/';
// Matches a single C-style block comment that contains the SPDX
// identifier. Used by --remove. Conservative on purpose: only strips
// the very first such block, leaving any later in-source SPDX notice
// intact.
$blockRe = '/^\s*\/\*[\s\S]*?SPDX-License-Identifier:[\s\S]*?\*\/\s*\n*/m';
// Matches the opening `<?php` line, including any same-line trailing
// code (e.g. `<?php declare(strict_types=1);`). The header block is
// inserted on the next line.
$openTagRe = '/^<\?php[^\n]*\n/';

// ---- process ----
$missing = [];
$updated = 0;
$skipped = 0;

foreach ($files as $path) {
    $content = file_get_contents($path);
    if ($content === false) continue;
    $hasHeader = (bool) preg_match($markerRe, $content);

    if ($action === 'check') {
        if (!$hasHeader) $missing[] = $path;
        continue;
    }

    if ($action === 'remove') {
        if (!$hasHeader) {
            $skipped++;
            continue;
        }
        $newContent = preg_replace($blockRe, '', $content, 1);
        if ($newContent !== null && $newContent !== $content) {
            if (!$dryRun) file_put_contents($path, $newContent);
            $updated++;
        }
        continue;
    }

    // action === 'add'
    if ($hasHeader) {
        $skipped++;
        continue;
    }
    if (!preg_match($openTagRe, $content, $m)) {
        // Not a standard <?php-opened file (could be a HTML/Twig
        // fragment, or the file uses the short open tag). Skip.
        $skipped++;
        continue;
    }
    $offset = strlen($m[0]);
    $rest = substr($content, $offset);
    $newContent = substr($content, 0, $offset)
        . "\n" . $block . "\n\n"
        . ltrim($rest, "\n");
    if (!$dryRun) file_put_contents($path, $newContent);
    $updated++;
}

// ---- report ----
$total = count($files);
$rel = function (string $p) use ($root): string {
    $r = str_replace('\\', '/', $root);
    $f = str_replace('\\', '/', $p);
    return str_starts_with($f, $r . '/') ? substr($f, strlen($r) + 1) : $f;
};

if ($action === 'check') {
    if ($missing) {
        echo "Missing SPDX header in " . count($missing) . " file(s):\n";
        foreach ($missing as $p) echo "  - " . $rel($p) . "\n";
        exit(1);
    }
    echo "OK -- all $total PHP files have the SPDX header.\n";
    exit(0);
}

$verb = $action === 'remove' ? 'removed' : 'added';
$prefix = $dryRun ? '[dry-run] would have ' : '';
echo "{$prefix}{$verb} SPDX header in $updated file(s); skipped $skipped (already headered or not eligible); scanned $total.\n";
