<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Manifest\PluginManifest;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Plugin\QaResourceTypeSubscriber;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * H6: a plugin adds a data-access resource type; the "Permissions for role"
 * screen lists the type and its resources, and grants on it are checked like
 * grants on pages.
 */
#[Group('security')]
final class AdminPluginResourceTypeControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const TYPES_URI = '/cms-api/v1/admin/data-access/plugin-resource-types';
    private const TYPE_CODE = QaResourceTypeSubscriber::TYPE_CODE;

    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->service(EntityManagerInterface::class);
        $this->plugin = new Plugin('qa-resource-plugin', 'QA resource plugin', '1.0.0', '0.1.0');
        $this->plugin->setEnabled(true);
        $em->persist($this->plugin);
        $em->flush();

        // The type arrives the way a real plugin's does: declared in the
        // manifest and written by the installer (rule 7.8).
        $this->service(PluginCatalogueSynchronizer::class)->sync($this->plugin, new PluginManifest([
            'id' => 'qa-resource-plugin',
            'lookups' => ['extends' => [[
                'typeCode' => LookupService::RESOURCE_TYPES,
                'ownership' => 'plugin_extendable',
                'entries' => [['code' => self::TYPE_CODE, 'value' => 'QA survey']],
            ]]],
        ]));
        // As the installer does after the catalogue sync.
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
    }

    public function testTheScreenListsThePluginTypeWithItsResources(): void
    {
        $types = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::TYPES_URI, null, $this->loginAsQaAdmin()));
        $declared = array_values(array_filter(
            $types,
            static fn (mixed $type): bool => is_array($type) && ($type['code'] ?? null) === self::TYPE_CODE
        ));
        self::assertCount(1, $declared);
        self::assertSame('QA survey', $declared[0]['label'] ?? null);
        self::assertSame('qa-resource-plugin', $declared[0]['plugin_id'] ?? null);

        $resources = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            self::TYPES_URI . '/' . self::TYPE_CODE . '/resources',
            null,
            $this->loginAsQaAdmin()
        ));
        self::assertSame([
            ['id' => 3, 'name' => 'QA first survey', 'description' => 'qa description'],
            ['id' => 7, 'name' => 'QA second survey', 'description' => null],
        ], $resources, 'resources come from the plugin, sorted by name');
    }

    public function testAnUnknownTypeIsNotFound(): void
    {
        $this->assertEnvelope404($this->jsonRequest('GET', self::TYPES_URI . '/qa_unknown_type/resources', null, $this->loginAsQaAdmin()));
    }

    public function testATypeOfADisabledPluginIsHidden(): void
    {
        $this->plugin->setEnabled(false);
        $this->service(EntityManagerInterface::class)->flush();

        $this->assertEnvelope404($this->jsonRequest('GET', self::TYPES_URI . '/' . self::TYPE_CODE . '/resources', null, $this->loginAsQaAdmin()));
    }

    public function testOnlyRoleAdminsMayReadTheTypesAndResources(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::TYPES_URI);
        $this->assertAdminOnlyMatrix('GET', self::TYPES_URI . '/' . self::TYPE_CODE . '/resources');
    }

    public function testAGrantOnAPluginResourceIsCheckedLikeAGrantOnAPage(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $editorId = $em->getConnection()->fetchOne('SELECT id FROM users WHERE email = ?', ['qa.editor@selfhelp.test']);
        self::assertIsNumeric($editorId);
        $dataAccess = $this->service(DataAccessSecurityService::class);

        self::assertFalse($dataAccess->hasPermission((int) $editorId, self::TYPE_CODE, 3, DataAccessSecurityService::PERMISSION_UPDATE));

        $connection = $em->getConnection();
        $connection->insert('roles', ['name' => 'qa_resource_role', 'description' => 'QA role with a grant on a plugin resource']);
        $roleId = (int) $connection->lastInsertId();
        $connection->insert('rel_roles_users', ['id_roles' => $roleId, 'id_users' => (int) $editorId]);
        $inserted = $connection->executeStatement(
            'INSERT INTO role_data_access (id_roles, id_resource_types, resource_id, crud_permissions, created_at, updated_at) '
            . 'SELECT ?, l.id, 3, ?, NOW(), NOW() FROM lookups l WHERE l.type_code = ? AND l.lookup_code = ?',
            [$roleId, DataAccessSecurityService::PERMISSION_UPDATE, LookupService::RESOURCE_TYPES, self::TYPE_CODE]
        );
        self::assertSame(1, $inserted);
        $dataAccess->invalidateUserPermissions((int) $editorId);

        self::assertTrue($dataAccess->hasPermission((int) $editorId, self::TYPE_CODE, 3, DataAccessSecurityService::PERMISSION_UPDATE));
        self::assertFalse($dataAccess->hasPermission((int) $editorId, self::TYPE_CODE, 7, DataAccessSecurityService::PERMISSION_UPDATE), 'the grant is per resource');
    }
}
