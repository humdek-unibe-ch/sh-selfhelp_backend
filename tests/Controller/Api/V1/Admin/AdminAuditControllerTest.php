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

/**
 * Coverage for the admin data-access audit API (plan Phase 6 — audit logs).
 *
 * The audit endpoints expose the data-access security trail and are protected
 * by the admin-only `admin.audit.view` permission. These tests assert the
 * read contract as qa.admin plus the negative-permission matrix (security).
 */
final class AdminAuditControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testGetDataAccessLogsReturnsPaginatedEnvelope(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/audit/data-access', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('data', $data, 'Audit log list must expose a data array');
        self::assertIsArray($data['data']);
        foreach (['total', 'page', 'pageSize', 'totalPages'] as $key) {
            self::assertArrayHasKey($key, $data, "Audit log list must expose pagination key '{$key}'");
        }
    }

    public function testGetDataAccessStatsReturnsSummary(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/audit/data-access/stats', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        foreach (['totalLogs', 'deniedAttempts', 'uniqueResources', 'uniqueUsers'] as $key) {
            self::assertArrayHasKey($key, $data, "Audit stats must expose summary key '{$key}'");
        }
    }

    public function testUnknownAuditLogReturnsNotFound(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/audit/data-access/999999', null, $this->loginAsQaAdmin());

        $this->assertEnvelope404($envelope);
    }

    #[Group('security')]
    public function testAuditLogsEnforceAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/audit/data-access');
    }
}
