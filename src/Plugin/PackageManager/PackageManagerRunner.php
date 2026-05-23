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
        $result = $this->run(
            ['composer', 'require', sprintf('%s:%s', $package, $constraint), '--no-interaction', '--no-scripts'],
            $onLine,
        );
        if ($result->success) {
            $this->refreshComposerAutoloader();
        }
        return $result;
    }

    /**
     * Registers a temporary composer repository (vcs / path / composer)
     * before requiring the package, and rolls the repo config back on
     * failure so a half-completed install does not leave a stale repo
     * pointer in composer.json.
     *
     * Repository shape (Phase 1):
     *   ['type' => 'vcs'|'path'|'composer'|'git', 'url' => '<url>',
     *    'reference' => '<git-ref>'(optional)]
     *
     * Phase 2a adds an `options` map serialised via Composer's JSON
     * repository form. The primary use case is `path` repos for
     * standalone .shplugin installs, where we want
     * `{"symlink": false}` to copy the package into vendor/ rather
     * than symlink it — symlinks are fragile across the promoter's
     * atomic-rename cycle and can leave Composer pointing at a deleted
     * staging dir after a failed promotion.
     *
     * @param array{
     *     type:string,
     *     url:string,
     *     reference?:string,
     *     options?:array<string,bool|int|string>
     * }|null $repository
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
            $options = (isset($repository['options']) && is_array($repository['options']))
                ? $repository['options']
                : [];

            if ($options !== []) {
                // Composer's JSON repo form lets us pass arbitrary
                // options (most notably {symlink:false} for path repos
                // and {ssh2:{...}} for VCS auth). Encode the whole
                // descriptor as a single JSON arg and use
                // `composer config --json repositories.<slug> '<json>'`.
                $json = json_encode(
                    array_merge(['type' => $type, 'url' => $url], ['options' => $options]),
                    JSON_UNESCAPED_SLASHES,
                );
                if ($json === false) {
                    return new PackageManagerResult(
                        command: 'composer config repositories.' . $repoSlug,
                        exitCode: -1,
                        stdout: '',
                        stderr: 'Failed to JSON-encode composer repository descriptor.',
                        success: false,
                    );
                }
                $configArgs = ['composer', 'config', '--json', 'repositories.' . $repoSlug, $json];
            } else {
                $configArgs = ['composer', 'config', 'repositories.' . $repoSlug, $type, $url];
            }
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

        if ($requireResult->success) {
            $this->refreshComposerAutoloader();
        }

        return $requireResult;
    }

    /**
     * @param (callable(string,string):void)|null $onLine
     */
    public function removeComposerPackage(string $package, ?callable $onLine = null): PackageManagerResult
    {
        $result = $this->run(['composer', 'remove', $package, '--no-interaction', '--no-scripts'], $onLine);
        // No autoloader refresh on remove: the classes already loaded by
        // the worker would still be reachable from PHP's class table even
        // after vendor/ is updated. Refreshing would not unload them.
        return $result;
    }

    /**
     * After a successful `composer require`, Composer rewrites
     * `vendor/composer/autoload_classmap.php`, `autoload_psr4.php`, and
     * `autoload_namespaces.php` on disk, but the long-running Messenger
     * worker is still using the in-memory `Composer\Autoload\ClassLoader`
     * registered at process boot. Symfony's
     * `PluginInstaller::finalize()` calls `class_exists($bundleClass)`
     * immediately after `composer require`, and without this refresh
     * step the call returns `false` for the newly installed bundle —
     * even though the autoload files on disk are correct.
     *
     * This method re-includes the regenerated autoload maps and merges
     * them into the active classloader so the worker can resolve
     * newly installed classes without restarting. It is intentionally
     * defensive: missing autoload files or a stale loader simply
     * short-circuits with a `false` return; the install flow's
     * `class_exists` check will still report a clear error if the
     * underlying composer step actually failed.
     */
    private function refreshComposerAutoloader(): bool
    {
        $loader = $this->findComposerClassLoader();
        if ($loader === null) {
            return false;
        }
        $vendorDir = $this->projectDir . '/vendor';
        $classmapPath = $vendorDir . '/composer/autoload_classmap.php';
        if (is_file($classmapPath)) {
            $classmap = require $classmapPath;
            if (is_array($classmap)) {
                /** @var array<string, string> $typedClassmap */
                $typedClassmap = array_filter(
                    $classmap,
                    static fn ($value, $key): bool => is_string($key) && is_string($value),
                    ARRAY_FILTER_USE_BOTH,
                );
                $loader->addClassMap($typedClassmap);
            }
        }
        $psr4Path = $vendorDir . '/composer/autoload_psr4.php';
        if (is_file($psr4Path)) {
            $psr4 = require $psr4Path;
            if (is_array($psr4)) {
                foreach ($psr4 as $prefix => $paths) {
                    if (is_string($prefix) && (is_string($paths) || is_array($paths))) {
                        /** @var list<string>|string $paths */
                        $loader->setPsr4($prefix, $paths);
                    }
                }
            }
        }
        $namespacesPath = $vendorDir . '/composer/autoload_namespaces.php';
        if (is_file($namespacesPath)) {
            $namespaces = require $namespacesPath;
            if (is_array($namespaces)) {
                foreach ($namespaces as $prefix => $paths) {
                    if (is_string($prefix) && (is_string($paths) || is_array($paths))) {
                        /** @var list<string>|string $paths */
                        $loader->set($prefix, $paths);
                    }
                }
            }
        }
        $filesPath = $vendorDir . '/composer/autoload_files.php';
        if (is_file($filesPath)) {
            $files = require $filesPath;
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_string($file) && is_file($file)) {
                        require_once $file;
                    }
                }
            }
        }
        return true;
    }

    private function findComposerClassLoader(): ?\Composer\Autoload\ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $registered) {
            if (is_array($registered) && isset($registered[0]) && $registered[0] instanceof \Composer\Autoload\ClassLoader) {
                return $registered[0];
            }
        }
        return null;
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
