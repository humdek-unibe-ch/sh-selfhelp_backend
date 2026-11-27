<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Plugin\Lifecycle\PluginCatalogueOwnership;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Lifecycle\PluginPurger;
use App\Plugin\Manifest\PluginManifest;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;

/**
 * Rule 7.8 / F112: the installer writes a plugin's catalogue rows from
 * its manifest, tagged with the plugin id, and purge removes them.
 *
 * @phpstan-type CatalogueManifest array{
 *     id: string,
 *     styleGroups: list<array<string,mixed>>,
 *     fieldTypes: list<array<string,mixed>>,
 *     fields: list<array<string,mixed>>,
 *     styles: list<array<string,mixed>>,
 *     permissions: list<array<string,mixed>>,
 *     lookups: array{extends: list<array{typeCode: string, ownership: string, entries: list<array<string,string>>}>}
 * }
 */
final class PluginCatalogueSynchronizerTest extends QaKernelTestCase
{
    private const PLUGIN_ID = 'qa-catalogue-plugin';

    private PluginCatalogueSynchronizer $synchronizer;
    private Connection $connection;
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synchronizer = $this->service(PluginCatalogueSynchronizer::class);
        $this->connection = $this->service(Connection::class);
        $this->plugin = new Plugin(self::PLUGIN_ID, 'QA catalogue plugin', '1.0.0', '0.1.0');
        $this->em->persist($this->plugin);
        $this->em->flush();
    }

    public function testInstallWritesEveryDeclaredCatalogueRowTaggedWithThePlugin(): void
    {
        $this->synchronizer->sync($this->plugin, $this->manifest());

        $pluginRowId = $this->plugin->getId();
        self::assertSame($pluginRowId, $this->owner('style_groups', 'qa-catalogue-group'));
        self::assertSame($pluginRowId, $this->owner('field_types', 'qa-catalogue-type'));
        self::assertSame($pluginRowId, $this->owner('fields', 'qa_catalogue_field'));
        self::assertSame($pluginRowId, $this->owner('styles', 'qa-catalogue-style'));
        self::assertSame($pluginRowId, $this->owner('permissions', 'qa.catalogue.manage'));

        self::assertSame(
            ['qa_catalogue_field', 'title'],
            $this->styleFieldNames('qa-catalogue-style'),
            'the style is linked to its own field and to the core title field'
        );
        self::assertSame('Field title', $this->connection->fetchOne(
            'SELECT rfs.title FROM rel_fields_styles rfs JOIN styles s ON s.id = rfs.id_styles '
            . 'JOIN fields f ON f.id = rfs.id_fields WHERE s.name = :s AND f.name = :f',
            ['s' => 'qa-catalogue-style', 'f' => 'qa_catalogue_field']
        ));
        $config = $this->connection->fetchOne('SELECT config FROM fields WHERE name = :name', ['name' => 'qa_catalogue_field']);
        self::assertIsString($config);
        self::assertEquals(['options' => [['value' => 'a', 'text' => 'A']]], json_decode($config, true), 'the field config is stored as JSON (MySQL reorders keys)');

        self::assertTrue($this->permissionHasRole('qa.catalogue.manage', 'admin'), 'defaultRoles grants the new permission once');
        self::assertSame(2, $this->rowCount(
            'SELECT COUNT(*) FROM lookups WHERE id_plugins = :p',
            ['p' => $pluginRowId]
        ), 'one plugin-owned and one plugin-extendable lookup entry');
    }

    public function testUpdateRenamesInPlaceAndRemovesWhatIsNoLongerDeclared(): void
    {
        $this->synchronizer->sync($this->plugin, $this->manifest());
        $styleId = $this->idOf('styles', 'qa-catalogue-style');
        $fieldId = $this->idOf('fields', 'qa_catalogue_field');

        $updated = $this->manifestData();
        $updated['fields'][0]['name'] = 'qa_catalogue_field_renamed';
        $updated['fields'][0]['renamedFrom'] = ['qa_catalogue_field'];
        $updated['styles'][0]['name'] = 'qa-catalogue-style-renamed';
        $updated['styles'][0]['renamedFrom'] = ['qa-catalogue-style'];
        $updated['styles'][0]['fields'] = [['name' => 'qa_catalogue_field_renamed']];
        $updated['permissions'] = [];
        $updated['lookups']['extends'][0]['entries'] = [];
        $this->synchronizer->sync($this->plugin, new PluginManifest($updated));

        self::assertSame($styleId, $this->idOf('styles', 'qa-catalogue-style-renamed'), 'the style row is renamed, not replaced');
        self::assertSame($fieldId, $this->idOf('fields', 'qa_catalogue_field_renamed'), 'the field row is renamed, so section values stay attached');
        self::assertSame(['qa_catalogue_field_renamed'], $this->styleFieldNames('qa-catalogue-style-renamed'), 'the core field link is removed');
        self::assertNull($this->idOf('permissions', 'qa.catalogue.manage'), 'a permission no longer declared is removed');
        self::assertSame(1, $this->rowCount(
            'SELECT COUNT(*) FROM lookups WHERE id_plugins = :p',
            ['p' => $this->plugin->getId()]
        ), 'the dropped lookup entry is removed');
    }

    public function testAbsentSectionsLeaveTheCatalogueUntouched(): void
    {
        $this->synchronizer->sync($this->plugin, $this->manifest());

        $data = $this->manifestData();
        unset($data['styleGroups'], $data['fieldTypes'], $data['fields'], $data['styles'][0]['fields'], $data['permissions'], $data['lookups']);
        $this->synchronizer->sync($this->plugin, new PluginManifest($data));

        self::assertSame(['qa_catalogue_field', 'title'], $this->styleFieldNames('qa-catalogue-style'));
        self::assertNotNull($this->idOf('fields', 'qa_catalogue_field'));
        self::assertNotNull($this->idOf('permissions', 'qa.catalogue.manage'), 'no `permissions` key: the plugin permissions stay');
        self::assertSame(2, $this->rowCount(
            'SELECT COUNT(*) FROM lookups WHERE id_plugins = :p',
            ['p' => $this->plugin->getId()]
        ), 'no `lookups` key: the plugin lookup entries stay');
    }

    public function testANameHeldByCoreIsRefused(): void
    {
        $data = $this->manifestData();
        $data['styles'][0]['name'] = 'button';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('style "button", which already exists and belongs to core');
        $this->synchronizer->sync($this->plugin, new PluginManifest($data));
    }

    public function testAClosedCoreLookupTypeIsRefused(): void
    {
        $data = $this->manifestData();
        $data['lookups']['extends'][1]['typeCode'] = LookupService::PAGE_ACCESS_TYPES;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('may not add entries to core lookup type');
        $this->synchronizer->sync($this->plugin, new PluginManifest($data));
    }

    public function testAnUnownedFieldTypeUsedOnlyByThePluginIsAdopted(): void
    {
        // Seeded by a plugin migration before rule 7.8: no owner yet.
        $this->connection->insert('field_types', ['name' => 'qa-catalogue-type', 'position' => 1]);

        $this->synchronizer->sync($this->plugin, $this->manifest());

        self::assertSame($this->plugin->getId(), $this->owner('field_types', 'qa-catalogue-type'));
    }

    public function testAStyleKeyTheManifestLeavesOutKeepsItsValue(): void
    {
        $this->synchronizer->sync($this->plugin, $this->manifest());
        // A container style seeded by a migration before its manifest declared it.
        $this->connection->update('styles', ['can_have_children' => 1], ['name' => 'qa-catalogue-style']);

        $data = $this->manifestData();
        unset($data['styles'][0]['description'], $data['styles'][0]['canHaveChildren']);
        $this->synchronizer->sync($this->plugin, new PluginManifest($data));

        $row = $this->connection->fetchAssociative('SELECT description, can_have_children FROM styles WHERE name = :n', ['n' => 'qa-catalogue-style']);
        self::assertIsArray($row);
        self::assertSame('QA style', $row['description']);
        self::assertEquals(1, $row['can_have_children']);
    }

    public function testPurgeRemovesEveryRowThePluginOwns(): void
    {
        $this->synchronizer->sync($this->plugin, $this->manifest());

        $purger = $this->service(PluginPurger::class);
        $deleteTaggedRows = new \ReflectionMethod($purger, 'deletePluginTaggedRows');
        $deleteTaggedRows->invoke($purger, $this->plugin->getId());

        foreach (['styles', 'fields', 'field_types', 'style_groups', 'permissions', 'lookups'] as $table) {
            self::assertSame(0, $this->rowCount(
                sprintf('SELECT COUNT(*) FROM %s WHERE id_plugins = :p', $table),
                ['p' => $this->plugin->getId()]
            ), $table . ' rows of the plugin are gone');
        }
    }

    /**
     * F248: uninstall keeps the catalogue rows (the database clears `id_plugins`); installing the
     * plugin again takes the same rows back, so section values, role permissions and resource
     * grants that point at their ids still work.
     */
    public function testUninstallAndInstallAgainKeepTheSameCatalogueRowsAndGrants(): void
    {
        $manifest = $this->resourceManifest();
        $this->synchronizer->sync($this->plugin, $manifest);

        $tables = ['styles' => 'qa-catalogue-style', 'fields' => 'qa_catalogue_field', 'permissions' => 'qa.catalogue.manage'];
        $ids = [];
        foreach ($tables as $table => $name) {
            $ids[$table] = $this->idOf($table, $name);
            self::assertNotNull($ids[$table]);
        }
        $lookupId = $this->resourceTypeLookupId();
        $roleId = $this->roleHoldingTheCatalogueRows($ids['permissions'] ?? 0, $lookupId);

        $this->uninstall();

        foreach ($tables as $table => $name) {
            self::assertSame($ids[$table], $this->idOf($table, $name), $table . ' row stays after uninstall');
            self::assertNull($this->owner($table, $name), $table . ' row has no owner after uninstall');
        }
        self::assertTrue($this->roleHoldsTheCatalogueRows($roleId, $lookupId), 'role permission and grant stay after uninstall');

        $reinstalled = new Plugin(self::PLUGIN_ID, 'QA catalogue plugin', '1.0.0', '0.2.0');
        $this->em->persist($reinstalled);
        $this->em->flush();
        $result = $this->synchronizer->sync($reinstalled, $manifest);

        self::assertGreaterThan(0, $result['reclaimed'] ?? 0, 'the rows the plugin owned are given back');
        foreach ($tables as $table => $name) {
            self::assertSame($ids[$table], $this->idOf($table, $name), $table . ' keeps its id after install');
            self::assertSame($reinstalled->getId(), $this->owner($table, $name), $table . ' belongs to the new plugin row again');
        }
        self::assertSame($lookupId, $this->resourceTypeLookupId(), 'the resource type keeps its id');
        self::assertSame($reinstalled->getId(), $this->owner('lookups', 'qa_catalogue_item', 'lookup_code'), 'the resource type is the plugin\'s again');
        self::assertTrue($this->roleHoldsTheCatalogueRows($roleId, $lookupId), 'role permission and grant survive install');
    }

    public function testARowAnotherPluginTookInTheMeantimeStaysRefused(): void
    {
        $manifest = $this->resourceManifest();
        $this->synchronizer->sync($this->plugin, $manifest);
        $this->uninstall();

        $other = new Plugin('qa-other-plugin', 'QA other plugin', '1.0.0', '0.2.0');
        $this->em->persist($other);
        $this->em->flush();
        $this->connection->update('styles', ['id_plugins' => $other->getId()], ['name' => 'qa-catalogue-style']);

        $reinstalled = new Plugin(self::PLUGIN_ID, 'QA catalogue plugin', '1.0.0', '0.2.0');
        $this->em->persist($reinstalled);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('style "qa-catalogue-style", which already exists and belongs to another plugin');
        $this->synchronizer->sync($reinstalled, $manifest);
    }

    public function testARowThePluginNeverOwnedStaysRefusedAfterAReinstall(): void
    {
        $manifest = $this->resourceManifest();
        $this->synchronizer->sync($this->plugin, $manifest);
        $this->uninstall();
        // A style of core that the plugin's next release wants to take over.
        $this->connection->insert('style_groups', ['name' => 'qa-catalogue-core-group', 'position' => 995]);
        $groupId = $this->idOf('style_groups', 'qa-catalogue-core-group');
        $this->connection->insert('styles', ['name' => 'qa-core-style', 'id_style_groups' => $groupId, 'can_have_children' => 0]);

        $reinstalled = new Plugin(self::PLUGIN_ID, 'QA catalogue plugin', '1.1.0', '0.2.0');
        $this->em->persist($reinstalled);
        $this->em->flush();
        $data = $this->resourceManifestData();
        $data['styles'][] = ['name' => 'qa-core-style', 'description' => 'Not the plugin\'s', 'canHaveChildren' => false, 'group' => 'qa-catalogue-group'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('style "qa-core-style", which already exists and belongs to core');
        $this->synchronizer->sync($reinstalled, new PluginManifest($data));
    }

    /**
     * What the uninstaller does to the catalogue: remember the plugin's rows in the uninstall
     * operation, then remove the plugin row (the database sets `id_plugins` of its rows to NULL).
     */
    private function uninstall(): void
    {
        $recorder = $this->service(PluginOperationRecorder::class);
        $operation = $recorder->start(self::PLUGIN_ID, PluginOperation::TYPE_UNINSTALL, Plugin::INSTALL_MODE_MANAGED, null, $this->plugin->getVersion());
        $this->service(PluginCatalogueOwnership::class)->remember($operation, $this->plugin);
        $recorder->succeed($operation, 'Uninstalled');

        $this->em->remove($this->plugin);
        $this->em->flush();
    }

    private function resourceTypeLookupId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM lookups WHERE type_code = :type AND lookup_code = :code',
            ['type' => LookupService::RESOURCE_TYPES, 'code' => 'qa_catalogue_item']
        );

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A role that holds the plugin permission and a grant on an item of the plugin's resource type.
     */
    private function roleHoldingTheCatalogueRows(int $permissionId, ?int $lookupId): int
    {
        $this->connection->insert('roles', ['name' => 'qa_catalogue_role', 'description' => 'QA role holding plugin rows']);
        $roleId = (int) $this->connection->lastInsertId();
        $this->connection->insert('rel_permissions_roles', ['id_permissions' => $permissionId, 'id_roles' => $roleId]);
        $this->connection->insert('role_data_access', [
            'id_roles' => $roleId,
            'id_resource_types' => $lookupId,
            'resource_id' => 5,
            'crud_permissions' => 2,
        ]);

        return $roleId;
    }

    private function roleHoldsTheCatalogueRows(int $roleId, ?int $lookupId): bool
    {
        $permissions = $this->rowCount('SELECT COUNT(*) FROM rel_permissions_roles WHERE id_roles = :r', ['r' => $roleId]);
        $grants = $this->rowCount(
            'SELECT COUNT(*) FROM role_data_access WHERE id_roles = :r AND id_resource_types = :t AND resource_id = 5',
            ['r' => $roleId, 't' => $lookupId]
        );

        return $permissions === 1 && $grants === 1;
    }

    private function resourceManifest(): PluginManifest
    {
        return new PluginManifest($this->resourceManifestData());
    }

    /**
     * The catalogue manifest plus a resource type and a style that holds an item of it.
     *
     * @return CatalogueManifest
     */
    private function resourceManifestData(): array
    {
        $data = $this->manifestData();
        $data['lookups']['extends'][] = [
            'typeCode' => LookupService::RESOURCE_TYPES,
            'ownership' => 'plugin_extendable',
            'entries' => [['code' => 'qa_catalogue_item', 'value' => 'QA catalogue item']],
        ];
        $data['styles'][0]['resourceFields'] = ['qa_catalogue_field' => 'qa_catalogue_item'];

        return $data;
    }

    private function manifest(): PluginManifest
    {
        return new PluginManifest($this->manifestData());
    }

    /**
     * @return CatalogueManifest
     */
    private function manifestData(): array
    {
        return [
            'id' => self::PLUGIN_ID,
            'styleGroups' => [['name' => 'qa-catalogue-group', 'description' => 'QA group', 'position' => 990]],
            'fieldTypes' => [['name' => 'qa-catalogue-type', 'position' => 990]],
            'fields' => [[
                'name' => 'qa_catalogue_field',
                'type' => 'qa-catalogue-type',
                'translatable' => false,
                'config' => ['options' => [['value' => 'a', 'text' => 'A']]],
            ]],
            'styles' => [[
                'name' => 'qa-catalogue-style',
                'description' => 'QA style',
                'canHaveChildren' => false,
                'group' => 'qa-catalogue-group',
                'fields' => [
                    ['name' => 'qa_catalogue_field', 'title' => 'Field title', 'help' => 'Help', 'default' => 'a'],
                    ['name' => 'title', 'title' => 'Heading'],
                ],
            ]],
            'permissions' => [['key' => 'qa.catalogue.manage', 'description' => 'QA permission', 'defaultRoles' => ['admin']]],
            'lookups' => ['extends' => [
                ['typeCode' => 'qa_catalogue_status', 'ownership' => 'plugin_owned', 'entries' => [['code' => 'qa_on', 'value' => 'On']]],
                ['typeCode' => LookupService::JOB_TYPES, 'ownership' => 'plugin_extendable', 'entries' => [['code' => 'qa_catalogue_job', 'value' => 'QA job']]],
            ]],
        ];
    }

    /** @param array<string, mixed> $params */
    private function rowCount(string $sql, array $params): int
    {
        $value = $this->connection->fetchOne($sql, $params);
        self::assertIsNumeric($value);
        return (int) $value;
    }

    private function owner(string $table, string $name, string $nameColumn = 'name'): ?int
    {
        $value = $this->connection->fetchOne(sprintf('SELECT id_plugins FROM %s WHERE %s = :name', $table, $nameColumn), ['name' => $name]);
        return is_numeric($value) ? (int) $value : null;
    }

    private function idOf(string $table, string $name): ?int
    {
        $value = $this->connection->fetchOne(sprintf('SELECT id FROM %s WHERE name = :name', $table), ['name' => $name]);
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return list<string> */
    private function styleFieldNames(string $style): array
    {
        $names = [];
        foreach ($this->connection->fetchFirstColumn(
            'SELECT f.name FROM rel_fields_styles rfs JOIN styles s ON s.id = rfs.id_styles '
            . 'JOIN fields f ON f.id = rfs.id_fields WHERE s.name = :style ORDER BY f.name',
            ['style' => $style]
        ) as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function permissionHasRole(string $permission, string $role): bool
    {
        return $this->rowCount(
            'SELECT COUNT(*) FROM rel_permissions_roles rpr JOIN permissions p ON p.id = rpr.id_permissions '
            . 'JOIN roles r ON r.id = rpr.id_roles WHERE p.name = :p AND r.name = :r',
            ['p' => $permission, 'r' => $role]
        ) === 1;
    }
}
