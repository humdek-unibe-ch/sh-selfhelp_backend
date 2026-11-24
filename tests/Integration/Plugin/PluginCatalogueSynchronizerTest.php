<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Lifecycle\PluginPurger;
use App\Plugin\Manifest\PluginManifest;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;

/**
 * Rule 7.8 / F112: the installer writes a plugin's catalogue rows from
 * its manifest, tagged with the plugin id, and purge removes them.
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

    private function manifest(): PluginManifest
    {
        return new PluginManifest($this->manifestData());
    }

    /**
     * @return array{
     *     id: string,
     *     styleGroups: list<array<string,mixed>>,
     *     fieldTypes: list<array<string,mixed>>,
     *     fields: list<array<string,mixed>>,
     *     styles: list<array<string,mixed>>,
     *     permissions: list<array<string,mixed>>,
     *     lookups: array{extends: list<array{typeCode: string, ownership: string, entries: list<array<string,string>>}>}
     * }
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

    private function owner(string $table, string $name): ?int
    {
        $value = $this->connection->fetchOne(sprintf('SELECT id_plugins FROM %s WHERE name = :name', $table), ['name' => $name]);
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
