<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Certification;

use App\Plugin\Lifecycle\PluginOperationLock;
use App\Tests\Controller\Api\V1\BaseControllerTest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reusable plugin install-lifecycle certification (Slice 8B / plan §"plugin
 * certification"). EVERY plugin's backend certification suite extends this
 * base and supplies its manifest; the base drives the lifecycle through the
 * REAL admin API and asserts the public, domain-visible effects (canonical
 * Testing Rule 17) plus self-cleaning order-independence (Rules 7 + 10).
 *
 * The `TestCase` suffix keeps PHPUnit from collecting this abstract base as a
 * runnable test (mirrors Symfony's own `WebTestCase`/`KernelTestCase`).
 *
 * ## Why this certifies the *managed-mode request*, not a finished install
 *
 * The backend runs plugin installs in **managed mode** in every non-`dev`
 * environment (see {@see \App\Plugin\Lifecycle\InstallModeResolver}: the
 * `development`/inline mode is refused unless `APP_ENV=dev`). In managed
 * mode the admin API only *records* the operation — a CLI/CI worker
 * (`selfhelp:plugin:run-operation`) later runs composer + npm + Doctrine
 * migrations and calls `PluginInstaller::finalize()`, which **writes to disk**
 * (`selfhelp.plugins.lock.json` and `config/selfhelp_plugin_bundles.php`).
 * Those writes are non-transactional and would pollute the working tree, so
 * finalize is deliberately a deployment step and is NOT exercised here. The
 * automated certification therefore proves the part that is safe + observable
 * over HTTP:
 *
 *   install request → 202 Accepted
 *                   → a `plugin_operations` row is recorded with the right
 *                     pluginId / type=install / managed installMode
 *                   → the operation is visible via the operations API
 *                   → a second operation is rejected while one is active
 *                     (the concurrency guard) and `cancel` clears it.
 *
 * Reaching 202 already proves the manifest passed signature verification,
 * compatibility checks, and capability/trust validation — the gauntlet a
 * real plugin must clear. Full composer/migration finalize + enable/disable +
 * purge run-through is covered by the deployment-side CLI smoke
 * (`selfhelp:plugin:*`), never inside the WebTestCase DB transaction.
 *
 * ## Environment expectations
 *
 *   - Signature: the test relaxes `SELFHELP_PLUGIN_REQUIRE_SIGNATURE=false`
 *     for its own duration so an unsigned `untrusted` fixture installs (the
 *     verifier's documented dev/test opt-out). Production stays strict=true.
 *   - Lock: the test env uses an in-process `flock` store. Because finalize
 *     never runs, the lock is released explicitly in tearDown via the
 *     {@see PluginOperationLock} service so the suite is order-independent.
 *   - On a host without a seeded QA admin the suite skips cleanly (Rule 26).
 *
 * This base is abstract — PHPUnit never runs it directly; concrete plugin
 * certifications (e.g. {@see Plugin\CertFixturePluginCertificationTest}) do.
 */
abstract class InstallLifecycleCertificationTestCase extends BaseControllerTest
{
    /**
     * Manifest installed via the `paste` source. Keep trustLevel=untrusted
     * (and declare no backend bundle / migrations) so the test-env validator
     * accepts an unsigned fixture without granting privileged capabilities.
     *
     * @return array<string,mixed>
     */
    abstract protected function pluginManifest(): array;

    abstract protected function pluginId(): string;

    abstract protected function expectedVersion(): string;

    private ?string $previousRequireSignature = null;

    protected function setUp(): void
    {
        // TEST-ENV ONLY: allow installing an unsigned `untrusted` fixture so the
        // lifecycle is certifiable without signing infrastructure. This is the
        // verifier's documented dev/test opt-out (PluginSignatureVerifier) — it
        // does NOT change production behaviour (the default stays strict=true).
        $this->previousRequireSignature = getenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE') ?: null;
        putenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE=false');
        $_ENV['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = 'false';
        $_SERVER['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = 'false';

        parent::setUp();
        // Reuse one kernel/container for every request in a test so the
        // flock lock acquired during install lives on the same
        // PluginOperationLock instance we release in tearDown.
        $this->client->disableReboot();
        $this->ensureAdminAvailable();
        $this->releaseOperationLock();
        $this->cancelActiveOperations();
    }

    protected function tearDown(): void
    {
        $this->cancelActiveOperations();
        $this->releaseOperationLock();
        parent::tearDown();

        if ($this->previousRequireSignature === null) {
            putenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE');
            unset($_ENV['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'], $_SERVER['SELFHELP_PLUGIN_REQUIRE_SIGNATURE']);
        } else {
            putenv('SELFHELP_PLUGIN_REQUIRE_SIGNATURE=' . $this->previousRequireSignature);
            $_ENV['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = $this->previousRequireSignature;
            $_SERVER['SELFHELP_PLUGIN_REQUIRE_SIGNATURE'] = $this->previousRequireSignature;
        }
    }

    public function testManifestPassesValidationAndRecordsAManagedInstallOperation(): void
    {
        $op = $this->requestInstall();

        self::assertIsInt($op['id'] ?? null, 'install must record an operation id');
        self::assertSame($this->pluginId(), $op['pluginId'], 'operation must be scoped to this plugin');
        self::assertSame('install', $op['type'], 'operation type must be install');
        self::assertContains(
            $op['status'],
            ['requested', 'running'],
            'a freshly requested managed install is requested/running until a CLI worker finalizes it',
        );
        self::assertSame(
            'install_dispatched',
            $op['installAction'] ?? null,
            'install must dispatch (not short-circuit to already_installed) for a fresh fixture',
        );
        self::assertNotSame(
            'development',
            $op['installMode'] ?? null,
            'non-dev environments must resolve to a managed (worker-finalized) install mode',
        );
    }

    public function testRecordedInstallOperationIsVisibleViaTheOperationsApi(): void
    {
        $op = $this->requestInstall();
        $operationId = $this->asInt($op['id']);

        $this->client->request(
            'GET',
            '/cms-api/v1/admin/plugins/operations/' . $operationId,
            [], [],
            $this->adminHeaders(),
        );
        self::assertResponseIsSuccessful();
        $data = $this->asArray($this->decodeArray()['data'] ?? null);
        self::assertSame($operationId, $data['id'] ?? null);
        self::assertSame($this->pluginId(), $data['pluginId'] ?? null);
    }

    public function testConcurrencyGuardRejectsASecondOperationAndCancelClearsIt(): void
    {
        $op = $this->requestInstall();
        $operationId = $this->asInt($op['id']);

        // A second lifecycle action while one is active must be refused by the
        // concurrency guard (public, observable contract — Rule 18 negatives).
        $this->client->request('POST', $this->base() . '/disable', [], [], $this->adminHeaders());
        $blocked = $this->client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(
            400,
            $blocked,
            'a second operation must be rejected while an install is active. Body: '
                . $this->client->getResponse()->getContent(),
        );

        // Cancelling the active operation clears the guard.
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/operations/' . $operationId . '/cancel',
            [], [],
            $this->adminHeaders(),
        );
        self::assertResponseIsSuccessful();
        $cancelled = $this->asArray($this->decodeArray()['data'] ?? null);
        self::assertSame('cancelled', $cancelled['status'] ?? null, 'cancel must mark the operation cancelled');
    }

    // --- helpers -----------------------------------------------------------

    protected function base(): string
    {
        return '/cms-api/v1/admin/plugins/' . $this->pluginId();
    }

    /** @return array<string,string> */
    protected function adminHeaders(): array
    {
        return [
            'HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken(),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    protected function ensureAdminAvailable(): void
    {
        try {
            $this->getAdminAccessToken();
        } catch (\Throwable $e) {
            self::markTestSkipped('Admin login not available (run composer test:reset-db): ' . $e->getMessage());
        }
    }

    /**
     * POST the manifest through the real install endpoint and return the
     * recorded operation payload (the `data` object). Asserts 202 Accepted.
     *
     * @return array<string,mixed>
     */
    protected function requestInstall(): array
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/install',
            [], [],
            $this->adminHeaders(),
            (string) json_encode(['source' => 'paste', 'manifest' => $this->pluginManifest()]),
        );
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $this->client->getResponse()->getStatusCode(),
            'install must return 202 Accepted (manifest passed signature + compatibility + capability checks). Body: '
                . $this->client->getResponse()->getContent(),
        );

        return $this->asArray($this->decodeArray()['data'] ?? null, 'install must return a plugin_operation data object');
    }

    /**
     * Cancel every still-active operation for this plugin so the DB-layer
     * concurrency guard starts each test clean (DAMA rolls the rows back
     * anyway, but cancelling keeps a single test's repeated installs sane).
     */
    protected function cancelActiveOperations(): void
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
        $operations = $this->decodeArray()['data'] ?? [];
        if (!is_array($operations)) {
            return;
        }
        foreach ($operations as $op) {
            if (!is_array($op)) {
                continue;
            }
            if (($op['pluginId'] ?? null) !== $this->pluginId()) {
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
     * Release the in-process flock distributed lock. In managed mode the
     * lock is held from request until the CLI worker finalizes (which never
     * runs in tests), so we release it explicitly to keep the suite
     * order-independent. Best-effort: the DB-layer guard is the fail-safe.
     */
    protected function releaseOperationLock(): void
    {
        try {
            $lock = self::getContainer()->get(PluginOperationLock::class);
        } catch (\Throwable) {
            return;
        }
        if ($lock instanceof PluginOperationLock) {
            $lock->release($this->pluginId());
        }
    }
}
