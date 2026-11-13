<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\System;

use App\Entity\User;
use App\Repository\Plugin\PluginRepository;
use App\Service\CMS\UserPermissionService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The "about the platform" facts: the product version, the frameworks it runs
 * on, the enabled plugins and the libraries the backend is built with, each
 * with its licence.
 *
 * Nothing here is typed by hand except the fixed list of four frameworks. The
 * libraries come from `composer.json` (what the backend asks for directly) and
 * `vendor/composer/installed.json` (what is installed, with version and
 * licence), the plugins from the plugin table, the versions from the running
 * PHP, Symfony, database server and Redis.
 *
 * The `version` style shows this to everybody, but exact versions only to a
 * caller who holds {@see self::DETAIL_PERMISSION}: for everybody else every
 * `version` is `null`. What is shown depends on the caller, so the block is
 * built when a page is served and is never stored in the page cache. Reading
 * `installed.json` is about 1.4 ms (410 KB, measured), so no cache is added.
 *
 * Every source is optional: a missing file, an unreachable Redis or a failing
 * query is logged and gives `version: null` or an empty list. The block never
 * fails the page.
 *
 * Mirrors the shared `IPlatformAbout` and `IAboutItem` types.
 *
 * @phpstan-type AboutItem array{name: string, version: string|null, license: string|null}
 * @phpstan-type PlatformAbout array{
 *     detail: bool,
 *     product: array{name: string, version: string},
 *     frameworks: list<AboutItem>,
 *     plugins: list<AboutItem>,
 *     libraries: list<AboutItem>
 * }
 */
class PlatformAboutService
{
    /** The permission that unlocks exact versions. */
    public const DETAIL_PERMISSION = 'admin.system.read';

    private const PRODUCT_NAME = 'SelfHelp';

    /**
     * @param object|null $redis the Redis connection the cache already uses
     *                           (`\Redis`, a Predis client, ...); only asked for `INFO server`
     */
    public function __construct(
        private readonly SystemInstanceService $instance,
        private readonly PluginRepository $pluginRepository,
        private readonly UserPermissionService $permissions,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire(service: 'cache.default_redis_provider')]
        private readonly ?object $redis = null,
    ) {
    }

    /**
     * The `about` block for the caller: exact versions only when the caller
     * holds {@see self::DETAIL_PERMISSION}.
     *
     * @return PlatformAbout
     */
    public function aboutForCaller(?User $caller): array
    {
        return $this->about($this->callerSeesDetail($caller));
    }

    /**
     * Anonymous callers never see exact versions. A failing permission lookup
     * counts as "no": showing less is the safe side.
     */
    public function callerSeesDetail(?User $caller): bool
    {
        if ($caller === null) {
            return false;
        }

        try {
            return in_array(self::DETAIL_PERMISSION, $this->permissions->getUserPermissions($caller), true);
        } catch (\Throwable $e) {
            $this->logger->warning('About block: permission lookup failed, showing no versions.', ['exception' => $e]);

            return false;
        }
    }

    /**
     * @return PlatformAbout
     */
    public function about(bool $detail): array
    {
        return [
            'detail' => $detail,
            'product' => ['name' => self::PRODUCT_NAME, 'version' => $this->instance->getCmsVersion()],
            'frameworks' => $this->frameworks($detail),
            'plugins' => $this->plugins($detail),
            'libraries' => $this->libraries($detail),
        ];
    }

    /**
     * The server side parts: PHP, Symfony, the database server, Redis. The
     * server versions are only read when they are going to be shown.
     *
     * @return list<AboutItem>
     */
    public function frameworks(bool $detail): array
    {
        return [
            $this->item('PHP', $detail ? PHP_VERSION : null, 'PHP-3.01'),
            $this->item('Symfony', $detail ? Kernel::VERSION : null, 'MIT'),
            $this->item('MySQL', $detail ? $this->databaseVersion() : null, 'GPL-2.0'),
            $this->item('Redis', $detail ? $this->redisVersion() : null, null),
        ];
    }

    /**
     * The enabled plugins with the licence of their manifest, sorted by id.
     *
     * @return list<AboutItem>
     */
    public function plugins(bool $detail): array
    {
        try {
            $plugins = $this->pluginRepository->findEnabled();
        } catch (\Throwable $e) {
            $this->logger->warning('About block: plugin list unavailable.', ['exception' => $e]);

            return [];
        }

        $items = [];
        foreach ($plugins as $plugin) {
            $license = $plugin->getManifestJson()['license'] ?? null;
            $items[] = $this->item(
                $plugin->getPluginId(),
                $detail ? $plugin->getVersion() : null,
                is_string($license) && trim($license) !== '' ? trim($license) : null
            );
        }

        return $this->sortedByName($items);
    }

    /**
     * The direct production dependencies of the backend (the `require` of
     * `composer.json`, without platform requirements such as `php` or
     * `ext-*`), resolved through `vendor/composer/installed.json` for version
     * and licence, sorted by name. A package that is not installed is left
     * out; without `installed.json` the list is empty.
     *
     * @return list<AboutItem>
     */
    public function libraries(bool $detail): array
    {
        $installed = $this->installedPackages();
        if ($installed === []) {
            return [];
        }

        $items = [];
        foreach ($this->directRequirements() as $name) {
            if (!isset($installed[$name])) {
                continue;
            }
            $items[] = $this->item($name, $detail ? $installed[$name]['version'] : null, $installed[$name]['license']);
        }

        return $this->sortedByName($items);
    }

    /**
     * @return AboutItem
     */
    private function item(string $name, ?string $version, ?string $license): array
    {
        return ['name' => $name, 'version' => $version, 'license' => $license];
    }

    /**
     * @param list<AboutItem> $items
     * @return list<AboutItem>
     */
    private function sortedByName(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
    }

    private function databaseVersion(): ?string
    {
        try {
            $raw = $this->connection->fetchOne('SELECT VERSION()');
        } catch (\Throwable $e) {
            $this->logger->warning('About block: database version unavailable.', ['exception' => $e]);

            return null;
        }

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * Redis server version from `INFO server`. `\Redis` answers a flat map,
     * Predis a map nested by section, so the key is looked up in both.
     */
    private function redisVersion(): ?string
    {
        if ($this->redis === null) {
            return null;
        }

        $info = [$this->redis, 'info'];
        if (!is_callable($info)) {
            return null;
        }

        try {
            $result = $info('server');
        } catch (\Throwable $e) {
            $this->logger->warning('About block: Redis version unavailable.', ['exception' => $e]);

            return null;
        }

        return is_array($result) ? $this->findRedisVersion($result) : null;
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private function findRedisVersion(array $info): ?string
    {
        foreach ($info as $key => $value) {
            if ($key === 'redis_version' && is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value)) {
                $found = $this->findRedisVersion($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Names of the packages `composer.json` requires directly. Platform
     * requirements (`php`, `ext-*`, `lib-*`, `composer-*-api`) have no vendor
     * part ("vendor/package"), so the slash tells them apart.
     *
     * @return list<string>
     */
    private function directRequirements(): array
    {
        $composer = $this->readJsonFile($this->projectDir . '/composer.json');
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        $names = [];
        foreach (array_keys($require) as $name) {
            if (is_string($name) && str_contains($name, '/')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Installed packages by name, from Composer's own record of the install.
     * It is written on every `composer install`, also with `--no-dev`, so it
     * is present in the production image.
     *
     * @return array<string, array{version: string|null, license: string|null}>
     */
    private function installedPackages(): array
    {
        $data = $this->readJsonFile($this->projectDir . '/vendor/composer/installed.json');
        $packages = is_array($data) && is_array($data['packages'] ?? null) ? $data['packages'] : [];

        $installed = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || $package['name'] === '') {
                continue;
            }
            $installed[$package['name']] = [
                'version' => $this->cleanVersion($package['version'] ?? null),
                'license' => $this->joinLicenses($package['license'] ?? null),
            ];
        }

        return $installed;
    }

    /**
     * Composer writes tags as `v7.4.5`; the other versions here are bare
     * (`PHP_VERSION`, `Kernel::VERSION`), so a leading `v` before a digit goes.
     */
    private function cleanVersion(mixed $version): ?string
    {
        if (!is_string($version) || trim($version) === '') {
            return null;
        }

        return preg_replace('/^v(?=\d)/', '', trim($version));
    }

    /**
     * Several licences of one package are alternatives (Composer's own
     * reading), so they are joined with ` OR `.
     */
    private function joinLicenses(mixed $license): ?string
    {
        $licenses = is_array($license) ? $license : [$license];
        $names = [];
        foreach ($licenses as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names === [] ? null : implode(' OR ', $names);
    }

    private function readJsonFile(string $path): mixed
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            $this->logger->warning('About block: file not readable.', ['path' => $path]);

            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('About block: file is not valid JSON.', ['path' => $path, 'exception' => $e]);

            return null;
        }
    }
}
