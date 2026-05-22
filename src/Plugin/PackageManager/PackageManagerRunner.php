<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\PackageManager;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper around `composer` and `npm` invocations used by the
 * plugin lifecycle.
 *
 * Two modes are supported:
 *
 *   - `dryRunComposer()` / `dryRunNpm()` — call the package manager
 *     with the same arguments as the real install but pass
 *     `--dry-run` and capture the output. Used by `PluginInstaller`
 *     and `PluginUpdater` so the proposed install can be logged into
 *     `plugin_operations.logs_json` before any real install touches
 *     the filesystem.
 *   - `requireComposerPackage()` / `installNpmPackage()` — perform the
 *     real install. Only allowed in `development` or `trusted`
 *     install modes; managed-mode workflows run these commands
 *     directly from CI so the host PHP process never executes
 *     composer.
 *
 * All commands run in the project root, time-limited to 5 minutes by
 * default, and return a structured `PackageManagerResult` so the
 * caller can attach the captured output to the operation row.
 *
 * The implementation deliberately keeps a minimal surface: it does not
 * try to manage lockfile churn, vendor reuse, or rollback semantics —
 * those concerns live in `PluginInstaller` / `PluginUpdater` and the
 * staged operation snapshots.
 */
final class PackageManagerRunner
{
    private const DEFAULT_TIMEOUT = 300;

    public function __construct(
        private readonly string $projectDir,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ) {
    }

    public function dryRunComposer(string $package, string $constraint, ?string $repositoryUrl = null): PackageManagerResult
    {
        $args = ['composer', 'require', sprintf('%s:%s', $package, $constraint), '--dry-run', '--no-interaction'];
        if ($repositoryUrl !== null) {
            array_push($args, '--repository', $repositoryUrl);
        }
        return $this->run($args);
    }

    public function dryRunNpm(string $package, string $constraint): PackageManagerResult
    {
        return $this->run(['npm', 'install', sprintf('%s@%s', $package, $constraint), '--dry-run']);
    }

    public function requireComposerPackage(string $package, string $constraint, ?string $repositoryUrl = null): PackageManagerResult
    {
        $args = ['composer', 'require', sprintf('%s:%s', $package, $constraint), '--no-interaction', '--no-scripts'];
        if ($repositoryUrl !== null) {
            array_push($args, '--repository', $repositoryUrl);
        }
        return $this->run($args);
    }

    public function installNpmPackage(string $package, string $constraint): PackageManagerResult
    {
        return $this->run(['npm', 'install', sprintf('%s@%s', $package, $constraint)]);
    }

    /**
     * @param list<string> $cmd
     */
    private function run(array $cmd): PackageManagerResult
    {
        $process = new Process($cmd, $this->projectDir, null, null, $this->timeoutSeconds);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return new PackageManagerResult(
                command: implode(' ', $cmd),
                exitCode: -1,
                stdout: '',
                stderr: $e->getMessage(),
                success: false,
            );
        }
        return new PackageManagerResult(
            command: implode(' ', $cmd),
            exitCode: $process->getExitCode() ?? -1,
            stdout: (string) $process->getOutput(),
            stderr: (string) $process->getErrorOutput(),
            success: $process->isSuccessful(),
        );
    }
}
