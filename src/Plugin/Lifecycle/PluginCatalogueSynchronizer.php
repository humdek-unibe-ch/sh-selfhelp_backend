<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Plugin\Lookup\LookupExtensionPolicy;
use App\Plugin\Lookup\LookupPolicyRegistry;
use App\Plugin\Manifest\PluginManifest;
use Doctrine\DBAL\Connection;

/**
 * Writes the rows a plugin adds to core catalogue tables from its
 * manifest (rule 7.8, F112): style groups, field types, fields, styles
 * with their field links, permissions and lookup entries. Every row is
 * tagged with `id_plugins`; a plugin migration touches only the
 * plugin's own tables.
 *
 * Lifecycle:
 *   - install / update → {@see sync()}, after the plugin's migrations
 *     and before {@see PluginApiRouteSynchronizer} (routes link to the
 *     permissions written here).
 *   - uninstall keeps the rows (sections keep their content);
 *     purge deletes every `id_plugins`-tagged row
 *     ({@see PluginPurger::deletePluginTaggedRows()}).
 *
 * Rules:
 *   - A manifest section that is absent (`styleGroups`, `fieldTypes`,
 *     `fields`, a style's `fields`, `permissions`, `lookups`) leaves that
 *     part untouched, so a plugin that still seeds its catalogue in a
 *     migration keeps working.
 *   - A name held by core or another plugin is refused. Exceptions,
 *     because these rows had no owner before: a style group or field
 *     type used only by this plugin's styles or fields, and an entry of
 *     a lookup type core does not know (a plugin-owned type), are
 *     adopted.
 *   - `renamedFrom` renames this plugin's style or field in place, so
 *     sections keep their style and their field values.
 *   - Permissions and lookup entries the plugin no longer declares are
 *     removed; so are field links a style no longer lists. Styles,
 *     fields, field types and style groups are never removed on update,
 *     because sections may still use them.
 */
final class PluginCatalogueSynchronizer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LookupPolicyRegistry $lookupPolicies,
    ) {
    }

    /**
     * @return array<string, int> counts for the operation log
     */
    public function sync(Plugin $plugin, PluginManifest $manifest): array
    {
        $pluginRowId = $plugin->getId();
        if ($pluginRowId === null) {
            throw new \LogicException('The plugin must be persisted before its catalogue rows are written.');
        }
        $context = new PluginCatalogueContext($pluginRowId, $manifest->getPluginId());

        $declared = $manifest->toArray();

        return $this->connection->transactional(function () use ($context, $manifest, $declared): array {
            return [
                'style_groups' => $this->syncStyleGroups($context, $manifest->getStyleGroups()),
                'field_types' => $this->syncFieldTypes($context, $manifest->getFieldTypes()),
                'fields' => $this->syncFields($context, $manifest->getFields()),
                'styles' => $this->syncStyles($context, $manifest->getStyles()),
                'permissions' => array_key_exists('permissions', $declared)
                    ? $this->syncPermissions($context, $manifest->getPermissions())
                    : 0,
                'lookups' => array_key_exists('lookups', $declared)
                    ? $this->syncLookups($context, $manifest->getLookupExtensions())
                    : 0,
            ];
        });
    }

    /** @param list<array<string,mixed>>|null $groups */
    private function syncStyleGroups(PluginCatalogueContext $context, ?array $groups): int
    {
        if ($groups === null) {
            return 0;
        }
        foreach ($groups as $group) {
            $name = self::requiredString($group, 'name', $context, 'styleGroups');
            $row = $this->fetchRow('SELECT id, id_plugins FROM style_groups WHERE name = :name', ['name' => $name]);
            $values = [
                'description' => self::optionalString($group, 'description'),
                'position' => self::optionalInt($group, 'position'),
            ];
            if ($row === null) {
                $this->connection->insert('style_groups', ['name' => $name, 'id_plugins' => $context->pluginRowId] + $values);
                continue;
            }
            $this->assertOwnedOrAdoptable($context, 'style group', $name, $row, 'styles', 'id_style_groups');
            $this->connection->update('style_groups', ['id_plugins' => $context->pluginRowId] + $values, ['id' => $row['id']]);
        }
        return count($groups);
    }

    /** @param list<array<string,mixed>>|null $types */
    private function syncFieldTypes(PluginCatalogueContext $context, ?array $types): int
    {
        if ($types === null) {
            return 0;
        }
        foreach ($types as $type) {
            $name = self::requiredString($type, 'name', $context, 'fieldTypes');
            $row = $this->fetchRow('SELECT id, id_plugins FROM field_types WHERE name = :name', ['name' => $name]);
            $position = self::optionalInt($type, 'position') ?? 0;
            if ($row === null) {
                $this->connection->insert('field_types', ['name' => $name, 'position' => $position, 'id_plugins' => $context->pluginRowId]);
                continue;
            }
            $this->assertOwnedOrAdoptable($context, 'field type', $name, $row, 'fields', 'id_field_types');
            $this->connection->update('field_types', ['position' => $position, 'id_plugins' => $context->pluginRowId], ['id' => $row['id']]);
        }
        return count($types);
    }

    /** @param list<array<string,mixed>>|null $fields */
    private function syncFields(PluginCatalogueContext $context, ?array $fields): int
    {
        if ($fields === null) {
            return 0;
        }
        foreach ($fields as $field) {
            $name = self::requiredString($field, 'name', $context, 'fields');
            $typeName = self::requiredString($field, 'type', $context, 'fields');
            $typeId = $this->fetchId('SELECT id FROM field_types WHERE name = :name', ['name' => $typeName]);
            if ($typeId === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" field "%s" uses field type "%s", which neither core nor the plugin\'s `fieldTypes` declares.',
                    $context->pluginId,
                    $name,
                    $typeName
                ));
            }
            $this->renameInPlace($context, 'fields', 'field', $name, self::stringList($field, 'renamedFrom'));

            $values = [
                'id_field_types' => $typeId,
                'display' => self::optionalBool($field, 'translatable') ? 1 : 0,
                'config' => self::encodeConfig($field, $context, $name),
            ];
            $row = $this->fetchRow('SELECT id, id_plugins FROM fields WHERE name = :name', ['name' => $name]);
            if ($row === null) {
                $this->connection->insert('fields', ['name' => $name, 'id_plugins' => $context->pluginRowId] + $values);
                continue;
            }
            $this->assertOwned($context, 'field', $name, $row);
            $this->connection->update('fields', $values, ['id' => $row['id']]);
        }
        return count($fields);
    }

    /** @param array<int, array<string,mixed>> $styles */
    private function syncStyles(PluginCatalogueContext $context, array $styles): int
    {
        foreach ($styles as $style) {
            $name = self::requiredString($style, 'name', $context, 'styles');
            $this->renameInPlace($context, 'styles', 'style', $name, self::stringList($style, 'renamedFrom'));

            $values = [
                'description' => self::optionalString($style, 'description'),
                'can_have_children' => self::optionalBool($style, 'canHaveChildren') ? 1 : 0,
            ];
            $groupName = self::optionalString($style, 'group');
            if ($groupName !== null) {
                $groupId = $this->fetchId('SELECT id FROM style_groups WHERE name = :name', ['name' => $groupName]);
                if ($groupId === null) {
                    throw new \InvalidArgumentException(sprintf(
                        'Plugin "%s" style "%s" names style group "%s", which neither core nor the plugin\'s `styleGroups` declares.',
                        $context->pluginId,
                        $name,
                        $groupName
                    ));
                }
                $values['id_style_groups'] = $groupId;
            }

            $row = $this->fetchRow('SELECT id, id_plugins FROM styles WHERE name = :name', ['name' => $name]);
            if ($row === null) {
                if (!isset($values['id_style_groups'])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Plugin "%s" style "%s" needs a `group` the first time it is installed.',
                        $context->pluginId,
                        $name
                    ));
                }
                $this->connection->insert('styles', ['name' => $name, 'id_plugins' => $context->pluginRowId] + $values);
                $styleId = (int) $this->connection->lastInsertId();
            } else {
                $this->assertOwned($context, 'style', $name, $row);
                $this->connection->update('styles', $values, ['id' => $row['id']]);
                $styleId = $row['id'];
            }

            if (array_key_exists('fields', $style) && is_array($style['fields'])) {
                $this->syncStyleFields($context, $name, $styleId, $style['fields']);
            }
        }
        return count($styles);
    }

    /** @param array<mixed> $links */
    private function syncStyleFields(PluginCatalogueContext $context, string $styleName, int $styleId, array $links): void
    {
        $keptFieldIds = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $link = self::stringKeyed($link);
            $fieldName = self::requiredString($link, 'name', $context, sprintf('styles.%s.fields', $styleName));
            $field = $this->fetchRow('SELECT id, id_plugins FROM fields WHERE name = :name', ['name' => $fieldName]);
            if ($field === null || ($field['id_plugins'] !== null && $field['id_plugins'] !== $context->pluginRowId)) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" style "%s" lists field "%s", which is neither a core field nor declared under the plugin\'s `fields`.',
                    $context->pluginId,
                    $styleName,
                    $fieldName
                ));
            }
            $this->connection->executeStatement(
                'INSERT INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title) '
                . 'VALUES (:style, :field, :default, :help, :disabled, :hidden, :title) '
                . 'ON DUPLICATE KEY UPDATE default_value = VALUES(default_value), help = VALUES(help), '
                . 'disabled = VALUES(disabled), hidden = VALUES(hidden), title = VALUES(title)',
                [
                    'style' => $styleId,
                    'field' => $field['id'],
                    'default' => self::optionalString($link, 'default'),
                    'help' => self::optionalString($link, 'help'),
                    'disabled' => self::optionalBool($link, 'disabled') ? 1 : 0,
                    'hidden' => self::optionalBool($link, 'hidden') ? 1 : 0,
                    'title' => self::optionalString($link, 'title'),
                ]
            );
            $keptFieldIds[] = $field['id'];
        }

        $sql = 'DELETE FROM rel_fields_styles WHERE id_styles = :style';
        $params = ['style' => $styleId];
        $types = [];
        if ($keptFieldIds !== []) {
            $sql .= ' AND id_fields NOT IN (:kept)';
            $params['kept'] = $keptFieldIds;
            $types['kept'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $this->connection->executeStatement($sql, $params, $types);
    }

    /** @param array<int, array<string,mixed>> $permissions */
    private function syncPermissions(PluginCatalogueContext $context, array $permissions): int
    {
        $declared = [];
        foreach ($permissions as $permission) {
            $name = self::requiredString($permission, 'key', $context, 'permissions');
            $declared[] = $name;
            $description = self::optionalString($permission, 'description');
            $row = $this->fetchRow('SELECT id, id_plugins FROM permissions WHERE name = :name', ['name' => $name]);
            if ($row !== null) {
                $this->assertOwned($context, 'permission', $name, $row);
                $this->connection->update('permissions', ['description' => $description], ['id' => $row['id']]);
                continue;
            }
            $this->connection->insert('permissions', [
                'name' => $name,
                'description' => $description,
                'id_plugins' => $context->pluginRowId,
            ]);
            $permissionId = (int) $this->connection->lastInsertId();
            // Default roles are granted once, when the permission is
            // created; later changes on the role screen are the admin's.
            $roles = self::stringList($permission, 'defaultRoles');
            if ($roles !== []) {
                $this->connection->executeStatement(
                    'INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles) '
                    . 'SELECT :permission, r.id FROM roles r WHERE r.name IN (:roles)',
                    ['permission' => $permissionId, 'roles' => $roles],
                    ['roles' => \Doctrine\DBAL\ArrayParameterType::STRING]
                );
            }
        }

        $stale = $this->ownedIdsNotNamed('permissions', 'name', $context, $declared);
        if ($stale !== []) {
            $ids = ['ids' => $stale];
            $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER];
            $this->connection->executeStatement('DELETE FROM rel_permissions_roles WHERE id_permissions IN (:ids)', $ids, $types);
            $this->connection->executeStatement('DELETE FROM rel_api_routes_permissions WHERE id_permissions IN (:ids)', $ids, $types);
            $this->connection->executeStatement('DELETE FROM permissions WHERE id IN (:ids)', $ids, $types);
        }
        return count($permissions);
    }

    /** @param array<int, array<string,mixed>> $blocks */
    private function syncLookups(PluginCatalogueContext $context, array $blocks): int
    {
        $keptIds = [];
        foreach ($blocks as $block) {
            $typeCode = self::requiredString($block, 'typeCode', $context, 'lookups.extends');
            $ownership = self::requiredString($block, 'ownership', $context, 'lookups.extends');
            $corePolicy = $this->lookupPolicies->policyFor($typeCode);
            $isCoreType = $corePolicy !== null && $this->lookupPolicies->ownerPluginId($typeCode) === null;
            if ($isCoreType && ($corePolicy !== LookupExtensionPolicy::PLUGIN_EXTENDABLE || $ownership !== LookupExtensionPolicy::PLUGIN_EXTENDABLE)) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" may not add entries to core lookup type "%s" (policy "%s").',
                    $context->pluginId,
                    $typeCode,
                    $corePolicy
                ));
            }
            if (!$isCoreType && $ownership !== LookupExtensionPolicy::PLUGIN_OWNED) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" lookup type "%s" is not a core type, so it must be declared with ownership "plugin_owned".',
                    $context->pluginId,
                    $typeCode
                ));
            }
            if (!$isCoreType) {
                $foreignOwner = $this->fetchId(
                    'SELECT id_plugins FROM lookups WHERE type_code = :type AND id_plugins IS NOT NULL AND id_plugins <> :plugin LIMIT 1',
                    ['type' => $typeCode, 'plugin' => $context->pluginRowId]
                );
                if ($foreignOwner !== null) {
                    throw new \InvalidArgumentException(sprintf(
                        'Plugin "%s" lookup type "%s" is already owned by another plugin.',
                        $context->pluginId,
                        $typeCode
                    ));
                }
            }

            $entries = is_array($block['entries'] ?? null) ? $block['entries'] : [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entry = self::stringKeyed($entry);
                $code = self::requiredString($entry, 'code', $context, 'lookups.extends.entries');
                $values = [
                    'lookup_value' => self::optionalString($entry, 'value'),
                    'lookup_description' => self::optionalString($entry, 'description'),
                ];
                $row = $this->fetchRow(
                    'SELECT id, id_plugins FROM lookups WHERE type_code = :type AND lookup_code = :code',
                    ['type' => $typeCode, 'code' => $code]
                );
                if ($row === null) {
                    $this->connection->insert('lookups', ['type_code' => $typeCode, 'lookup_code' => $code, 'id_plugins' => $context->pluginRowId] + $values);
                    $keptIds[] = (int) $this->connection->lastInsertId();
                    continue;
                }
                // An unowned entry of a plugin-owned type was seeded by
                // this plugin's own migration before rule 7.8: adopt it.
                if ($row['id_plugins'] !== $context->pluginRowId && !($row['id_plugins'] === null && !$isCoreType)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Plugin "%s" lookup entry "%s/%s" already exists and belongs to %s.',
                        $context->pluginId,
                        $typeCode,
                        $code,
                        $row['id_plugins'] === null ? 'core' : 'another plugin'
                    ));
                }
                $this->connection->update('lookups', ['id_plugins' => $context->pluginRowId] + $values, ['id' => $row['id']]);
                $keptIds[] = $row['id'];
            }
        }

        $sql = 'DELETE FROM lookups WHERE id_plugins = :plugin';
        $params = ['plugin' => $context->pluginRowId];
        $types = [];
        if ($keptIds !== []) {
            $sql .= ' AND id NOT IN (:kept)';
            $params['kept'] = $keptIds;
            $types['kept'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $this->connection->executeStatement($sql, $params, $types);

        return count($keptIds);
    }

    /**
     * Renames this plugin's row from an earlier name, unless a row with
     * the new name already exists.
     *
     * @param 'styles'|'fields' $table
     * @param list<string> $previousNames
     */
    private function renameInPlace(PluginCatalogueContext $context, string $table, string $label, string $name, array $previousNames): void
    {
        if ($previousNames === [] || $this->fetchId(sprintf('SELECT id FROM %s WHERE name = :name', $table), ['name' => $name]) !== null) {
            return;
        }
        foreach ($previousNames as $previous) {
            $old = $this->fetchRow(sprintf('SELECT id, id_plugins FROM %s WHERE name = :name', $table), ['name' => $previous]);
            if ($old === null) {
                continue;
            }
            if ($old['id_plugins'] !== $context->pluginRowId) {
                throw new \InvalidArgumentException(sprintf(
                    'Plugin "%s" %s "%s" is renamed from "%s", but that %s does not belong to the plugin.',
                    $context->pluginId,
                    $label,
                    $name,
                    $previous,
                    $label
                ));
            }
            $this->connection->update($table, ['name' => $name], ['id' => $old['id']]);
            return;
        }
    }

    /** @param array{id: int, id_plugins: int|null} $row */
    private function assertOwned(PluginCatalogueContext $context, string $label, string $name, array $row): void
    {
        if ($row['id_plugins'] === $context->pluginRowId) {
            return;
        }
        throw new \InvalidArgumentException(sprintf(
            'Plugin "%s" declares %s "%s", which already exists and belongs to %s.',
            $context->pluginId,
            $label,
            $name,
            $row['id_plugins'] === null ? 'core' : 'another plugin'
        ));
    }

    /**
     * Style groups and field types had no owner before rule 7.8. An
     * unowned one is adopted when only this plugin's rows use it.
     *
     * @param array{id: int, id_plugins: int|null} $row
     * @param 'styles'|'fields' $usedBy
     */
    private function assertOwnedOrAdoptable(PluginCatalogueContext $context, string $label, string $name, array $row, string $usedBy, string $foreignKey): void
    {
        if ($row['id_plugins'] === null) {
            $foreignUse = $this->fetchId(
                sprintf('SELECT id FROM %s WHERE %s = :row AND (id_plugins IS NULL OR id_plugins <> :plugin) LIMIT 1', $usedBy, $foreignKey),
                ['row' => $row['id'], 'plugin' => $context->pluginRowId]
            );
            if ($foreignUse === null) {
                return;
            }
        }
        $this->assertOwned($context, $label, $name, $row);
    }

    /**
     * @param 'permissions' $table
     * @param list<string> $declaredNames
     * @return list<int>
     */
    private function ownedIdsNotNamed(string $table, string $nameColumn, PluginCatalogueContext $context, array $declaredNames): array
    {
        $sql = sprintf('SELECT id FROM %s WHERE id_plugins = :plugin', $table);
        $params = ['plugin' => $context->pluginRowId];
        $types = [];
        if ($declaredNames !== []) {
            $sql .= sprintf(' AND %s NOT IN (:names)', $nameColumn);
            $params['names'] = $declaredNames;
            $types['names'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        $ids = [];
        foreach ($this->connection->fetchFirstColumn($sql, $params, $types) as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{id: int, id_plugins: int|null}|null
     */
    private function fetchRow(string $sql, array $params): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);
        if ($row === false || !is_numeric($row['id'] ?? null)) {
            return null;
        }
        $owner = $row['id_plugins'] ?? null;
        return [
            'id' => (int) $row['id'],
            'id_plugins' => is_numeric($owner) ? (int) $owner : null,
        ];
    }

    /** @param array<string, mixed> $params */
    private function fetchId(string $sql, array $params): ?int
    {
        $value = $this->connection->fetchOne($sql, $params);
        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string,mixed> $data */
    private static function requiredString(array $data, string $key, PluginCatalogueContext $context, string $section): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf(
                'Plugin "%s" `%s` entry is missing a non-empty "%s".',
                $context->pluginId,
                $section,
                $key
            ));
        }
        return trim($value);
    }

    /**
     * @param array<mixed> $data
     * @return array<string,mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        return is_int($value) ? $value : null;
    }

    /** @param array<string,mixed> $data */
    private static function optionalBool(array $data, string $key): bool
    {
        return ($data[$key] ?? false) === true;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $field */
    private static function encodeConfig(array $field, PluginCatalogueContext $context, string $name): ?string
    {
        $config = $field['config'] ?? null;
        if ($config === null) {
            return null;
        }
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" field "%s" has a `config` that is not an object.', $context->pluginId, $name));
        }
        return json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
