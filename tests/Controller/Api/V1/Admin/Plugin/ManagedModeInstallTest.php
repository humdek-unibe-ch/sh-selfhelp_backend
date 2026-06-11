<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use App\Plugin\Lifecycle\PluginOperationLock;
use App\Tests\Controller\Api\V1\BaseControllerTest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration test for the unified `POST /admin/plugins/install` endpoint.
 *
 * ## Why this certifies the *managed-mode request*, not a finished install
 *
 * The backend runs plugin installs in **managed mode** in every non-`dev`
 * environment ({@see \App\Plugin\Lifecycle\InstallModeResolver} refuses the
 * inline `development` mode unless `APP_ENV=dev`). In managed mode the admin
 * API only *records* a `plugin_operations` row and dispatches the worker
 * message; a CLI/CI worker (`selfhelp:plugin:run-operation`) later runs
 * composer + Doctrine migrations and calls `PluginInstaller::finalize()`,
 * which writes `selfhelp.plugins.lock.json` + `config/selfhelp_plugin_bundles.php`
 * to disk. Those writes are non-transactional and would pollute the working
 * tree, so finalize is deliberately a deployment step and is NOT exercised
 * inside the WebTestCase DB transaction. The automated coverage here proves
 * the part that is safe + observable over HTTP:
 *
 *   - a valid manifest install request → 202 Accepted, recording a managed
 *     `plugin_operations` row (requested/running, installAction=install_dispatched);
 *   - a structurally invalid manifest is rejected with a 4xx client error
 *     (never a 5xx) — the canonical-schema validation gate.
 *
 * Reaching 202 already proves the manifest cleared signature verification,
 * compatibility, and capability/trust validation.
 *
 * ## Environment expectations
 *
 *   - Signature: the test relaxes `SELFHELP_PLUGIN_REQUIRE_SIGNATURE=false`
 *     for its own duration so the unsigned `untrusted` fixture installs (the
 *     verifier's documented dev/test opt-out). Production stays strict=true.
 *   - Lock: the test env uses an in-process `flock` store. Because finalize
 *     never runs, the lock is held from request-time; it is released
 *     explicitly in tearDown via {@see PluginOperationLock} so the suite is
 *     order-independent.
 *   - On a host without a seeded QA admin the suite skips cleanly.
 */
class ManagedModeInstallTest extends BaseControllerTest
{
    private const TEST_PLUGIN_ID = 'sh2-shp-plugin-doctor-test';
    private const TEST_PLUGIN_VERSION = '1.0.0';

    private ?string $previousRequireSignature = null;

    /**
     * Frontend-only `untrusted` fixture: declaring no backend bundle / no
     * migrations keeps the manifest valid for an unsigned install in the test
     * env (an untrusted plugin may not ship a backend bundle — see
     * PluginManifestValidator), so the install reaches the managed dispatch.
     *
     * @return array<string,mixed>
     */
    private function manifest(): array
    {
        // Track the CMS's own SDK version + a 0.1.x range that includes the
        // current pre-release (0.1.0) so the fixture never drifts out of
        // compatibility when the host bumps either value.
        $sdkVersion = $this->coerceString(self::getContainer()->getParameter('selfhelp.plugin_api_version'));

        return [
            'id' => self::TEST_PLUGIN_ID,
            'name' => 'Plugin Doctor Test Fixture',
            'version' => self::TEST_PLUGIN_VERSION,
            'pluginApiVersion' => $sdkVersion,
            'description' => 'Synthetic plugin used only by the install integration test.',
            'author' => [
                'name' => 'SelfHelp Test Harness',
            ],
            'license' => 'MPL-2.0',
            'compatibility' => [
                'selfhelp' => '>=0.1.0 <0.2.0',
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

    protected function setUp(): void
    {
        // TEST-ENV ONLY: allow installing an unsigned `untrusted` fixture so the
        // managed dispatch is reachable without signing infrastructure. This is
        // the verifier's documented dev/test opt-out and does NOT change
        // production behaviour (the default stays strict=true).
        $this->previousRequireSignature = getenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE') ?: null;
        putenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE=false');
        $_ENV['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = 'false';
        $_SERVER['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = 'false';

        parent::setUp();
        // Reuse one kernel/container per test so the flock lock acquired during
        // the managed install request lives on the same PluginOperationLock
        // instance we release in tearDown.
        $this->client->disableReboot();
        $this->cancelActiveOperations();
        $this->releaseOperationLock();
    }

    protected function tearDown(): void
    {
        $this->cancelActiveOperations();
        $this->releaseOperationLock();

        if ($this->previousRequireSignature === null) {
            putenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE');
            unset($_ENV['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'], $_SERVER['SELFHELP_PLUGIN_REQUIRE_SIGNATURE']);
        } else {
            putenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE=' . $this->previousRequireSignature);
            $_ENV['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = $this->previousRequireSignature;
            $_SERVER['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = $this->previousRequireSignature;
        }

        parent::tearDown();
    }

    /** @return array<string, string> */
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
            $this->markTestSkipped('Admin login not available (run composer test:reset-db): ' . $e->getMessage());
        }
    }

    public function testManagedInstallRequestRecordsADispatchedOperation(): void
    {
        $this->ensureAdminAvailable();

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/install',
            [], [],
            $this->adminHeaders(),
            (string) json_encode(['source' => 'paste', 'manifest' => $this->manifest()]),
        );

        $this->assertSame(
            Response::HTTP_ACCEPTED,
            $this->client->getResponse()->getStatusCode(),
            'install must return 202 Accepted (manifest passed signature + compatibility + capability checks). Body: '
                . $this->client->getResponse()->getContent(),
        );

        $op = $this->asArray($this->decodeArray()['data'] ?? null, 'install must return a plugin_operation data object');

        $this->assertIsInt($op['id'] ?? null, 'install must record an operation id');
        $this->assertSame(self::TEST_PLUGIN_ID, $op['pluginId'] ?? null, 'operation must be scoped to this plugin');
        $this->assertSame('install', $op['type'] ?? null, 'operation type must be install');
        $this->assertContains(
            $op['status'] ?? null,
            ['requested', 'running'],
            'a freshly requested managed install stays requested/running until a CLI worker finalizes it',
        );
        $this->assertSame(
            'install_dispatched',
            $op['installAction'] ?? null,
            'a fresh fixture must dispatch (not short-circuit to already_installed)',
        );
        $this->assertNotSame(
            'development',
            $op['installMode'] ?? null,
            'non-dev environments must resolve to a managed (worker-finalized) install mode',
        );

        // The managed dispatch must NOT have finalized the plugin from the web
        // request — the detail endpoint stays 404 until the CLI worker runs.
        $this->client->request('GET', '/cms-api/v1/admin/plugins/' . self::TEST_PLUGIN_ID, [], [], $this->adminHeaders());
        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'managed mode must not finalize the plugin synchronously from the web request',
        );

        // The recorded operation is observable via the operations API.
        $operationId = $this->asInt($op['id']);
        $this->client->request('GET', '/cms-api/v1/admin/plugins/operations/' . $operationId, [], [], $this->adminHeaders());
        $this->assertResponseIsSuccessful();
        $detailData = $this->asArray($this->decodeArray()['data'] ?? null);
        $this->assertSame($operationId, $detailData['id'] ?? null);
        $this->assertSame(self::TEST_PLUGIN_ID, $detailData['pluginId'] ?? null);
    }

    public function testInstallRejectsManifestMissingRequiredFields(): void
    {
        $this->ensureAdminAvailable();

        $broken = [
            'id' => self::TEST_PLUGIN_ID,
            // every other required field intentionally missing.
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/install',
            [], [],
            $this->adminHeaders(),
            (string) json_encode(['source' => 'paste', 'manifest' => $broken]),
        );

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status, 'Broken manifest must return 4xx, got ' . $status);
        $this->assertLessThan(500, $status, 'Broken manifest must not crash with 5xx, got ' . $status);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Cancel every still-active operation for this plugin so the DB-layer
     * concurrency guard starts each test clean (DAMA rolls the rows back
     * anyway, but cancelling keeps repeated installs in one process sane).
     */
    private function cancelActiveOperations(): void
    {
        try {
            $headers = $this->adminHeaders();
        } catch (\Throwable) {
            return;
        }
        $this->client->request('GET', '/cms-api/v1/admin/plugins/operations', [], [], $headers);
        if (!$this->client->getResponse()->isSuccessful()) {
            return;
        }
        $operationsRaw = $this->decodeArray()['data'] ?? [];
        $operations = is_array($operationsRaw) ? $operationsRaw : [];
        foreach ($operations as $op) {
            if (!is_array($op) || ($op['pluginId'] ?? null) !== self::TEST_PLUGIN_ID) {
                continue;
            }
            if (!in_array($op['status'] ?? null, ['requested', 'running'], true)) {
                continue;
            }
            $this->client->request(
                'POST',
                '/cms-api/v1/admin/plugins/operations/' . $this->asInt($op['id']) . '/cancel',
                [], [],
                $headers,
            );
        }
    }

    /**
     * Release the in-process flock distributed lock. In managed mode the lock
     * is held from request until the CLI worker finalizes (which never runs in
     * tests), so we release it explicitly to keep the suite order-independent.
     * Best-effort: the DB-layer guard is the fail-safe.
     */
    private function releaseOperationLock(): void
    {
        try {
            $lock = self::getContainer()->get(PluginOperationLock::class);
        } catch (\Throwable) {
            return;
        }
        if ($lock instanceof PluginOperationLock) {
            $lock->release(self::TEST_PLUGIN_ID);
        }
    }
}
