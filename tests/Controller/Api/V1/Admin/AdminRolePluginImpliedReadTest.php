<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Permission;
use App\Entity\Role;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\CallerHoldingsService;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rule 4.13 for a plugin's permissions (contract C2 of the role scope, now also for plugins):
 * whoever may change something in an area may also read it. A role given
 * `<prefix>.<area>.<action>` also holds `<prefix>.<area>.read`; removing that read removes the
 * other rights of the area; a name that only looks alike (owned by core, by another plugin, or
 * with no read of the same plugin) implies nothing.
 *
 * The QA plugin ({@see QaItemPluginFixture}) owns `qa_plugin.items.read`, `.create`, `.update`
 * and `.delete`.
 */
#[Group('security')]
final class AdminRolePluginImpliedReadTest extends QaWebTestCase
{
    private const ROLES_URI = '/cms-api/v1/admin/roles';
    private const PERMS_URI = '/cms-api/v1/admin/permissions';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find this plugin or its permissions.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testAGivenPluginRightBringsTheReadOfTheSameArea(): void
    {
        $token = $this->loginAsQaAdmin();

        $created = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::ROLES_URI,
            ['name' => 'qa_plugin_implied_create', 'permission_ids' => [$this->permissionId(QaItemPluginFixture::PERMISSION_UPDATE)]],
            $token,
        ), 201);
        $roleId = self::coerceInt($created['id'] ?? null);
        self::assertSame(
            [QaItemPluginFixture::PERMISSION_READ, QaItemPluginFixture::PERMISSION_UPDATE],
            $this->rolePermissionNames($roleId),
            'creating a role with `update` also gives it `read`, and nothing else',
        );

        // Replacing the list with `delete` alone (no read in the request) still ends with the read.
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->permissionId(QaItemPluginFixture::PERMISSION_DELETE)]],
            $token,
        ));
        self::assertSame(
            [QaItemPluginFixture::PERMISSION_DELETE, QaItemPluginFixture::PERMISSION_READ],
            $this->rolePermissionNames($roleId),
        );

        // Adding `create` to the role adds nothing else: the read is already there.
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->permissionId(QaItemPluginFixture::PERMISSION_CREATE)]],
            $token,
        ));
        self::assertSame(
            [QaItemPluginFixture::PERMISSION_CREATE, QaItemPluginFixture::PERMISSION_DELETE, QaItemPluginFixture::PERMISSION_READ],
            $this->rolePermissionNames($roleId),
        );
    }

    public function testRemovingTheReadOfAPluginAreaRemovesTheOtherRightsOfThatAreaOnly(): void
    {
        $token = $this->loginAsQaAdmin();
        $roleId = $this->roleWith('qa_plugin_implied_remove', [
            QaItemPluginFixture::PERMISSION_UPDATE,
            QaItemPluginFixture::PERMISSION_DELETE,
            'admin.group.read',
        ], $token);
        self::assertContains(QaItemPluginFixture::PERMISSION_READ, $this->rolePermissionNames($roleId));

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'DELETE',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->permissionId(QaItemPluginFixture::PERMISSION_READ)]],
            $token,
        ));

        self::assertSame(['admin.group.read'], $this->rolePermissionNames($roleId), 'the rights of the plugin area go with its read; other areas stay');
    }

    public function testRemovingAnotherRightKeepsTheRead(): void
    {
        $token = $this->loginAsQaAdmin();
        $roleId = $this->roleWith('qa_plugin_implied_keep', [QaItemPluginFixture::PERMISSION_UPDATE, QaItemPluginFixture::PERMISSION_DELETE], $token);

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'DELETE',
            self::ROLES_URI . '/' . $roleId . '/permissions',
            ['permission_ids' => [$this->permissionId(QaItemPluginFixture::PERMISSION_UPDATE)]],
            $token,
        ));

        self::assertSame(
            [QaItemPluginFixture::PERMISSION_DELETE, QaItemPluginFixture::PERMISSION_READ],
            $this->rolePermissionNames($roleId),
        );
    }

    public function testANameThatOnlyLooksLikeAPluginAreaImpliesNothing(): void
    {
        // Same prefix and area as the QA plugin, but no plugin owns the row: a lookalike.
        $this->createPermission('qa_plugin.items.export');
        // The QA plugin owns an area with no read at all.
        $this->createPermission('qa_plugin.reports.update');
        $holdings = $this->service(CallerHoldingsService::class);

        self::assertSame(QaItemPluginFixture::PERMISSION_READ, $holdings->impliedReadOf(QaItemPluginFixture::PERMISSION_UPDATE));
        self::assertSame(QaItemPluginFixture::PERMISSION_READ, $holdings->impliedReadOf(QaItemPluginFixture::PERMISSION_CREATE));
        self::assertNull($holdings->impliedReadOf(QaItemPluginFixture::PERMISSION_READ), 'a read implies nothing');
        self::assertNull($holdings->impliedReadOf('qa_plugin.items.export'), 'a row no plugin owns implies nothing');
        self::assertNull($holdings->impliedReadOf('qa_plugin.reports.update'), 'no read of that area exists');
        self::assertNull($holdings->impliedReadOf('qa_plugin.update'), 'only <prefix>.<area>.<action> names imply a read');
        self::assertSame('admin.group.read', $holdings->impliedReadOf('admin.group.acl'), 'the core rule is unchanged');

        $token = $this->loginAsQaAdmin();
        $roleId = $this->roleWith('qa_plugin_implied_lookalike', ['qa_plugin.items.export'], $token);
        self::assertSame(['qa_plugin.items.export'], $this->rolePermissionNames($roleId), 'no read is added for a lookalike');
    }

    public function testThePermissionListNamesTheImpliedReadOfEachPermission(): void
    {
        $token = $this->loginAsQaAdmin();

        $list = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::PERMS_URI, null, $token));
        $byName = [];
        foreach ($this->asList($list['permissions'] ?? null) as $item) {
            $row = $this->asArray($item);
            $byName[self::coerceString($row['name'] ?? null)] = $row;
        }

        foreach ([QaItemPluginFixture::PERMISSION_READ, QaItemPluginFixture::PERMISSION_UPDATE, QaItemPluginFixture::PERMISSION_DELETE] as $name) {
            self::assertArrayHasKey($name, $byName, 'the plugin permission is listed');
            self::assertArrayHasKey('implied_read', $byName[$name]);
        }
        self::assertSame(QaItemPluginFixture::PERMISSION_READ, $byName[QaItemPluginFixture::PERMISSION_UPDATE]['implied_read']);
        self::assertSame(QaItemPluginFixture::PERMISSION_READ, $byName[QaItemPluginFixture::PERMISSION_DELETE]['implied_read']);
        self::assertNull($byName[QaItemPluginFixture::PERMISSION_READ]['implied_read'], 'a read implies nothing');
    }

    // -- helpers -------------------------------------------------------------------------

    /**
     * @param list<string> $permissionNames
     */
    private function roleWith(string $name, array $permissionNames, string $token): int
    {
        $created = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::ROLES_URI,
            ['name' => $name, 'permission_ids' => array_map(fn (string $permission): int => $this->permissionId($permission), $permissionNames)],
            $token,
        ), 201);

        return self::coerceInt($created['id'] ?? null);
    }

    private function createPermission(string $name): void
    {
        $permission = new Permission();
        $permission->setName($name);
        $permission->setDescription('QA lookalike');
        $this->em->persist($permission);
        $this->em->flush();
    }

    private function permissionId(string $name): int
    {
        $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Permission::class, $permission, 'Missing permission ' . $name);

        return (int) $permission->getId();
    }

    /**
     * @return list<string>
     */
    private function rolePermissionNames(int $roleId): array
    {
        $this->em->clear();
        $role = $this->em->getRepository(Role::class)->find($roleId);
        self::assertInstanceOf(Role::class, $role);
        $names = [];
        foreach ($role->getPermissions() as $permission) {
            $names[] = $permission->getName();
        }
        sort($names);

        return $names;
    }
}
