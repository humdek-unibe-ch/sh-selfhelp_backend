<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\PackageManager;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper around `composer` invocations used by the plugin
 * Messenger worker.
 *
 * Public surface:
 *
 *   - `requireComposerPackage($package, $constraint, ?$onLine)` —
 *     direct `composer require` (Packagist or pre-configured repo).
 *     Streams each stdout/stderr line into `$onLine` so the worker
 *     can append it to `plugin_operations.logs_json` in near real
 *     time.
 *
 *   - `requireComposerPackageFromRepository($package, $constraint, $repository, ?$onLine)` —
 *     registers a temporary `composer config repositories.<name>`
 *     entry (vcs / path / composer), runs `composer require`, and
 *     unregisters the repo on failure. The `<name>` defaults to a
 *     `plugin-<sanitised-package>` slug so two plugins requiring
 *     packages from different repos do not collide.
 *
 *   - `removeComposerPackage($package, ?$onLine)` — `composer remove`
 *     used by `PluginUninstaller`.
 *
 * All commands run in the project root, time-limited to 10 minutes by
 * default, and return a structured `PackageManagerResult`.
 */
final class PackageManagerRunner
{
    private const DEFAULT_TIMEOUT = 600;

    public function __construct(
        private readonly string $projectDir,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ) {
    }

    /**
     * @param (callable(string,string):void)|null $onLine receives ($line, $type) where $type is 'out'|'err'
     */
    public function requireComposerPackage(string $package, string $constraint, ?callable $onLine = null): PackageManagerResult
    {
        return $this->run(
            ['composer', 'require', sprintf('%s:%s', $package, $constraint), '--no-interaction', '--no-scripts'],
            $onLine,
        );
    }

    /**
     * Registers a temporary composer repository (vcs / path / composer)
     * before requiring the package, and rolls the repo config back on
     * failure so a half-completed install does not leave a stale repo
     * pointer in composer.json.
     *
     * @param array{type:string,url:string,reference?:string}|null $repository
     * @param (callable(string,string):void)|null                   $onLine
     */
    public function requireComposerPackageFromRepository(
        string $package,
        string $constraint,
        ?array $repository,
        ?callable $onLine = null,
    ): PackageManagerResult {
        $repoSlug = null;

        if ($repository !== null) {
            $type = (string) ($repository['type'] ?? '');
            $url = (string) ($repository['url'] ?? '');
            if ($type === '' || $url === '') {
                return new PackageManagerResult(
                    command: 'composer require ' . $package . ':' . $constraint,
                    exitCode: -1,
                    stdout: '',
                    stderr: 'Invalid composer repository: type and url are required.',
                    success: false,
                );
            }

            $repoSlug = 'plugin-' . preg_replace('/[^a-z0-9.-]+/i', '-', $package);
            $configArgs = ['composer', 'config', 'repositories.' . $repoSlug, $type, $url];
            $configResult = $this->run($configArgs, $onLine);
            if (!$configResult->success) {
                return $configResult;
            }
        }

        $constraintToUse = $constraint;
        if ($repository !== null && isset($repository['reference']) && is_string($repository['reference']) && $repository['reference'] !== '') {
            // VCS path references — append `#<ref>` so composer pins to the exact commit.
            $constraintToUse = sprintf('%s#%s', $constraint, $repository['reference']);
        }

        $requireResult = $this->run(
            ['composer', 'require', sprintf('%s:%s', $package, $constraintToUse), '--no-interaction', '--no-scripts'],
            $onLine,
        );

        if (!$requireResult->success && $repoSlug !== null) {
            // Roll back the temporary repository entry so a failed
            // install does not poison composer.json.
            $this->run(['composer', 'config', '--unset', 'repositories.' . $repoSlug], null);
        }

        return $requireResult;
    }

    /**
     * @param (callable(string,string):void)|null $onLine
     */
    public function removeComposerPackage(string $package, ?callable $onLine = null): PackageManagerResult
    {
        return $this->run(['composer', 'remove', $package, '--no-interaction', '--no-scripts'], $onLine);
    }

    /**
     * @param list<string>                       $cmd
     * @param (callable(string,string):void)|null $onLine
     */
    private function run(array $cmd, ?callable $onLine = null): PackageManagerResult
    {
        $process = new Process($cmd, $this->projectDir, null, null, $this->timeoutSeconds);
        $stdout = '';
        $stderr = '';
        try {
            if ($onLine !== null) {
                $stdoutBuffer = '';
                $stderrBuffer = '';
                $process->run(function (string $type, string $chunk) use (&$stdoutBuffer, &$stderrBuffer, &$stdout, &$stderr, $onLine): void {
                    if ($type === Process::OUT) {
                        $stdout .= $chunk;
                        $stdoutBuffer .= $chunk;
                        while (($pos = strpos($stdoutBuffer, "\n")) !== false) {
                            $line = substr($stdoutBuffer, 0, $pos);
                            $stdoutBuffer = substr($stdoutBuffer, $pos + 1);
                            $onLine(rtrim($line, "\r"), 'out');
                        }
                    } else {
                        $stderr .= $chunk;
                        $stderrBuffer .= $chunk;
                        while (($pos = strpos($stderrBuffer, "\n")) !== false) {
                            $line = substr($stderrBuffer, 0, $pos);
                            $stderrBuffer = substr($stderrBuffer, $pos + 1);
                            $onLine(rtrim($line, "\r"), 'err');
                        }
                    }
                });
                if ($stdoutBuffer !== '') {
                    $onLine($stdoutBuffer, 'out');
                }
                if ($stderrBuffer !== '') {
                    $onLine($stderrBuffer, 'err');
                }
            } else {
                $process->run();
                $stdout = (string) $process->getOutput();
                $stderr = (string) $process->getErrorOutput();
            }
        } catch (\Throwable $e) {
            return new PackageManagerResult(
                command: implode(' ', $cmd),
                exitCode: -1,
                stdout: $stdout,
                stderr: $stderr . $e->getMessage(),
                success: false,
            );
        }
        return new PackageManagerResult(
            command: implode(' ', $cmd),
            exitCode: $process->getExitCode() ?? -1,
            stdout: $stdout,
            stderr: $stderr,
            success: $process->isSuccessful(),
        );
    }
}
