<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

$projectDir = realpath(__DIR__ . '/..');
if ($projectDir === false) {
    fwrite(STDERR, "Cannot resolve project root from " . __DIR__ . "\n");
    exit(2);
}

$adminEmail = getenv('SELFHELP_TEST_ADMIN_EMAIL') ?: 'admin@unibe.ch';
$adminName = getenv('SELFHELP_TEST_ADMIN_NAME') ?: 'Admin';
$adminPassword = getenv('SELFHELP_TEST_ADMIN_PASSWORD') ?: 'admin';

echo "Cleaning generated plugin state before Symfony boots...\n";
removePath($projectDir . '/config/selfhelp_plugin_bundles.php');
removePath($projectDir . '/config/selfhelp_plugin_bundles.php.tmp');
removePath($projectDir . '/selfhelp.plugins.lock.json');
removePath($projectDir . '/selfhelp.plugins.lock.json.tmp');
removePath($projectDir . '/selfhelp.plugins.lock.json.bak');
removePath($projectDir . '/var/plugin_safe_mode.lock');
removePath($projectDir . '/var/plugin-composer');
removePath($projectDir . '/var/plugins');
removePath($projectDir . '/public/plugin-artifacts');
removePath($projectDir . '/var/cache');

$php = PHP_BINARY;

run([$php, 'bin/console', 'doctrine:database:drop', '--force', '--if-exists'], $projectDir);
run([$php, 'bin/console', 'doctrine:database:create'], $projectDir);
run([$php, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $projectDir);
run([$php, 'bin/console', 'app:create-admin-user', $adminEmail, $adminName, '--password=' . $adminPassword], $projectDir);
run([$php, 'bin/console', 'cache:pool:clear', '--all'], $projectDir, allowFailure: true);
run([$php, 'bin/console', 'cache:clear'], $projectDir);

echo "\nFresh test install ready. Admin user: {$adminEmail}\n";

/**
 * @param list<string> $command
 */
function run(array $command, string $cwd, bool $allowFailure = false): void
{
    echo "\n> " . implode(' ', array_map('quoteForDisplay', $command)) . "\n";

    $process = proc_open(
        $command,
        [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
        $cwd
    );

    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start command.\n");
        exit(1);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        if ($allowFailure) {
            fwrite(STDERR, "Command failed with exit code {$exitCode}; continuing.\n");
            return;
        }
        fwrite(STDERR, "Command failed with exit code {$exitCode}.\n");
        exit($exitCode);
    }
}

function removePath(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        if (!@unlink($path) && file_exists($path)) {
            fwrite(STDERR, "Failed to remove file: {$path}\n");
            exit(1);
        }
        echo "Removed {$path}\n";
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            if (!@rmdir($itemPath) && is_dir($itemPath)) {
                fwrite(STDERR, "Failed to remove directory: {$itemPath}\n");
                exit(1);
            }
        } elseif (!@unlink($itemPath) && file_exists($itemPath)) {
            fwrite(STDERR, "Failed to remove file: {$itemPath}\n");
            exit(1);
        }
    }

    if (!@rmdir($path) && is_dir($path)) {
        fwrite(STDERR, "Failed to remove directory: {$path}\n");
        exit(1);
    }

    echo "Removed {$path}\n";
}

function quoteForDisplay(string $value): string
{
    if (preg_match('~^[A-Za-z0-9_@%+=:,./\\\\-]+$~', $value) === 1) {
        return $value;
    }

    return "'" . str_replace("'", "'\\''", $value) . "'";
}
