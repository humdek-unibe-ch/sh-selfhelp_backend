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
 * A target far above any real version keeps the preflight non-blocked offline
 * (registry unreachable -> warning), so the happy path is deterministic in CI.
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
            'plugin_api_version', 'database_migration_version', 'safe_mode',
            'maintenance_mode', 'installed_plugins',
        ] as $key) {
            self::assertArrayHasKey($key, $data, "Version summary must expose '{$key}'.");
        }
        self::assertIsString($data['instance_id']);
        self::assertNotSame('', $data['instance_id'], 'Instance id is server-derived and never empty.');
        self::assertIsArray($data['installed_plugins']);
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
}
