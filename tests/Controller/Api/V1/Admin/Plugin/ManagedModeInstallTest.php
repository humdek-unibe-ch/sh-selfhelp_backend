<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use App\Tests\Controller\Api\V1\BaseControllerTest;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end install integration test for the unified
 * `POST /admin/plugins/install` endpoint.
 *
 * The test environment uses the `sync://` Messenger transport so the
 * install handler runs inline; the test can therefore assert on the
 * resulting plugin row immediately after the install POST returns.
 *
 * Two test cases:
 *   1. testSyncInstallEndToEnd — full install via the `paste` source,
 *      uninstall, and purge cleanup.
 *   2. testInstallRejectsManifestMissingRequiredFields — the canonical
 *      schema validation refuses a structurally invalid manifest.
 *
 * The fixture manifest uses `trustLevel=untrusted` so the host's
 * signature verifier accepts the install even when no
 * SELFHELP_PLUGIN_TRUSTED_KEYS are configured (require_signature is
 * false in the test env).
 */
class ManagedModeInstallTest extends BaseControllerTest
{
    private const TEST_PLUGIN_ID = 'sh2-shp-plugin-doctor-test';
    private const TEST_PLUGIN_VERSION = '1.0.0';

    private function manifest(): array
    {
        return [
            'id' => self::TEST_PLUGIN_ID,
            'name' => 'Plugin Doctor Test Fixture',
            'version' => self::TEST_PLUGIN_VERSION,
            'pluginApiVersion' => '1.0',
            'description' => 'Synthetic plugin used only by the install integration test.',
            'author' => [
                'name' => 'SelfHelp Test Harness',
            ],
            'license' => 'MPL-2.0',
            'compatibility' => [
                'selfhelp' => '>=8.0.0 <9.0.0',
            ],
            'backend' => [
                'bundleClass' => 'Humdek\\PluginDoctorTest\\PluginDoctorTestBundle',
                'composer' => [
                    'package' => 'humdek/' . self::TEST_PLUGIN_ID,
                    'version' => self::TEST_PLUGIN_VERSION,
                ],
            ],
            'frontend' => [
                'runtime' => [
                    'entrypoint' => '/plugin-artifacts/' . self::TEST_PLUGIN_ID . '-' . self::TEST_PLUGIN_VERSION . '/plugin.esm.js',
                    'format' => 'esm',
                ],
            ],
            'security' => [
                'trustLevel' => 'untrusted',
                'capabilities' => [
                    'frontendStyles',
                ],
            ],
        ];
    }

    private function adminHeaders(): array
    {
        return [
            'HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken(),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function ensureAdminAvailable(): void
    {
        try {
            $this->getAdminAccessToken();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Admin login not available: ' . $e->getMessage());
        }
    }

    private function cleanupResidual(): void
    {
        try {
            $headers = $this->adminHeaders();
        } catch (\Throwable) {
            return;
        }
        $this->client->request('GET', '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID, [], [], $headers);
        if ($this->client->getResponse()->getStatusCode() === Response::HTTP_NOT_FOUND) {
            return;
        }
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/purge',
            [], [], $headers,
            json_encode(['confirmedPluginId' => self::TEST_PLUGIN_ID]),
        );
    }

    public function testSyncInstallEndToEnd(): void
    {
        $this->ensureAdminAvailable();
        $this->cleanupResidual();

        $manifest = $this->manifest();
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/install',
            [], [],
            $this->adminHeaders(),
            json_encode(['source' => 'paste', 'manifest' => $manifest]),
        );

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertSame(
            Response::HTTP_ACCEPTED,
            $status,
            'install must return 202 Accepted. Body: ' . $this->client->getResponse()->getContent(),
        );

        // Detail must show the plugin after the sync-transport handler runs.
        $this->client->request('GET', '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID, [], [], $this->adminHeaders());
        $this->assertResponseIsSuccessful();
        $detail = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(self::TEST_PLUGIN_ID, $detail['data']['pluginId']);
        $this->assertSame(self::TEST_PLUGIN_VERSION, $detail['data']['version']);
        $this->assertSame('untrusted', $detail['data']['trustLevel']);

        // Uninstall + purge to keep the test idempotent.
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/uninstall',
            [], [], $this->adminHeaders(),
        );
        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/purge',
            [], [],
            $this->adminHeaders(),
            json_encode(['confirmedPluginId' => self::TEST_PLUGIN_ID]),
        );
        $this->assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        $this->cleanupResidual();
        parent::tearDown();
    }

    public function testInstallRejectsManifestMissingRequiredFields(): void
    {
        $this->ensureAdminAvailable();
        $this->cleanupResidual();

        $broken = [
            'id' => self::TEST_PLUGIN_ID,
            // every other required field intentionally missing.
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/install',
            [], [],
            $this->adminHeaders(),
            json_encode(['source' => 'paste', 'manifest' => $broken]),
        );

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status, 'Broken manifest must return 4xx, got ' . $status);
        $this->assertLessThan(500, $status, 'Broken manifest must not crash with 5xx, got ' . $status);
    }
}
