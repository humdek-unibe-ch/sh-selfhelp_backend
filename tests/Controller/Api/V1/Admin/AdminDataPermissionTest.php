<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\CMS\DataService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Permission matrix for the admin Data-Management routes (plan §26/§29).
 *
 * Every `/admin/data/*` route is guarded by an `admin.data.*` route permission
 * held only by the seeded admin role, so the matrix is the canonical
 * admin-only one: admin → success, every authenticated non-admin → 403,
 * anonymous → 401. Read routes use the full matrix (admin success asserted);
 * destructive routes use the negative-only matrix so the test never mutates
 * data — the success paths are covered by {@see AdminDataControllerTest}.
 */
#[Group('security')]
final class AdminDataPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/data';

    private int $recordId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = new DataTableFactory(
            $this->service(EntityManagerInterface::class),
            $this->service(DataService::class),
        );
        // qa.admin needs a real, accessible table for the read success calls.
        $this->recordId = $factory->createTableWithRow('qa_perm_data_table', 1)[1];
    }

    public function testReadRoutesAreAdminOnly(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/tables');
        $this->assertAdminOnlyMatrix('GET', self::BASE . '?table_name=qa_perm_data_table');
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/tables/qa_perm_data_table/columns');
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/tables/qa_perm_data_table/column-names');
    }

    public function testDestructiveRoutesAreForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins(
            'DELETE',
            self::BASE . '/records/' . $this->recordId . '?table_name=qa_perm_data_table',
        );
        $this->assertForbiddenForNonAdmins('DELETE', self::BASE . '/tables/qa_perm_data_table');
        $this->assertForbiddenForNonAdmins(
            'DELETE',
            self::BASE . '/tables/qa_perm_data_table/columns',
            ['columns' => ['qa_field']],
        );
    }
}
