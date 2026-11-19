<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\ApiRoute;
use App\Entity\Plugin\Plugin;
use App\Plugin\Lifecycle\PluginApiRouteSynchronizer;
use App\Plugin\Manifest\PluginManifest;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * F09 / C3: plugin routes need a permission or public: true, never both / neither.
 */
#[Group('security')]
final class PluginApiRouteSynchronizerPublicTest extends QaKernelTestCase
{
    public function testPreflightRefusesRouteWithNeitherPermissionNorPublic(): void
    {
        $synchronizer = $this->service(PluginApiRouteSynchronizer::class);
        $manifest = new PluginManifest($this->minimalManifest([
            [
                'name' => 'qa_public_neither',
                'path' => '/plugins/qa-route-public-test/ping',
                'methods' => ['GET'],
                'controller' => 'App\\Controller\\Api\\V1\\HealthController::health',
            ],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route "qa_public_neither" has no permission and is not marked public.');
        $synchronizer->preflightValidate($manifest);
    }

    public function testPreflightRefusesRouteWithBothPermissionAndPublic(): void
    {
        $synchronizer = $this->service(PluginApiRouteSynchronizer::class);
        $manifest = new PluginManifest($this->minimalManifest([
            [
                'name' => 'qa_public_both',
                'path' => '/plugins/qa-route-public-test/ping',
                'methods' => ['GET'],
                'controller' => 'App\\Controller\\Api\\V1\\HealthController::health',
                'permission' => 'admin.page.read',
                'public' => true,
            ],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route "qa_public_both" cannot be public and declare permissions.');
        $synchronizer->preflightValidate($manifest);
    }

    public function testSyncStoresPublicTrueRouteAsIsPublic(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $synchronizer = $this->service(PluginApiRouteSynchronizer::class);

        $plugin = new Plugin('qa-route-public-test', 'QA Route Public Test', '0.0.1', '0.1.0');
        $plugin->setTrustLevel(Plugin::TRUST_UNTRUSTED);
        $plugin->setEnabled(true);
        $plugin->setInstallMode(Plugin::INSTALL_MODE_DEVELOPMENT);
        $plugin->setManifestJson(['id' => 'qa-route-public-test']);
        $em->persist($plugin);
        $em->flush();

        $manifest = new PluginManifest($this->minimalManifest([
            [
                'name' => 'qa_public_ok',
                'path' => '/plugins/qa-route-public-test/ping',
                'methods' => ['GET'],
                'controller' => 'App\\Controller\\Api\\V1\\HealthController::health',
                'public' => true,
            ],
        ]));

        try {
            $synchronizer->sync($plugin, $manifest);

            $route = $em->getRepository(ApiRoute::class)->findOneBy([
                'route_name' => 'qa_public_ok',
                'plugin' => $plugin,
            ]);
            self::assertInstanceOf(ApiRoute::class, $route);
            self::assertTrue($route->isPublic());
            self::assertCount(0, $route->getPermissions());
        } finally {
            $synchronizer->removeAllForPlugin($plugin);
            $em->remove($plugin);
            $em->flush();
        }
    }

    /**
     * @param list<array<string, mixed>> $apiRoutes
     * @return array<string, mixed>
     */
    private function minimalManifest(array $apiRoutes): array
    {
        return [
            'id' => 'qa-route-public-test',
            'name' => 'QA Route Public Test',
            'version' => '0.0.1',
            'pluginApiVersion' => '0.1.0',
            'cmsCompatibility' => '>=0.1.0',
            'permissions' => [
                ['key' => 'admin.page.read', 'label' => 'Read pages'],
            ],
            'apiRoutes' => $apiRoutes,
        ];
    }
}
