<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\Plugin\Plugin;
use App\Repository\Plugin\PluginRepository;
use App\Service\System\SystemAdvisoryService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemRegistryGatewayInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the advisory filtering logic.
 *
 * Asserts the observable contract: an unreachable registry degrades to
 * `available:false`; advisories are returned only when they actually affect an
 * installed component version (core / frontend / a named plugin); and the
 * relevant fixed version(s) are surfaced for what the instance runs.
 */
final class SystemAdvisoryServiceTest extends TestCase
{
    /**
     * @param array<string,mixed>|null $feed
     * @param list<Plugin> $plugins
     */
    private function service(
        ?array $feed,
        string $coreVersion = '0.1.0',
        string $frontendVersion = '0.1.0',
        array $plugins = [],
    ): SystemAdvisoryService {
        $gateway = $this->createStub(SystemRegistryGatewayInterface::class);
        $gateway->method('fetchAdvisories')->willReturn($feed);

        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getCmsVersion')->willReturn($coreVersion);
        $instance->method('getFrontendVersion')->willReturn($frontendVersion);

        $repo = $this->createStub(PluginRepository::class);
        $repo->method('findAllOrderedByName')->willReturn($plugins);

        return new SystemAdvisoryService($gateway, $instance, $repo);
    }

    private function plugin(string $id, string $version): Plugin
    {
        $plugin = $this->createStub(Plugin::class);
        $plugin->method('getPluginId')->willReturn($id);
        $plugin->method('getVersion')->willReturn($version);

        return $plugin;
    }

    public function testReportsUnavailableWhenRegistryOffline(): void
    {
        $result = $this->service(null)->getAdvisories();

        self::assertFalse($result['available']);
        self::assertSame([], $result['advisories']);
    }

    public function testReturnsEmptyWhenFeedHasNoMatchingAdvisories(): void
    {
        $feed = ['advisories' => [
            [
                'id' => 'SHSA-2026-0009', 'severity' => 'high',
                'affected' => [['kind' => 'core', 'id' => 'selfhelp-core', 'versions' => '>=0.0.1 <0.1.0']],
                'fixed' => [['kind' => 'core', 'id' => 'selfhelp-core', 'version' => '0.1.0']],
                'recommendedAction' => 'Update.', 'blocked' => true,
            ],
        ]];

        $result = $this->service($feed, '0.1.0')->getAdvisories();

        self::assertTrue($result['available']);
        self::assertSame([], $result['advisories'], 'An advisory for a pre-0.1.0 range must not match a 0.1.0 instance.');
    }

    public function testMatchesCoreAdvisoryAndSurfacesFixedVersion(): void
    {
        $feed = ['advisories' => [
            [
                'id' => 'SHSA-2026-0010', 'severity' => 'critical',
                'affected' => [['kind' => 'core', 'id' => 'selfhelp-core', 'versions' => '>=0.1.0 <0.1.1']],
                'fixed' => [['kind' => 'core', 'id' => 'selfhelp-core', 'version' => '0.1.1']],
                'recommendedAction' => 'Update core to 0.1.1.', 'blocked' => true,
                'detailsUrl' => 'https://example.test/advisory',
            ],
        ]];

        $result = $this->service($feed, '0.1.0')->getAdvisories();

        self::assertCount(1, $result['advisories']);
        $advisory = $result['advisories'][0];
        self::assertSame('SHSA-2026-0010', $advisory['id']);
        self::assertSame('critical', $advisory['severity']);
        self::assertTrue($advisory['blocked']);
        self::assertSame('https://example.test/advisory', $advisory['details_url']);
        self::assertSame(['0.1.1'], $advisory['fixed_versions']);
        self::assertSame(
            [['kind' => 'core', 'id' => 'selfhelp-core', 'installed_version' => '0.1.0']],
            $advisory['affected'],
        );
    }

    public function testMatchesPluginAdvisoryByIdButNotWithoutId(): void
    {
        $plugins = [$this->plugin('sh2-shp-survey-js', '0.2.20')];

        $matching = ['advisories' => [
            [
                'id' => 'SHSA-2026-0011', 'severity' => 'medium',
                'affected' => [['kind' => 'plugin', 'id' => 'sh2-shp-survey-js', 'versions' => '<0.3.0']],
                'fixed' => [['kind' => 'plugin', 'id' => 'sh2-shp-survey-js', 'version' => '0.3.0']],
                'recommendedAction' => 'Update the plugin.', 'blocked' => false,
            ],
        ]];
        $result = $this->service($matching, '0.1.0', '0.1.0', $plugins)->getAdvisories();
        self::assertCount(1, $result['advisories']);
        self::assertSame(['0.3.0'], $result['advisories'][0]['fixed_versions']);

        // A plugin advisory that does not name an id never matches an installed plugin.
        $noId = ['advisories' => [
            [
                'id' => 'SHSA-2026-0012', 'severity' => 'medium',
                'affected' => [['kind' => 'plugin', 'versions' => '<0.3.0']],
                'fixed' => [], 'recommendedAction' => 'x', 'blocked' => false,
            ],
        ]];
        $resultNoId = $this->service($noId, '0.1.0', '0.1.0', $plugins)->getAdvisories();
        self::assertSame([], $resultNoId['advisories']);
    }
}
