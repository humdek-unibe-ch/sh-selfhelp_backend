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
 * Every command runs with `cwd = var/plugin-composer/` and the
 * `COMPOSER` env pinned to `composer.json` so the host root is never
 * touched. After a successful `composer require` the freshly
 * regenerated PSR-4 / classmap is merged into the SECONDARY
 * `Composer\Autoload\ClassLoader` registered by
 * {@see PluginAutoloaderBootstrap} so `PluginInstaller::finalize()`'s
 * `class_exists($bundleClass)` gate succeeds without restarting the
 * worker.
 */
final class PackageManagerRunner
{
    private const DEFAULT_TIMEOUT = 600;

    private readonly PluginComposerRoot $composerRoot;

    public function __construct(
        private readonly string $projectDir,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ) {
        $this->composerRoot = new PluginComposerRoot($projectDir);
    }

    /**
     * @param (callable(string,string):void)|null $onLine receives ($line, $type) where $type is 'out'|'err'
     */
    public function requireComposerPackage(string $package, string $constraint, ?callable $onLine = null): PackageManagerResult
    {
        $result = $this->run(
            ['composer', 'require', sprintf('%s:%s', $package, $constraint), '--no-interaction', '--no-scripts', '--no-plugins'],
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
     * Repository shape:
     *   ['type' => 'vcs'|'path'|'composer'|'git', 'url' => '<url>',
     *    'reference' => '<git-ref>'(optional),
     *    'options' => ['symlink' => false, ...](optional)]
     *
     * The `options` map is serialised via Composer's JSON repository
     * form. The primary use case is `path` repos for standalone
     * .shplugin installs, where we want `{"symlink": false}` to copy
     * the package into vendor/ rather than symlink it — symlinks are
     * fragile across the promoter's atomic-rename cycle and can leave
     * Composer pointing at a deleted staging dir after a failed
     * promotion.
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
            $repository = $this->normaliseRepositoryForComposer($repository);
            $type = $repository['type'];
            $url = $repository['url'];
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
            $options = $repository['options'] ?? [];

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
        if ($repository !== null && isset($repository['reference']) && $repository['reference'] !== '') {
            // VCS path references — append `#<ref>` so composer pins to the exact commit.
            $constraintToUse = sprintf('%s#%s', $constraint, $repository['reference']);
        }

        $requireResult = $this->run(
            ['composer', 'require', sprintf('%s:%s', $package, $constraintToUse), '--no-interaction', '--no-scripts', '--no-plugins'],
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
     * Composer treats `type=vcs` GitHub URLs as API-backed GitHub
     * repositories and may fall back to `git@github.com:...` on API
     * rate-limit/auth failures. The plugin worker runs
     * non-interactively under service accounts where SSH host keys are
     * often unavailable, so prefer a plain `git` repository for public
     * GitHub HTTPS remotes.
     *
     * @param array{
     *     type:string,
     *     url:string,
     *     reference?:string,
     *     options?:array<string,bool|int|string>
     * } $repository
     * @return array{
     *     type:string,
     *     url:string,
     *     reference?:string,
     *     options?:array<string,bool|int|string>
     * }
     */
    private function normaliseRepositoryForComposer(array $repository): array
    {
        $type = $repository['type'];
        $url = $repository['url'];
        if ($type !== 'vcs' || $url === '') {
            return $repository;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (($host === 'github.com' || $host === 'www.github.com') && $scheme === 'https') {
            $repository['type'] = 'git';
        }

        return $repository;
    }

    /**
     * @param (callable(string,string):void)|null $onLine
     */
    public function removeComposerPackage(string $package, ?callable $onLine = null): PackageManagerResult
    {
        $result = $this->run(['composer', 'remove', $package, '--no-interaction', '--no-scripts', '--no-plugins'], $onLine);
        // No autoloader refresh on remove: the classes already loaded by
        // the worker would still be reachable from PHP's class table even
        // after vendor/ is updated. Refreshing would not unload them.
        return $result;
    }

    /**
     * After a successful `composer require`, Composer rewrites the
     * plugin root's autoload maps
     * (`var/plugin-composer/vendor/composer/autoload_*.php`), but the
     * long-running Messenger worker is still using the SECONDARY
     * in-memory `Composer\Autoload\ClassLoader` registered at process
     * boot by {@see PluginAutoloaderBootstrap}. Symfony's
     * `PluginInstaller::finalize()` calls `class_exists($bundleClass)`
     * immediately after `composer require`, and without this refresh
     * step the call returns `false` for the newly installed bundle —
     * even though the autoload files on disk are correct.
     *
     * This method re-includes the regenerated autoload maps from the
     * plugin vendor and merges them into the secondary classloader so
     * the worker can resolve newly installed plugin classes without
     * restarting. The host's primary autoloader is never touched.
     *
     * On the very first install the boot helper found no
     * `vendor/autoload.php` to require (the plugin root was empty).
     * In that case we lazily require the freshly generated autoload
     * here and stash the loader in the registry so subsequent installs
     * within the same worker process refresh the same instance.
     */
    private function refreshComposerAutoloader(): bool
    {
        $loader = $this->resolvePluginClassLoader();
        if ($loader === null) {
            return false;
        }
        $vendorDir = $this->composerRoot->vendorDir();
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
        $this->clearComposerMissingClassCache($loader);
        return true;
    }

    /**
     * Composer's ClassLoader keeps a per-process cache of classes it
     * already failed to resolve. During plugin install/update the worker
     * may have looked up the bundle class before `composer require`
     * completed, so simply merging the regenerated PSR-4/classmap files
     * is not enough â€” the loader can keep returning the stale miss.
     *
     * Clear only Composer's internal negative cache. This is much
     * smaller/safer than rebuilding the whole autoloader chain and is
     * enough for the immediate `class_exists($bundleClass)` gate in the
     * lifecycle finalizer.
     */
    private function clearComposerMissingClassCache(\Composer\Autoload\ClassLoader $loader): void
    {
        try {
            $ref = new \ReflectionObject($loader);
            if (!$ref->hasProperty('missingClasses')) {
                return;
            }

            $property = $ref->getProperty('missingClasses');
            $property->setAccessible(true);
            $property->setValue($loader, []);
        } catch (\Throwable) {
            // Best-effort only. If Composer changes the internal field
            // name in a future release, the refreshed PSR-4/classmap is
            // still applied and the next process boot will pick it up.
        }
    }

    /**
     * Returns the secondary plugin `ClassLoader` from
     * {@see PluginAutoloaderRegistry}. If the registry is empty (very
     * first install — the plugin vendor did not exist at boot), this
     * method requires the freshly generated `autoload.php`, registers
     * the returned loader at the tail of the SPL chain, and stashes
     * it for subsequent calls.
     */
    private function resolvePluginClassLoader(): ?\Composer\Autoload\ClassLoader
    {
        $loader = PluginAutoloaderRegistry::get();
        if ($loader !== null) {
            return $loader;
        }
        $autoloadPath = $this->composerRoot->autoloadPath();
        if (!is_file($autoloadPath)) {
            return null;
        }
        $maybeLoader = require $autoloadPath;
        if (!$maybeLoader instanceof \Composer\Autoload\ClassLoader) {
            return null;
        }
        // `autoload.php` already calls register() with prepend=true.
        // Re-register at the tail so host classes win on collision.
        $maybeLoader->unregister();
        $maybeLoader->register(false);
        PluginAutoloaderRegistry::set($maybeLoader);
        return $maybeLoader;
    }

    /**
     * Builds the env block passed to every composer subprocess.
     *
     * Symfony's `Process` defaults `$env` to `null` which is documented as
     * "inherit parent env" — but on Windows that inheritance is unreliable
     * once the PHP process was spawned by the built-in dev server / Symfony
     * CLI / a Messenger worker that lost its console env. Composer then
     * fatals with "The APPDATA or COMPOSER_HOME environment variable must
     * be set", killing every install/uninstall before the `plugins` row is
     * touched. We explicitly forward the variables Composer needs (and
     * fall back to a project-local Composer home when the host has neither
     * `APPDATA` nor `COMPOSER_HOME` available) so plugin install/uninstall
     * works regardless of how PHP was launched.
     *
     * @return array<string,string>
     */
    private function resolveSubprocessEnv(): array
    {
        $passthrough = [
            'APPDATA', 'LOCALAPPDATA', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR',
            'HOME', 'USERPROFILE', 'PATH', 'Path', 'SystemRoot', 'SYSTEMROOT',
            'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'PROCESSOR_ARCHITECTURE',
        ];
        $env = [];
        foreach ($passthrough as $var) {
            $value = getenv($var);
            if ($value === false || $value === '') {
                $value = $_SERVER[$var] ?? null;
            }
            if (is_string($value) && $value !== '') {
                $env[$var] = $value;
            }
        }

        if (!isset($env['APPDATA']) && !isset($env['COMPOSER_HOME'])) {
            $fallbackHome = $this->projectDir
                . DIRECTORY_SEPARATOR . 'var'
                . DIRECTORY_SEPARATOR . 'composer-home';
            if (!is_dir($fallbackHome)) {
                @mkdir($fallbackHome, 0775, true);
            }
            $env['COMPOSER_HOME'] = $fallbackHome;
        }

        // Pin the manifest filename Composer reads. Without this an
        // ambient `COMPOSER` env from the parent process could
        // redirect plugin operations to a different file (e.g. the
        // host's `composer.json` if someone exported it). The cwd
        // already points at the plugin root, so the bare filename is
        // resolved against the plugin root.
        $env['COMPOSER'] = 'composer.json';

        return $env;
    }

    /**
     * @param list<string>                       $cmd
     * @param (callable(string,string):void)|null $onLine
     */
    private function run(array $cmd, ?callable $onLine = null): PackageManagerResult
    {
        // Make sure the plugin Composer root + seeded composer.json
        // exist before every invocation. Idempotent.
        $this->composerRoot->ensure();
        $process = new Process($cmd, $this->composerRoot->rootDir(), $this->resolveSubprocessEnv(), null, $this->timeoutSeconds);
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
