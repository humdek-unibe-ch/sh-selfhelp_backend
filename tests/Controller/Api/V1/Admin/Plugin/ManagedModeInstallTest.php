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
 * End-to-end managed-mode install integration test.
 *
 * Walks the full plugin install lifecycle against a real HTTP test
 * client + the live test DB:
 *
 *   1. Initial list   — confirm the fixture plugin is not installed.
 *   2. Request install — POST /admin/plugins   (expects 202 Accepted).
 *   3. Finalize        — POST /admin/plugins/{id}/finalize-install.
 *   4. Detail re-fetch — confirm the plugin shows up with the right
 *                        version and a `succeeded` operation.
 *   5. Uninstall       — POST /admin/plugins/{id}/uninstall.
 *   6. Cleanup         — POST /admin/plugins/{id}/purge with the
 *                        confirmation token (we do not want a stale
 *                        row left behind between runs).
 *
 * The test only relies on the admin JWT from the parent
 * `BaseControllerTest`; no fixtures, no mocks, no manual DB writes.
 * If the install or finalize call fails, the test still tries to
 * clean up so the next run starts from a clean slate.
 */
class ManagedModeInstallTest extends BaseControllerTest
{
    private const TEST_PLUGIN_ID = 'sh2-shp-plugin-doctor-test';
    private const TEST_PLUGIN_VERSION = '1.0.0';

    /**
     * Minimal-but-valid manifest matching `plugin-manifest.schema.json`.
     *
     * The plugin does not need to exist on disk for the `request +
     * finalize` flow because the orchestrator only stores the
     * manifest on the operation snapshot; the real composer / npm
     * work would happen in `managed` mode CI, which is out of scope
     * for an HTTP integration test.
     */
    private function manifest(): array
    {
        return [
            'id' => self::TEST_PLUGIN_ID,
            'name' => 'Plugin Doctor Test Fixture',
            'version' => self::TEST_PLUGIN_VERSION,
            'pluginApiVersion' => '1.0',
            'description' => 'Synthetic plugin used only by the managed-mode install integration test.',
            'author' => [
                'name' => 'SelfHelp Test Harness',
            ],
            'license' => 'MPL-2.0',
            'compatibility' => [
                'selfhelp' => '>=8.0.0 <9.0.0',
            ],
            'backend' => [
                'package' => 'humdek/' . self::TEST_PLUGIN_ID,
                'bundleClass' => 'Humdek\\PluginDoctorTest\\PluginDoctorTestBundle',
            ],
            'frontend' => [
                'package' => '@humdek/' . self::TEST_PLUGIN_ID,
                'version' => self::TEST_PLUGIN_VERSION,
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

    /**
     * Skip the test cleanly when the test environment cannot
     * authenticate as admin. This keeps the integration test useful
     * in CI envs that have an admin seed, while not poisoning the
     * full suite in dev environments that don't.
     */
    private function ensureAdminAvailable(): void
    {
        try {
            $this->getAdminAccessToken();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Admin login is not available in this test environment: ' . $e->getMessage()
            );
        }
    }

    /**
     * Try to remove any leftover test plugin row from a previous run.
     * Soft-fails on any HTTP status so a clean DB does not flag the
     * test as broken.
     */
    private function cleanupResidual(): void
    {
        try {
            $headers = $this->adminHeaders();
        } catch (\Throwable) {
            return;
        }

        $this->client->request(
            'GET',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID,
            [],
            [],
            $headers
        );

        $status = $this->client->getResponse()->getStatusCode();
        if ($status === Response::HTTP_NOT_FOUND) {
            return;
        }

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/purge',
            [],
            [],
            $headers,
            json_encode(['confirmedPluginId' => self::TEST_PLUGIN_ID])
        );
    }

    public function testManagedModeInstallEndToEnd(): void
    {
        $this->ensureAdminAvailable();
        $this->cleanupResidual();

        // ── 1. Initial list ────────────────────────────────────────────
        $this->client->request('GET', '/cms-api/v1/admin/plugins', [], [], $this->adminHeaders());
        $this->assertResponseIsSuccessful();
        $listBefore = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame(200, $listBefore['status']);
        $this->assertArrayHasKey('plugins', $listBefore['data']);
        $idsBefore = array_column($listBefore['data']['plugins'], 'pluginId');
        $this->assertNotContains(self::TEST_PLUGIN_ID, $idsBefore, 'Test plugin should not exist before install.');

        // ── 2. Request install ─────────────────────────────────────────
        $manifest = $this->manifest();
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins',
            [],
            [],
            $this->adminHeaders(),
            json_encode(['manifest' => $manifest])
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_ACCEPTED,
            $response->getStatusCode(),
            'Install request must return 202 Accepted. Body: ' . $response->getContent()
        );

        $requestBody = json_decode($response->getContent(), true);
        $this->assertSame(202, $requestBody['status']);
        $this->assertArrayHasKey('data', $requestBody);
        $this->assertArrayHasKey('id', $requestBody['data']);
        $this->assertArrayHasKey('status', $requestBody['data']);
        $this->assertSame('requested', $requestBody['data']['status']);
        $this->assertSame(self::TEST_PLUGIN_ID, $requestBody['data']['pluginId']);
        $operationId = (int) $requestBody['data']['id'];

        // ── 3. Finalize install ────────────────────────────────────────
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/finalize-install',
            [],
            [],
            $this->adminHeaders(),
            json_encode([
                'operationId' => $operationId,
                'manifest' => $manifest,
            ])
        );

        $this->assertResponseIsSuccessful();
        $finalizeBody = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(200, $finalizeBody['status']);
        $this->assertSame(self::TEST_PLUGIN_ID, $finalizeBody['data']['pluginId']);
        $this->assertSame(self::TEST_PLUGIN_VERSION, $finalizeBody['data']['version']);

        // ── 4. Detail re-fetch ────────────────────────────────────────
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID,
            [],
            [],
            $this->adminHeaders()
        );
        $this->assertResponseIsSuccessful();
        $detail = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(200, $detail['status']);
        $this->assertSame(self::TEST_PLUGIN_ID, $detail['data']['pluginId']);
        $this->assertSame(self::TEST_PLUGIN_VERSION, $detail['data']['version']);
        $this->assertSame('untrusted', $detail['data']['trustLevel']);

        // The detail endpoint must expose at least one operation row in
        // the `succeeded` state for this plugin.
        $this->assertArrayHasKey('operations', $detail['data']);
        $hasSucceeded = false;
        foreach ($detail['data']['operations'] as $op) {
            if ($op['type'] === 'install' && $op['status'] === 'succeeded') {
                $hasSucceeded = true;
                break;
            }
        }
        $this->assertTrue($hasSucceeded, 'Detail must show a succeeded install operation.');

        // ── 5. List again, this time the plugin must show up ──────────
        $this->client->request('GET', '/cms-api/v1/admin/plugins', [], [], $this->adminHeaders());
        $this->assertResponseIsSuccessful();
        $listAfter = json_decode($this->client->getResponse()->getContent(), true);
        $idsAfter = array_column($listAfter['data']['plugins'], 'pluginId');
        $this->assertContains(self::TEST_PLUGIN_ID, $idsAfter, 'Test plugin must show up in list after install.');

        // ── 6. Uninstall ──────────────────────────────────────────────
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/uninstall',
            [],
            [],
            $this->adminHeaders()
        );
        $this->assertResponseIsSuccessful();
        $uninstallBody = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(200, $uninstallBody['status']);
        $this->assertSame('uninstalled', $uninstallBody['data']['status']);

        // ── 7. Purge so the next test run starts clean ────────────────
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/purge',
            [],
            [],
            $this->adminHeaders(),
            json_encode(['confirmedPluginId' => self::TEST_PLUGIN_ID])
        );
        $this->assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        $this->cleanupResidual();
        parent::tearDown();
    }

    public function testInstallRejectsDuplicateRequest(): void
    {
        $this->ensureAdminAvailable();
        $this->cleanupResidual();

        $manifest = $this->manifest();

        // Successful first request.
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins',
            [],
            [],
            $this->adminHeaders(),
            json_encode(['manifest' => $manifest])
        );
        $this->assertSame(
            Response::HTTP_ACCEPTED,
            $this->client->getResponse()->getStatusCode(),
            'First install request must return 202.'
        );
        $firstBody = json_decode($this->client->getResponse()->getContent(), true);
        $opId = (int) $firstBody['data']['id'];

        // Finalize the first request so the plugin row exists.
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID . '/finalize-install',
            [],
            [],
            $this->adminHeaders(),
            json_encode(['operationId' => $opId, 'manifest' => $manifest])
        );
        $this->assertResponseIsSuccessful();

        // Second request must be rejected.
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins',
            [],
            [],
            $this->adminHeaders(),
            json_encode(['manifest' => $manifest])
        );
        $secondStatus = $this->client->getResponse()->getStatusCode();
        $this->assertSame(
            Response::HTTP_CONFLICT,
            $secondStatus,
            'Duplicate install must return 409 Conflict.'
        );
    }

    public function testInstallRejectsManifestMissingRequiredFields(): void
    {
        $this->ensureAdminAvailable();
        $this->cleanupResidual();

        $broken = [
            'id' => self::TEST_PLUGIN_ID,
            // 'name', 'version', 'pluginApiVersion', 'compatibility',
            // 'security' all intentionally missing.
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins',
            [],
            [],
            $this->adminHeaders(),
            json_encode(['manifest' => $broken])
        );

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status, 'Broken manifest must return 4xx, got ' . $status);
        $this->assertLessThan(500, $status, 'Broken manifest must not crash with 5xx, got ' . $status);
    }
}
