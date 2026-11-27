<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginDependencyGuard;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * C21 over HTTP: the admin plugin routes answer a refused lifecycle step with 409, the machine
 * code in `error` and the plugins that block it in `data` (the plugin screen names them).
 *
 * Only refusals are driven here. They happen before anything is recorded or changed. The
 * success paths of enable, disable and uninstall write the plugin lock file and the bundle
 * list of the working tree and are not run from a test (the rule checks themselves are in
 * {@see \App\Tests\Integration\Plugin\PluginDependencyTest}).
 */
#[Group('security')]
final class AdminPluginDependencyRefusalTest extends QaWebTestCase
{
    private const PLUGINS_URI = '/cms-api/v1/admin/plugins/';
    private const BASE = 'qa-dep-http-base';
    private const DEPENDENT = 'qa-dep-http-dependent';

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback; do not leave the QA plugins in it.
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        parent::tearDown();
    }

    public function testDisablingAPluginThatAnEnabledPluginRequiresNamesTheDependent(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [['pluginId' => self::BASE, 'version' => '^1.0.0']]);

        $envelope = $this->jsonRequest('POST', self::PLUGINS_URI . self::BASE . '/disable', null, $this->loginAsQaAdmin());

        $this->assertRefusal($envelope, PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED);
        self::assertSame(
            ['dependents' => [['plugin_id' => self::DEPENDENT, 'version' => '^1.0.0']]],
            $envelope['data'],
        );
        self::assertSame(1, $this->enabledFlag(self::BASE), 'a refused disable changes nothing');
        self::assertSame(0, $this->operationCount(self::BASE), 'and records no operation');
    }

    public function testUninstallingAPluginThatAnEnabledPluginRequiresIsRefusedAndQueuesNothing(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [['pluginId' => self::BASE]]);

        $envelope = $this->jsonRequest('POST', self::PLUGINS_URI . self::BASE . '/uninstall', null, $this->loginAsQaAdmin());

        $this->assertRefusal($envelope, PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED);
        self::assertSame(
            ['dependents' => [['plugin_id' => self::DEPENDENT, 'version' => '*']]],
            $envelope['data'],
        );
        self::assertSame(0, $this->operationCount(self::BASE), 'no uninstall operation is queued');
    }

    public function testEnablingAPluginWhoseDependencyIsDisabledListsTheUnmetDependency(): void
    {
        $this->plugin(self::BASE, '1.2.0', false);
        $this->plugin(self::DEPENDENT, '1.0.0', false, [['pluginId' => self::BASE, 'version' => '^1.0.0']]);

        $envelope = $this->jsonRequest('POST', self::PLUGINS_URI . self::DEPENDENT . '/enable', null, $this->loginAsQaAdmin());

        $this->assertRefusal($envelope, PluginDependencyGuard::ERROR_DEPENDENCY_MISSING);
        self::assertSame(
            ['unmet' => [['plugin_id' => self::BASE, 'version' => '^1.0.0', 'reason' => 'disabled', 'installed_version' => '1.2.0']]],
            $envelope['data'],
        );
        self::assertSame(0, $this->enabledFlag(self::DEPENDENT), 'a refused enable changes nothing');
        self::assertSame(0, $this->operationCount(self::DEPENDENT));
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertRefusal(array $envelope, string $code): void
    {
        $this->assertEnvelopeError($envelope, Response::HTTP_CONFLICT);
        self::assertSame($code, $envelope['error']);
    }

    /**
     * A plugin row as an install leaves it, with the dependencies its manifest declares.
     *
     * @param list<array{pluginId: string, version?: string}> $dependencies
     */
    private function plugin(string $id, string $version, bool $enabled, array $dependencies = []): void
    {
        $plugin = new Plugin($id, 'QA ' . $id, $version, '0.1.0');
        $plugin->setEnabled($enabled);
        $plugin->setManifestJson([
            'id' => $id,
            'name' => 'QA ' . $id,
            'version' => $version,
            'pluginApiVersion' => '0.1.0',
            'dependencies' => $dependencies,
        ]);

        $em = $this->service(EntityManagerInterface::class);
        $em->persist($plugin);
        $em->flush();
    }

    private function enabledFlag(string $pluginId): int
    {
        $value = $this->service(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT enabled FROM plugins WHERE plugin_id = ?', [$pluginId]);

        return is_numeric($value) ? (int) $value : -1;
    }

    private function operationCount(string $pluginId): int
    {
        $value = $this->service(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM plugin_operations WHERE plugin_id = ?', [$pluginId]);

        return is_numeric($value) ? (int) $value : -1;
    }
}
