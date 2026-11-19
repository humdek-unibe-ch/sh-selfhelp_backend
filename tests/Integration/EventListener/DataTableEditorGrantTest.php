<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\CreatorGrantService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F15: when a form section creates its data table, {@see \App\EventListener\DataTableAdminAccessListener}
 * grants full CRUD to the admin role and to editor roles that may update the page.
 */
#[PhpUnitGroup('integration')]
final class DataTableEditorGrantTest extends QaWebTestCase
{
    private const EDITOR_EMAIL = 'qa.f15.editor@selfhelp.test';
    private const EDITOR_ROLE = 'qa_f15_editor_role';
    private const READER_ROLE = 'qa_f15_reader_role';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private RoleDataAccessFactory $grants;
    private string $editorToken;
    private int $editorRoleId;
    private int $readerRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );

        $editorRole = $roles->createRole(self::EDITOR_ROLE);
        $readerRole = $roles->createRole(self::READER_ROLE);
        foreach (['admin.access', 'admin.page.read', 'admin.page.update', 'admin.data.read'] as $permission) {
            $roles->grantPermission($editorRole, $permission);
        }
        foreach (['admin.access', 'admin.page.read', 'admin.page.update', 'admin.data.read'] as $permission) {
            $roles->grantPermission($readerRole, $permission);
        }

        $users->createUser(self::EDITOR_EMAIL, 'QA F15 Editor', roles: [$editorRole, $readerRole]);
        $this->editorRoleId = (int) $editorRole->getId();
        $this->readerRoleId = (int) $readerRole->getId();
        $this->editorToken = $this->loginAs(self::EDITOR_EMAIL);
    }

    public function testEditorWithPageUpdateGetsTableGrantAfterFormSubmit(): void
    {
        $keyword = 'qa_f15_editor_page';
        $page = $this->pages->createPage($keyword, openAccess: true);
        $pageId = (int) $page->getId();
        $this->grants->grantPageAccess(
            $this->role(self::EDITOR_ROLE),
            $pageId,
            DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE,
        );
        $this->grants->grantPageAccess(
            $this->role(self::READER_ROLE),
            $pageId,
            DataAccessSecurityService::PERMISSION_READ,
        );

        $section = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                '/cms-api/v1/admin/pages/' . $pageId . '/sections/create',
                ['styleId' => $this->styleId('form-record'), 'position' => 10, 'name' => 'qa_f15_form'],
                $this->editorToken,
            ),
            Response::HTTP_CREATED,
        );
        $sectionId = self::asInt($section['id'] ?? null);

        $submit = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => $pageId,
            'section_id' => $sectionId,
            'form_data' => ['qa_field' => 'f15 value'],
        ]));
        $recordId = self::asInt($submit['record_id'] ?? null);
        self::assertGreaterThan(0, $recordId);

        $tableId = self::coerceInt($this->em->getConnection()->fetchOne(
            'SELECT id FROM data_tables WHERE name = :name',
            ['name' => (string) $sectionId],
        ));
        self::assertGreaterThan(0, $tableId);

        self::assertSame(
            CreatorGrantService::FULL_ACCESS,
            $this->tableGrantCrud($this->editorRoleId, $tableId),
            'Editor role with page update must receive full table CRUD.',
        );
        self::assertNull(
            $this->tableGrant($this->readerRoleId, $tableId),
            'Read-only page grant must not receive a table grant.',
        );
        self::assertSame(
            CreatorGrantService::FULL_ACCESS,
            $this->tableGrantCrud($this->adminRoleId(), $tableId),
            'Admin grant must still exist on the new table.',
        );

        $tables = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/data/tables', null, $this->editorToken),
        );
        $names = array_column(self::asList($tables['dataTables'] ?? null), 'name');
        self::assertContains((string) $sectionId, $names, 'Editor must see the new table in Data Management.');

        $rows = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'GET',
                '/cms-api/v1/admin/data?table_name=' . $sectionId,
                null,
                $this->editorToken,
            ),
        );
        self::assertNotEmpty(self::asList($rows['rows'] ?? null), 'Editor must read rows from the granted table.');
    }

    private function tableGrantCrud(int $roleId, int $tableId): ?int
    {
        return $this->tableGrant($roleId, $tableId)?->getCrudPermissions();
    }

    private function tableGrant(int $roleId, int $tableId): ?RoleDataAccess
    {
        $this->em->clear();
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_DATA_TABLE,
        );
        self::assertNotNull($typeId);
        $grant = $this->em->getRepository(RoleDataAccess::class)->findOneBy([
            'idRoles' => $roleId,
            'idResourceTypes' => $typeId,
            'resourceId' => $tableId,
        ]);

        return $grant instanceof RoleDataAccess ? $grant : null;
    }

    private function adminRoleId(): int
    {
        $admin = $this->em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $admin);

        return (int) $admin->getId();
    }

    private function role(string $name): Role
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Role::class, $role);

        return $role;
    }

    private function styleId(string $name): int
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Style::class, $style);

        return (int) $style->getId();
    }
}