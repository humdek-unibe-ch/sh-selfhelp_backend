<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\Plugin\Plugin;
use App\Entity\User;
use App\Repository\Plugin\PluginRepository;
use App\Service\CMS\UserPermissionService;
use App\Service\System\PlatformAboutService;
use App\Service\System\SystemInstanceService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The "about the platform" data is generated from what is installed: direct
 * Composer requirements resolved through `installed.json`, the plugin table,
 * the running PHP / Symfony / database / Redis. Every source may be missing
 * without failing the caller, and without detail no version leaks.
 */
final class PlatformAboutServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/sh_about_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/vendor/composer/installed.json', '/composer.json'] as $file) {
            if (is_file($this->projectDir . $file)) {
                unlink($this->projectDir . $file);
            }
        }
        @rmdir($this->projectDir . '/vendor/composer');
        @rmdir($this->projectDir . '/vendor');
        @rmdir($this->projectDir);
    }

    public function testLibrariesAreTheDirectRequirementsWithInstalledVersionAndLicence(): void
    {
        $this->writeComposerJson([
            'php' => '>=8.4',
            'ext-ctype' => '*',
            'lib-curl' => '*',
            'composer-runtime-api' => '^2',
            'acme/beta' => '^2.0',
            'acme/alpha' => '^1.0',
            'acme/not-installed' => '^1.0',
        ]);
        $this->writeInstalledJson([
            ['name' => 'acme/alpha', 'version' => 'v1.2.3', 'license' => ['MIT']],
            ['name' => 'acme/beta', 'version' => '2.0.0', 'license' => ['MIT', 'Apache-2.0']],
            ['name' => 'acme/transitive', 'version' => '9.9.9', 'license' => ['BSD-3-Clause']],
        ]);

        $libraries = $this->service()->libraries(true);

        self::assertSame([
            ['name' => 'acme/alpha', 'version' => '1.2.3', 'license' => 'MIT'],
            ['name' => 'acme/beta', 'version' => '2.0.0', 'license' => 'MIT OR Apache-2.0'],
        ], $libraries, 'direct requirements only, platform requirements and transitive packages left out, sorted by name');
    }

    public function testLibraryWithoutLicenceFieldHasNullLicence(): void
    {
        $this->writeComposerJson(['acme/alpha' => '^1.0']);
        $this->writeInstalledJson([['name' => 'acme/alpha', 'version' => '1.0.0']]);

        self::assertSame(
            [['name' => 'acme/alpha', 'version' => '1.0.0', 'license' => null]],
            $this->service()->libraries(true)
        );
    }

    public function testWithoutDetailEveryVersionIsNullButNamesAndLicencesStay(): void
    {
        $this->writeComposerJson(['acme/alpha' => '^1.0']);
        $this->writeInstalledJson([['name' => 'acme/alpha', 'version' => '1.2.3', 'license' => ['MIT']]]);

        $about = $this->service(plugins: [$this->plugin('sh2-shp-demo', '2.3.4', ['license' => 'MPL-2.0'])])->about(false);

        self::assertFalse($about['detail']);
        self::assertSame(['name' => 'SelfHelp', 'version' => '0.1.43'], $about['product'], 'the product version is always shown');
        self::assertSame(['PHP', 'Symfony', 'MySQL', 'Redis'], array_column($about['frameworks'], 'name'));
        foreach ([...$about['frameworks'], ...$about['plugins'], ...$about['libraries']] as $item) {
            self::assertNull($item['version'], sprintf('%s must not show a version', $item['name']));
        }
        self::assertSame('MPL-2.0', $about['plugins'][0]['license']);
        self::assertSame('MIT', $about['libraries'][0]['license']);
        self::assertSame('PHP-3.01', $about['frameworks'][0]['license']);
    }

    public function testWithDetailVersionsAreExact(): void
    {
        $this->writeComposerJson(['acme/alpha' => '^1.0']);
        $this->writeInstalledJson([['name' => 'acme/alpha', 'version' => 'v1.2.3', 'license' => ['MIT']]]);

        $about = $this->service(
            plugins: [$this->plugin('sh2-shp-demo', '2.3.4', [])],
            redis: $this->redis(['redis_version' => '7.2.4', 'os' => 'Linux']),
            databaseVersion: '8.0.36',
        )->about(true);

        self::assertTrue($about['detail']);
        $frameworks = array_column($about['frameworks'], 'version', 'name');
        self::assertSame(PHP_VERSION, $frameworks['PHP']);
        self::assertSame(Kernel::VERSION, $frameworks['Symfony']);
        self::assertSame('8.0.36', $frameworks['MySQL']);
        self::assertSame('7.2.4', $frameworks['Redis']);
        self::assertSame([['name' => 'sh2-shp-demo', 'version' => '2.3.4', 'license' => null]], $about['plugins']);
        self::assertSame('1.2.3', $about['libraries'][0]['version']);
    }

    public function testRedisVersionIsFoundInAMapNestedBySection(): void
    {
        $about = $this->service(redis: $this->redis(['Server' => ['redis_version' => '7.0.15']]))->frameworks(true);

        self::assertSame('7.0.15', array_column($about, 'version', 'name')['Redis']);
    }

    public function testMissingInstalledJsonGivesAnEmptyLibraryList(): void
    {
        $this->writeComposerJson(['acme/alpha' => '^1.0']);

        self::assertSame([], $this->service()->libraries(true));
    }

    public function testInvalidInstalledJsonGivesAnEmptyLibraryList(): void
    {
        $this->writeComposerJson(['acme/alpha' => '^1.0']);
        file_put_contents($this->projectDir . '/vendor/composer/installed.json', '{ not json');

        self::assertSame([], $this->service()->libraries(true));
    }

    public function testMissingComposerJsonGivesAnEmptyLibraryList(): void
    {
        $this->writeInstalledJson([['name' => 'acme/alpha', 'version' => '1.0.0', 'license' => ['MIT']]]);

        self::assertSame([], $this->service()->libraries(true));
    }

    public function testUnreadableServerVersionsAreNullAndNothingThrows(): void
    {
        $service = $this->service(
            redis: new class {
                public function info(string ...$section): never
                {
                    throw new \RuntimeException('Redis is down');
                }
            },
            databaseVersionFails: true,
        );

        $frameworks = array_column($service->frameworks(true), 'version', 'name');

        self::assertSame(PHP_VERSION, $frameworks['PHP']);
        self::assertNull($frameworks['MySQL']);
        self::assertNull($frameworks['Redis']);
    }

    public function testWithoutARedisConnectionTheRedisVersionIsNull(): void
    {
        self::assertNull(array_column($this->service()->frameworks(true), 'version', 'name')['Redis']);
    }

    public function testFailingPluginQueryGivesAnEmptyPluginList(): void
    {
        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findEnabled')->willThrowException(new \RuntimeException('database is down'));

        self::assertSame([], $this->service(pluginRepository: $plugins)->plugins(true));
    }

    public function testPluginsAreSortedByIdAndLicenceNeedsAStringInTheManifest(): void
    {
        $about = $this->service(plugins: [
            $this->plugin('sh2-shp-zeta', '1.0.0', ['license' => 'MIT']),
            $this->plugin('sh2-shp-alpha', '1.0.0', ['license' => 123]),
            $this->plugin('sh2-shp-mid', '1.0.0', ['license' => '  ']),
        ])->plugins(true);

        self::assertSame(['sh2-shp-alpha', 'sh2-shp-mid', 'sh2-shp-zeta'], array_column($about, 'name'));
        self::assertSame([null, null, 'MIT'], array_column($about, 'license'));
    }

    public function testOnlyACallerWithAdminSystemReadSeesDetail(): void
    {
        $service = $this->service(grantedPermissions: ['admin.system.read', 'admin.page.read']);
        self::assertTrue($service->callerSeesDetail($this->createStub(User::class)));

        $service = $this->service(grantedPermissions: ['admin.page.read']);
        self::assertFalse($service->callerSeesDetail($this->createStub(User::class)));
    }

    public function testAnonymousCallerNeverSeesDetailAndPermissionsAreNotAsked(): void
    {
        $permissions = $this->createMock(UserPermissionService::class);
        $permissions->expects(self::never())->method('getUserPermissions');

        self::assertFalse($this->service(permissionService: $permissions)->callerSeesDetail(null));
    }

    public function testFailingPermissionLookupCountsAsNoDetail(): void
    {
        $permissions = $this->createStub(UserPermissionService::class);
        $permissions->method('getUserPermissions')->willThrowException(new \RuntimeException('cache is down'));

        self::assertFalse($this->service(permissionService: $permissions)->callerSeesDetail($this->createStub(User::class)));
    }

    public function testAboutForCallerFollowsThePermission(): void
    {
        $admin = $this->service(grantedPermissions: ['admin.system.read'])->aboutForCaller($this->createStub(User::class));
        $guest = $this->service()->aboutForCaller(null);

        self::assertTrue($admin['detail']);
        self::assertSame(PHP_VERSION, array_column($admin['frameworks'], 'version', 'name')['PHP']);
        self::assertFalse($guest['detail']);
        self::assertNull(array_column($guest['frameworks'], 'version', 'name')['PHP']);
    }

    /**
     * @param list<Plugin> $plugins
     * @param list<string> $grantedPermissions
     */
    private function service(
        array $plugins = [],
        ?object $redis = null,
        ?string $databaseVersion = null,
        bool $databaseVersionFails = false,
        array $grantedPermissions = [],
        ?PluginRepository $pluginRepository = null,
        ?UserPermissionService $permissionService = null,
    ): PlatformAboutService {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getCmsVersion')->willReturn('0.1.43');

        if ($pluginRepository === null) {
            $pluginRepository = $this->createStub(PluginRepository::class);
            $pluginRepository->method('findEnabled')->willReturn($plugins);
        }

        if ($permissionService === null) {
            $permissionService = $this->createStub(UserPermissionService::class);
            $permissionService->method('getUserPermissions')->willReturn($grantedPermissions);
        }

        $connection = $this->createStub(Connection::class);
        if ($databaseVersionFails) {
            $connection->method('fetchOne')->willThrowException(new \RuntimeException('database is down'));
        } else {
            $connection->method('fetchOne')->willReturn($databaseVersion);
        }

        return new PlatformAboutService(
            $instance,
            $pluginRepository,
            $permissionService,
            $connection,
            new NullLogger(),
            $this->projectDir,
            $redis,
        );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function plugin(string $pluginId, string $version, array $manifest): Plugin
    {
        $plugin = $this->createStub(Plugin::class);
        $plugin->method('getPluginId')->willReturn($pluginId);
        $plugin->method('getVersion')->willReturn($version);
        $plugin->method('getManifestJson')->willReturn($manifest);

        return $plugin;
    }

    /**
     * A stand-in for the Redis connection: answers `INFO server` with `$info`.
     *
     * @param array<string, mixed> $info
     */
    private function redis(array $info): object
    {
        return new class($info) {
            /** @param array<string, mixed> $info */
            public function __construct(private readonly array $info)
            {
            }

            /** @return array<string, mixed> */
            public function info(string ...$section): array
            {
                return $this->info;
            }
        };
    }

    /**
     * @param array<string, string> $require
     */
    private function writeComposerJson(array $require): void
    {
        file_put_contents($this->projectDir . '/composer.json', json_encode(['name' => 'acme/app', 'require' => $require], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function writeInstalledJson(array $packages): void
    {
        file_put_contents(
            $this->projectDir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages, 'dev' => false, 'dev-package-names' => []], JSON_THROW_ON_ERROR)
        );
    }
}
