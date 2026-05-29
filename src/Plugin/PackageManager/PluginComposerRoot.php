<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\PackageManager;

/**
 * Owns the on-disk shape of `var/plugin-composer/`.
 *
 * `var/plugin-composer/` is the isolated Composer root for plugin
 * packages. Plugin install/update/uninstall run `composer require|remove`
 * against it (cwd + `COMPOSER` env) so the host's `composer.json` /
 * `composer.lock` / `vendor/` stay untouched. The plugin root's
 * `vendor/autoload.php` is loaded as a SECONDARY `ClassLoader` at
 * boot — see {@see PluginAutoloaderRegistry} and the boot snippets in
 * `public/index.php`.
 *
 * `ensure()` is idempotent and creates the dir + seeded `composer.json`
 * the first time it is called. The seed materialises:
 *
 *   - `provide` for every host-provided package family
 *     (`symfony/*`, `doctrine/*`, `psr/*`, plus optional shared
 *     contracts) at the host's resolved version, so plugin
 *     `require` constraints validate against host versions and
 *     Composer never downloads a duplicate vendor tree;
 *   - `config.platform` mirroring the host's PHP version + every
 *     loaded `ext-*`, so the plugin root resolves against the host's
 *     platform matrix even when the worker spawns Composer in a
 *     slightly different env.
 *
 * The dependency policy is documented in
 * {@see \App\Plugin\Security\PluginDependencyPolicy} and
 * `docs/plugins/architecture.md`.
 */
final class PluginComposerRoot
{
    /**
     * Host-provided package namespace prefixes. Any package in
     * `vendor/composer/installed.json` whose name starts with one of
     * these prefixes is materialised into the plugin root's `provide`
     * block at the host's resolved version. Plugin `require`
     * constraints are then satisfied without fetching a duplicate
     * copy.
     *
     * @var list<string>
     */
    public const HOST_PROVIDED_PREFIXES = [
        'symfony/',
        'doctrine/',
        'psr/',
        'humdek/sh-selfhelp-',
    ];

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function rootDir(): string
    {
        return $this->projectDir
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'plugin-composer';
    }

    public function vendorDir(): string
    {
        return $this->rootDir() . DIRECTORY_SEPARATOR . 'vendor';
    }

    public function autoloadPath(): string
    {
        return $this->vendorDir() . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    public function composerJsonPath(): string
    {
        return $this->rootDir() . DIRECTORY_SEPARATOR . 'composer.json';
    }

    /**
     * Idempotent. Creates the plugin Composer root + seeded
     * `composer.json` if they do not exist yet. Safe to call before
     * every `composer require` so a missing root never breaks an
     * install.
     */
    public function ensure(): void
    {
        $root = $this->rootDir();
        if (!is_dir($root)) {
            if (!@mkdir($root, 0775, true) && !is_dir($root)) {
                throw new \RuntimeException(sprintf(
                    'Failed to create plugin Composer root "%s".',
                    $root
                ));
            }
        }

        $composerJson = $this->composerJsonPath();
        if (is_file($composerJson)) {
            return;
        }

        $seed = $this->buildSeedComposerJson();
        $contents = json_encode(
            $seed,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($contents === false) {
            throw new \RuntimeException('Failed to JSON-encode plugin composer.json seed.');
        }
        if (file_put_contents($composerJson, $contents . "\n", LOCK_EX) === false) {
            throw new \RuntimeException(sprintf(
                'Failed to write plugin composer.json at "%s".',
                $composerJson
            ));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSeedComposerJson(): array
    {
        $platform = $this->collectHostPlatform();
        $provide = $this->collectHostProvidedPackages();

        $seed = [
            'name' => 'selfhelp/plugin-composer-root',
            'description' => 'Isolated Composer root for SelfHelp plugins. Auto-managed by the plugin lifecycle services. Do NOT edit by hand.',
            'type' => 'project',
            'license' => 'MPL-2.0',
            'config' => [
                'vendor-dir' => 'vendor',
                'allow-plugins' => false,
                'sort-packages' => true,
                'platform' => $platform,
            ],
            'require' => [
                'php' => '>=8.4',
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];
        if ($provide !== []) {
            $seed['provide'] = $provide;
        }
        return $seed;
    }

    /**
     * Reads `vendor/composer/installed.json` and returns a map of
     * `<host-provided-package> => <version>` for every package whose
     * name starts with one of {@see self::HOST_PROVIDED_PREFIXES}.
     *
     * @return array<string,string>
     */
    private function collectHostProvidedPackages(): array
    {
        $installedJson = $this->projectDir
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($installedJson)) {
            return [];
        }
        $raw = @file_get_contents($installedJson);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $packages = $decoded['packages'] ?? [];
        if (!is_array($packages)) {
            return [];
        }

        $provide = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $name = $pkg['name'] ?? null;
            $version = $pkg['version'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($version) || $version === '') {
                continue;
            }
            foreach (self::HOST_PROVIDED_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $provide[$name] = $this->normaliseVersion($version);
                    break;
                }
            }
        }
        ksort($provide);
        return $provide;
    }

    /**
     * Builds the `config.platform` block. Only entries that Composer's
     * own `VersionParser` will accept are emitted — anything else is
     * dropped and Composer falls back to runtime
     * `extension_loaded()` / `phpversion()` detection, which is always
     * correct because the worker runs in the host PHP.
     *
     * Why filtering matters: `phpversion()` returns wildly different
     * shapes per extension. Some return clean semver (`8.4.0`), some
     * return a prefixed string (`mysqlnd 8.4.0`), some return
     * date-ish numbers (`20031129`), some return non-numeric tokens
     * (`3.4.0beta1`). Putting any of these into `config.platform`
     * makes Composer fatal with `Invalid version string "<x>"` —
     * killing every install.
     *
     * Likewise, extension names must be valid Composer platform keys.
     * `get_loaded_extensions()` can return values like
     * `"Zend OPcache"` (with a literal space) which Composer rejects.
     *
     * @return array<string,string>
     */
    private function collectHostPlatform(): array
    {
        $platform = [
            'php' => $this->normalisePhpVersion(PHP_VERSION),
        ];
        foreach (get_loaded_extensions() as $ext) {
            $extName = strtolower($ext);
            if ($extName === '' || $extName === 'standard' || $extName === 'core') {
                continue;
            }
            // Composer platform keys: lowercase letters, digits,
            // underscore and dash. Anything else (e.g. a space in
            // "Zend OPcache") would be rejected by Composer's
            // `PlatformRepository`. Skip rather than guess at the
            // canonical name.
            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $extName) !== 1) {
                continue;
            }
            $version = phpversion($extName);
            if (!is_string($version) || $version === '') {
                continue;
            }
            $clean = $this->normaliseExtensionVersion($version);
            if ($clean === null) {
                continue;
            }
            $platform['ext-' . $extName] = $clean;
        }
        ksort($platform);
        return $platform;
    }

    /**
     * Extract a Composer-parseable version (`X[.Y[.Z[.W]]]` with
     * optional `-stability` / `+meta` suffix) from a raw `phpversion()`
     * result. Returns null when nothing semver-shaped is present so
     * the caller skips the entry entirely.
     */
    private function normaliseExtensionVersion(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');
        if (preg_match('/^(\d+(?:\.\d+){0,3})(?:[-+][A-Za-z0-9.\-]+)?$/', $version, $m) === 1) {
            return $m[1];
        }
        // Some extensions prefix with their name, e.g. "mysqlnd 8.4.0"
        // or "PCRE 8.40 2017-01-11". Pull out the first X.Y[.Z]
        // sequence. If none is present, return null and let Composer
        // detect the extension at runtime.
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Composer accepts non-`v`-prefixed semver in `provide`. Strip the
     * leading `v` if a tag-style version sneaks through.
     */
    private function normaliseVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }

    /**
     * `PHP_VERSION` may include `-dev` / `-rc` suffixes Composer accepts
     * but tools downstream don't. We keep the major.minor.patch only,
     * matching what Composer's own `config.platform.php` examples do.
     */
    private function normalisePhpVersion(string $version): string
    {
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m)) {
            return $m[1];
        }
        return $version;
    }
}
