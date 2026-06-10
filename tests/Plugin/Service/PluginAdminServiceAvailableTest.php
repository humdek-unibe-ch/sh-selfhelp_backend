<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Service;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginSource;
use App\Plugin\Registry\PluginSourceUrlResolver;
use App\Plugin\Registry\Unified\PluginReleaseResolver;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Service\PluginAdminService;
use App\Repository\Plugin\PluginRepository;
use App\Repository\Plugin\PluginSourceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * #50 read side — the live admin `/available` and `/updates` endpoints are wired
 * onto the UNIFIED consumer ({@see UnifiedRegistryClient} +
 * {@see PluginReleaseResolver}). These tests drive
 * {@see PluginAdminService::listAvailableFromRegistries()} and
 * {@see PluginAdminService::listAvailableUpdates()} over the one shared signed
 * fixture (0.1.0 compatible with core 0.1.x; 0.2.0 only with core 0.2.x) and
 * assert the multi-version contract the #49 Available-UI picker renders:
 * per-version compatibility, the default selection (newest COMPATIBLE), the
 * compat-error payload, and a ready-to-install `registryEntry.releaseUrl`.
 *
 * The service is built with `newInstanceWithoutConstructor()` + reflection
 * injection of only the collaborators these read paths touch, so the test stays
 * a fast unit test (no kernel, no DB) while exercising the REAL unified client
 * and resolver end-to-end.
 */
#[Group('plugin')]
final class PluginAdminServiceAvailableTest extends TestCase
{
    private const BASE_URL = 'https://registry.selfhelp.test';

    private function fixtureDir(): string
    {
        return \dirname(__DIR__, 2) . '/fixtures/registry/unified';
    }

    private function trustedVerifier(): PluginSignatureVerifier
    {
        $seed = hash('sha256', 'selfhelp-dev-registry-signing-key-v1', true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return new PluginSignatureVerifier(
            ['selfhelp-official-2026' => base64_encode($publicKey)],
            true,
            new NullLogger(),
            'test',
        );
    }

    private function fixtureHttpClient(): HttpClientInterface
    {
        $dir = $this->fixtureDir();
        return new MockHttpClient(static function (string $method, string $url) use ($dir): MockResponse {
            $path = ltrim(substr($url, \strlen(self::BASE_URL)), '/');
            $file = $dir . '/' . $path;
            if (!is_file($file)) {
                return new MockResponse('not found', ['http_code' => 404]);
            }
            $body = file_get_contents($file);
            return new MockResponse($body === false ? '' : $body);
        });
    }

    /**
     * @param list<Plugin> $installedPlugins return of plugins->findAll()/findAllOrderedByName()
     */
    private function makeService(string $cmsVersion, array $installedPlugins = []): PluginAdminService
    {
        $http = $this->fixtureHttpClient();

        $source = new PluginSource('humdek-public', PluginSource::KIND_PUBLIC_REGISTRY, self::BASE_URL);

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAll')->willReturn($installedPlugins);
        $plugins->method('findAllOrderedByName')->willReturn($installedPlugins);

        $sources = $this->createStub(PluginSourceRepository::class);
        $sources->method('findEnabled')->willReturn([$source]);

        $service = (new \ReflectionClass(PluginAdminService::class))->newInstanceWithoutConstructor();
        $this->inject($service, 'plugins', $plugins);
        $this->inject($service, 'sources', $sources);
        $this->inject($service, 'sourceUrlResolver', new PluginSourceUrlResolver(self::BASE_URL));
        $this->inject($service, 'unifiedRegistryClient', new UnifiedRegistryClient($http, $this->trustedVerifier(), new NullLogger()));
        $this->inject($service, 'releaseResolver', new PluginReleaseResolver());
        $this->inject($service, 'httpClient', $http);
        $this->inject($service, 'cmsVersion', $cmsVersion);
        $this->inject($service, 'sdkApiVersion', '0.1.0');

        return $service;
    }

    private function inject(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setValue($target, $value);
    }

    /**
     * @return array<array-key,mixed>
     */
    private function arr(mixed $value): array
    {
        self::assertIsArray($value);
        return $value;
    }

    private function str(mixed $value): string
    {
        self::assertIsString($value);
        return $value;
    }

    public function testAvailableExposesMultiVersionPickerWithNewestCompatibleSelected(): void
    {
        // core 0.1.0 -> 0.1.0 compatible, 0.2.0 incompatible (needs core 0.2.x).
        $available = $this->makeService('0.1.0')->listAvailableFromRegistries();

        self::assertCount(1, $available);
        $entry = $this->arr($available[0]);

        self::assertSame('sh2-shp-survey-js', $entry['pluginId']);
        self::assertFalse($entry['installed']);
        self::assertSame('0.2.0', $entry['latestVersion']);
        self::assertSame('0.1.0', $entry['latestCompatibleVersion']);
        self::assertSame('0.1.0', $entry['selectedVersion']);
        self::assertTrue($entry['newerExistsButIncompatible']);
        self::assertTrue($entry['hasCompatibleVersion']);
        // A compatible version exists -> no entry-level blocking error; the
        // incompatible row still carries its own per-version reason (below).
        self::assertNull($entry['compatibilityError']);

        // Per-version picker rows: newest-first, each carrying compatibility +
        // an install-ready registryEntry.releaseUrl.
        $versions = $this->arr($entry['versions']);
        self::assertCount(2, $versions);

        $byVersion = [];
        foreach ($versions as $row) {
            $rowArr = $this->arr($row);
            $byVersion[$this->str($rowArr['version'])] = $rowArr;
        }
        self::assertSame('0.2.0', $this->str($this->arr($versions[0])['version']), 'versions are newest-first');

        self::assertFalse($byVersion['0.2.0']['compatible']);
        self::assertSame('incompatible', $byVersion['0.2.0']['state']);
        self::assertNotNull($byVersion['0.2.0']['reason']);

        self::assertTrue($byVersion['0.1.0']['compatible']);
        self::assertTrue($byVersion['0.1.0']['selected']);
        self::assertSame('latest-compatible', $byVersion['0.1.0']['state']);
        $registryEntry = $this->arr($byVersion['0.1.0']['registryEntry']);
        self::assertStringEndsWith(
            'releases/plugins/sh2-shp-survey-js-0.1.0.json',
            $this->str($registryEntry['releaseUrl']),
        );
        self::assertSame('humdek-public', $registryEntry['sourceName']);
    }

    public function testAvailableSurfacesCompatibilityErrorWhenNoVersionCompatible(): void
    {
        // core 0.9.0 -> neither 0.1.0 nor 0.2.0 runs on it: no compatible
        // selection, so the entry carries the standardized compat-error payload
        // (#51) and the picker offers no default selection.
        $available = $this->makeService('0.9.0')->listAvailableFromRegistries();

        self::assertCount(1, $available);
        $entry = $this->arr($available[0]);

        self::assertNull($entry['selectedVersion']);
        self::assertNull($entry['latestCompatibleVersion']);
        self::assertFalse($entry['hasCompatibleVersion']);
        $compatError = $this->arr($entry['compatibilityError']);
        self::assertSame('plugin', $compatError['component']);
        self::assertSame('sh2-shp-survey-js', $compatError['component_id']);

        // Both versions still listed, both incompatible.
        $versions = $this->arr($entry['versions']);
        self::assertCount(2, $versions);
        foreach ($versions as $row) {
            self::assertFalse($this->arr($row)['compatible']);
        }
    }

    public function testUpdatesSkipsIncompatibleNewerVersionForInstalledPlugin(): void
    {
        // Installed 0.1.0, core 0.1.0: 0.2.0 exists but is incompatible -> the
        // newest-COMPATIBLE selection equals installed -> no update offered.
        $installed = $this->installedPlugin('sh2-shp-survey-js', '0.1.0', pinned: false);
        $rows = $this->makeService('0.1.0', [$installed])->listAvailableUpdates();

        self::assertSame([], $rows);
    }

    public function testUpdatesOffersNewestCompatibleVersionWhenCoreSupportsIt(): void
    {
        // Same installed 0.1.0 but on core 0.2.0: now 0.2.0 is compatible and
        // strictly newer -> exactly one update row, selecting 0.2.0.
        $installed = $this->installedPlugin('sh2-shp-survey-js', '0.1.0', pinned: false);
        $rows = $this->makeService('0.2.0', [$installed])->listAvailableUpdates();

        self::assertCount(1, $rows);
        $row = $this->arr($rows[0]);
        self::assertSame('sh2-shp-survey-js', $row['pluginId']);
        self::assertSame('0.1.0', $row['installedVersion']);
        self::assertSame('0.2.0', $row['availableVersion']);
        self::assertSame('minor', $row['diffKind']);
        $registryEntry = $this->arr($row['registryEntry']);
        self::assertStringEndsWith(
            'releases/plugins/sh2-shp-survey-js-0.2.0.json',
            $this->str($registryEntry['releaseUrl']),
        );
    }

    public function testUpdatesNeverOffersPinnedPlugin(): void
    {
        // Pinned plugin on core 0.2.0 where a compatible newer version exists:
        // pinning (audit #52) freezes it -> no update row.
        $installed = $this->installedPlugin('sh2-shp-survey-js', '0.1.0', pinned: true);
        $rows = $this->makeService('0.2.0', [$installed])->listAvailableUpdates();

        self::assertSame([], $rows);
    }

    private function installedPlugin(string $pluginId, string $version, bool $pinned): Plugin
    {
        $plugin = $this->createStub(Plugin::class);
        $plugin->method('getPluginId')->willReturn($pluginId);
        $plugin->method('getVersion')->willReturn($version);
        $plugin->method('getName')->willReturn($pluginId);
        $plugin->method('getTrustLevel')->willReturn('official');
        $plugin->method('isPinned')->willReturn($pinned);

        return $plugin;
    }
}
