<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional + security coverage for the instance-scoped system maintenance /
 * update API ({@see \App\Controller\Api\V1\Admin\SystemController}, SelfHelp
 * Manager / Docker Distribution MVP).
 *
 * Asserts the hard rules the plan mandates:
 *   - all four routes are admin-only (matrix: admin 2xx, non-admin 403, anon 401);
 *   - the version/preflight/status payloads match the shared contract shape;
 *   - an update request NEVER trusts a client-supplied instance id — a body
 *     carrying `instance_id` is denied with 403 (and logged) BEFORE schema
 *     validation, even for an otherwise-authorised admin;
 *   - a valid request returns 202 and the status endpoint reflects it.
 *
 * Uses the seeded qa.admin persona; DAMA rolls back the operation rows.
 *
 * Determinism: the test env pins the registry base URL to a closed local port
 * (services.yaml `when@test`), so every registry-backed endpoint (advisories,
 * preflight, update releases) degrades offline the SAME way in CI and locally —
 * tests never reach the live public registry (Testing Rule 14). A target far
 * above any real version keeps the preflight non-blocked offline (registry
 * unreachable -> warning), so the happy path is deterministic too.
 */
#[Group('security')]
final class SystemControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/system';
    private const TARGET = '999.0.0';

    public function testVersionIsAdminOnlyAndExposesInstanceSummary(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/version');

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/version', null, $this->loginAsQaAdmin())
        );

        foreach ([
            'instance_id', 'selfhelp_version', 'backend_version', 'frontend_version',
            'plugin_api_version', 'database_migration_version', 'deployment', 'safe_mode',
            'maintenance_mode', 'installed_plugins',
        ] as $key) {
            self::assertArrayHasKey($key, $data, "Version summary must expose '{$key}'.");
        }
        self::assertIsString($data['instance_id']);
        self::assertNotSame('', $data['instance_id'], 'Instance id is server-derived and never empty.');
        self::assertIsArray($data['installed_plugins']);
        self::assertContains(
            $data['deployment'],
            ['source', 'docker'],
            'Deployment marker distinguishes the production Docker image from a source/dev checkout.'
        );
    }

    public function testUpdateReleasesIsAdminOnlyAndDegradesGracefullyOffline(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/update/releases');

        // The registry base URL is pinned to a closed local port in the test
        // env (services.yaml when@test), so the picker degrades to "manual
        // entry" deterministically instead of fetching the live registry.
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/update/releases', null, $this->loginAsQaAdmin())
        );

        self::assertArrayHasKey('available', $data);
        self::assertArrayHasKey('current_version', $data);
        self::assertArrayHasKey('releases', $data);
        self::assertFalse($data['available'], 'Offline registry must degrade the release list to unavailable.');
        self::assertSame([], $data['releases']);
        self::assertIsString($data['current_version']);
        self::assertNotSame('', $data['current_version']);
    }

    public function testPreflightIsAdminOnlyAndReturnsCompatibilityVerdict(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/update/preflight?target=' . self::TARGET);

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/update/preflight?target=' . self::TARGET, null, $this->loginAsQaAdmin())
        );

        self::assertContains($data['status'], ['ok', 'warning', 'blocked']);
        self::assertSame(self::TARGET, $data['target_version']);
        self::assertIsArray($data['checks']);
        self::assertIsArray($data['database']);
        self::assertIsArray($data['rollback']);

        // The CMS always declares that resource/Docker checks belong to the manager.
        $codes = [];
        foreach ($data['checks'] as $check) {
            if (is_array($check) && isset($check['code'])) {
                $codes[] = $check['code'];
            }
        }
        self::assertContains('resource_checks', $codes, 'Preflight must flag that Docker/resource checks run in the manager.');
    }

    public function testAdvisoriesIsAdminOnlyAndDegradesGracefullyOffline(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/advisories');

        // The registry base URL is pinned to a closed local port in the test
        // env, so the feed fails soft: the endpoint reports it could not check
        // rather than blocking (and never fetches the live advisory feed).
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/advisories', null, $this->loginAsQaAdmin())
        );

        self::assertArrayHasKey('available', $data);
        self::assertArrayHasKey('advisories', $data);
        self::assertIsBool($data['available']);
        self::assertIsArray($data['advisories']);
        self::assertFalse($data['available'], 'Offline registry must degrade advisories to unavailable.');
        self::assertSame([], $data['advisories']);
    }

    public function testPreflightRequiresTargetQueryParam(): void
    {
        $this->assertEnvelope400(
            $this->jsonRequest('GET', self::BASE . '/update/preflight', null, $this->loginAsQaAdmin())
        );
    }

    public function testStatusIsAdminOnlyAndDefaultsToNoActiveOperation(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/update/status');

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/update/status', null, $this->loginAsQaAdmin())
        );

        self::assertArrayHasKey('instance_id', $data);
        self::assertArrayHasKey('operation_id', $data);
        self::assertArrayHasKey('status', $data);
        self::assertSame('', $data['operation_id'], 'With no operation requested, operation_id is empty.');
    }

    public function testUpdateRequestIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BASE . '/update/request', [
            'target_version' => self::TARGET,
            'preflight_id' => 'pf_test',
            'accepted_migration_risk' => false,
        ]);
    }

    public function testUpdateRequestDeniesClientSuppliedInstanceId(): void
    {
        // Even an authorised admin cannot target another instance: the body's
        // instance_id is rejected with 403 before schema validation.
        $envelope = $this->jsonRequest('POST', self::BASE . '/update/request', [
            'target_version' => self::TARGET,
            'preflight_id' => 'pf_test',
            'accepted_migration_risk' => false,
            'instance_id' => 'some-other-instance',
        ], $this->loginAsQaAdmin());

        $this->assertEnvelope403($envelope);
    }

    public function testUpdateRequestAcceptsAndStatusReflectsIt(): void
    {
        $token = $this->loginAsQaAdmin();

        $accepted = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . '/update/request', [
                'target_version' => self::TARGET,
                'preflight_id' => 'pf_test',
                'accepted_migration_risk' => false,
            ], $token),
            Response::HTTP_ACCEPTED
        );

        self::assertArrayHasKey('operation_id', $accepted);
        self::assertIsString($accepted['operation_id']);
        self::assertNotSame('', $accepted['operation_id']);
        self::assertSame('requested', $accepted['status']);

        $status = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/update/status', null, $token)
        );
        self::assertSame($accepted['operation_id'], $status['operation_id'], 'Status must report the just-requested operation.');
        self::assertSame(self::TARGET, $status['target_version']);
        self::assertSame('requested', $status['status']);
    }

    public function testMaintenanceReadIsAdminOnlyAndDefaultsToDisabled(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/maintenance');

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/maintenance', null, $this->loginAsQaAdmin())
        );

        foreach (['enabled', 'forced_by_env', 'message', 'since', 'updated_by', 'safe_mode'] as $key) {
            self::assertArrayHasKey($key, $data, "Maintenance state must expose '{$key}'.");
        }
        self::assertIsBool($data['enabled']);
        self::assertIsBool($data['forced_by_env']);
        self::assertIsBool($data['safe_mode']);
    }

    public function testMaintenanceWriteIsForbiddenForNonAdmins(): void
    {
        // PUT under admin.system.maintenance: non-admin 403, anon 401.
        $this->assertForbiddenForNonAdmins('PUT', self::BASE . '/maintenance', [
            'enabled' => true,
            'message' => 'qa maintenance window',
        ]);
    }

    public function testMaintenanceEnableThenDisableRoundTrips(): void
    {
        $token = $this->loginAsQaAdmin();

        // Enable with an operator note.
        $enabled = $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . '/maintenance', [
                'enabled' => true,
                'message' => 'qa upgrade window',
            ], $token)
        );
        self::assertTrue($enabled['enabled']);
        self::assertSame('qa upgrade window', $enabled['message']);
        self::assertNotSame('', $enabled['since']);
        self::assertNotSame('', $enabled['updated_by']);

        // Health/version now reflect maintenance mode (live resolution).
        $version = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/version', null, $token)
        );
        self::assertTrue($version['maintenance_mode'], 'Version summary must reflect the live maintenance toggle.');

        // Disable again (clean up the QA state so we never leave the test DB host
        // in maintenance — the file lives under the app, not the DB).
        $disabled = $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . '/maintenance', ['enabled' => false], $token)
        );
        self::assertFalse($disabled['enabled']);
    }

    public function testMaintenanceWriteRejectsClientSuppliedInstanceId(): void
    {
        // additionalProperties:false rejects any unknown field (incl. instance_id)
        // with a 400 schema error, even for an authorised admin.
        $this->assertEnvelope400(
            $this->jsonRequest('PUT', self::BASE . '/maintenance', [
                'enabled' => true,
                'instance_id' => 'some-other-instance',
            ], $this->loginAsQaAdmin())
        );
    }
}
