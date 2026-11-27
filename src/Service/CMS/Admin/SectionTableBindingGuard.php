<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\Field;
use App\Plugin\Resource\ResourceBindingGuard;
use App\Plugin\Resource\ResourceFieldMap;
use App\Service\CMS\DataService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\UserContextAwareService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Author-time check for binding a data table into a section (F07, decided in
 * concepts/rules.md rule 9).
 *
 * An editor may bind a table only when their own role can read it. The check
 * runs when the section is saved or imported, never at render time: once a
 * table is bound, visitors see it through the section without needing a grant
 * of their own (unless the section is limited to their own entries).
 *
 * A section binds a table in two ways, and both go through this guard:
 *  - a field of type `select-data_table` (the `data_table` property), by id;
 *  - a `table` entry in the section's `data_config`, by name.
 *
 * A plugin field can bind one of the plugin's resources the same way (a survey
 * in a survey section). The fields a plugin's manifest lists in
 * `styles[].resourceFields` ({@see ResourceFieldMap}) are checked by
 * {@see ResourceBindingGuard}: the editor must hold the view grant on the item,
 * else 403 `resource_not_bindable` (C8, H7).
 *
 * Clearing a binding is always allowed. A reference that resolves to no table
 * binds nothing and is allowed; the reader finds no data for it. Requests with
 * no signed-in user are system actors (console commands, fixtures) and are
 * trusted; the admin routes that reach this guard always have a user.
 */
class SectionTableBindingGuard extends BaseService
{
    /** Field type of the property that stores a bound table id. */
    public const TABLE_FIELD_TYPE = 'select-data_table';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataService $dataService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly ResourceFieldMap $resourceFields,
        private readonly ResourceBindingGuard $resourceBindingGuard,
    ) {
    }

    /**
     * Check every table-binding property in the property fields of one section
     * save, and every plugin resource the section's style binds (C8) in its
     * property and content fields.
     *
     * @param list<array<string, mixed>> $propertyFields Property fields as sent by the editor (`fieldId`, `value`)
     * @param string $styleName The style of the section being saved
     * @param list<array<string, mixed>> $contentFields Content fields of the same save (`fieldId`, `value`); a plugin resource id can sit in one
     */
    public function assertPropertyFieldsBindable(array $propertyFields, string $styleName, array $contentFields = []): void
    {
        $propertyValues = $this->valuesByFieldId($propertyFields);
        $contentValues = $this->valuesByFieldId($contentFields);
        $fieldIds = array_values(array_unique(array_merge(array_keys($propertyValues), array_keys($contentValues))));
        if ($fieldIds === []) {
            return;
        }

        foreach ($this->entityManager->getRepository(Field::class)->findBy(['id' => $fieldIds]) as $field) {
            $fieldId = (int) $field->getId();
            if ($field->getType()?->getName() === self::TABLE_FIELD_TYPE) {
                foreach ($propertyValues[$fieldId] ?? [] as $value) {
                    $this->assertCanBindTableId($this->asInt($value));
                }
            }
            foreach (array_merge($propertyValues[$fieldId] ?? [], $contentValues[$fieldId] ?? []) as $value) {
                $this->assertPluginResourceBindable($styleName, $field->getName(), $value);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $sentFields Fields as sent by the editor (`fieldId`, `value`)
     * @return array<int, list<mixed>> field id => the values sent for it (one per language for a content field)
     */
    private function valuesByFieldId(array $sentFields): array
    {
        $values = [];
        foreach ($sentFields as $sentField) {
            $fieldId = $this->asInt($sentField['fieldId'] ?? null);
            if ($fieldId > 0) {
                $values[$fieldId][] = $sentField['value'] ?? null;
            }
        }

        return $values;
    }

    /**
     * Check the table-binding fields and the plugin resource fields of one
     * imported section.
     *
     * @param array<array-key, mixed> $fields Imported fields: field name => locale => `{content, ...}`
     * @param string $styleName The style of the imported section
     */
    public function assertImportedFieldsBindable(array $fields, string $styleName): void
    {
        $names = [];
        foreach (array_keys($fields) as $name) {
            $names[] = (string) $name;
        }
        if ($names === []) {
            return;
        }

        foreach ($this->entityManager->getRepository(Field::class)->findBy(['name' => $names]) as $field) {
            $isTable = $field->getType()?->getName() === self::TABLE_FIELD_TYPE;
            $fieldName = $field->getName();
            if ($fieldName === null || !array_key_exists($fieldName, $fields)) {
                continue;
            }
            $localeData = $fields[$fieldName];
            if (!is_array($localeData)) {
                continue;
            }
            foreach ($localeData as $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                if ($isTable) {
                    // Owner tokens such as `@section:<name>` point at a table the
                    // import itself creates; they read as 0 and bind nothing yet.
                    $this->assertCanBindTableId($this->asInt($translation['content'] ?? null));
                }
                $this->assertPluginResourceBindable($styleName, $fieldName, $translation['content'] ?? null);
            }
        }
    }

    /**
     * Check a table bound by id (the `data_table` property). 0 or less clears
     * the binding and is allowed.
     */
    public function assertCanBindTableId(int $tableId): void
    {
        if ($tableId <= 0) {
            return;
        }

        $this->assertReadable($this->dataService->getDataTableById($tableId)?->getId());
    }

    /**
     * Check a table bound by name (a `table` entry in `data_config`).
     */
    public function assertCanBindTableName(string $tableName): void
    {
        $tableName = trim($tableName);
        if ($tableName === '') {
            return;
        }

        $this->assertReadable($this->dataService->getDataTableByName($tableName)?->getId());
    }

    /**
     * Check every table named in a section's `data_config` JSON. Empty or
     * invalid JSON binds nothing; the data reader rejects it on its own.
     */
    public function assertDataConfigBindable(?string $dataConfig): void
    {
        if ($dataConfig === null || trim($dataConfig) === '') {
            return;
        }

        $decoded = json_decode($dataConfig, true);
        if (!is_array($decoded)) {
            return;
        }

        $entries = isset($decoded['table']) ? [$decoded] : $decoded;
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['table'] ?? null)) {
                $this->assertCanBindTableName($entry['table']);
            }
        }
    }

    /**
     * A field that a plugin lists in `styles[].resourceFields` binds one of the
     * plugin's resources: the editor must hold the view grant on it (C8, H7).
     * Any other field is not checked here. System actors (no signed-in user)
     * are trusted.
     */
    private function assertPluginResourceBindable(string $styleName, ?string $fieldName, mixed $value): void
    {
        $type = $fieldName === null ? null : $this->resourceFields->typeOfStyleField($styleName, $fieldName);
        $user = $this->userContextAwareService->getCurrentUser();
        if ($type === null || $user === null) {
            return;
        }

        $this->resourceBindingGuard->assertBindable($type, $value, (int) $user->getId());
    }

    private function assertReadable(?int $resolvedTableId): void
    {
        if ($resolvedTableId === null) {
            return;
        }

        $user = $this->userContextAwareService->getCurrentUser();
        if ($user === null) {
            return;
        }

        $canRead = $this->dataAccessSecurityService->hasPermission(
            (int) $user->getId(),
            LookupService::RESOURCE_TYPES_DATA_TABLE,
            $resolvedTableId,
            DataAccessSecurityService::PERMISSION_READ
        );
        if (!$canRead) {
            $this->throwForbidden('You can only bind data tables that your role can read.');
        }
    }
}
