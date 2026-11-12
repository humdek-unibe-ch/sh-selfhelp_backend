<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\DataAccessAudit;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
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

    /**
     * Deleting a user (or cleaning their data) keeps their audit rows and only
     * clears the link to the person. The read API must still serve such a row,
     * with the user fields empty, and the response must still match its schema.
     */
    public function testAuditRowKeptWithoutItsUserIsStillServed(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $lookups = $this->service(LookupService::class);
        $resourceType = $lookups->findByTypeAndCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_PAGES);
        $action = $lookups->findByTypeAndCode(LookupService::AUDIT_ACTIONS, LookupService::AUDIT_ACTIONS_READ);
        $result = $lookups->findByTypeAndCode(LookupService::PERMISSION_RESULTS, LookupService::PERMISSION_RESULTS_DENIED);
        self::assertNotNull($resourceType);
        self::assertNotNull($action);
        self::assertNotNull($result);

        $audit = new DataAccessAudit();
        $audit->setUser(null);
        $audit->setResourceType($resourceType);
        $audit->setResourceId(1);
        $audit->setAction($action);
        $audit->setPermissionResult($result);
        $audit->setHttpMethod('GET');
        $audit->setNotes('qa_audit_row_without_user');
        $em->persist($audit);
        $em->flush();
        $auditId = (int) $audit->getId();

        $admin = $this->loginAsQaAdmin();

        $detail = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/audit/data-access/' . $auditId, null, $admin)
        );
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false);
        self::assertIsObject($decoded, 'Response body must be a JSON object.');
        $errors = $this->service(JsonSchemaValidationService::class)->validate($decoded, 'responses/admin/audit/data_access_log');
        self::assertSame([], $errors, "The detail response must match its published schema:\n" . implode("\n", $errors));
        self::assertSame($auditId, $detail['id']);
        self::assertNull($detail['idUsers'], 'The row is kept; the person is gone');
        $user = $this->asArray($detail['user']);
        self::assertNull($user['id']);
        self::assertNull($user['username']);
        self::assertNull($user['email']);

        // The list must not choke on such a row either (its schema is enforced on the way out).
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/audit/data-access?pageSize=100', null, $admin)
        );
    }

    #[Group('security')]
    public function testAuditLogsEnforceAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/audit/data-access');
    }
}
