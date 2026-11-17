<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\Field;
use App\Service\CMS\DataService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\UserContextAwareService;
use App\Service\Security\DataAccessSecurityService;
use App\Plugin\Event\SectionFieldBindingEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

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
 * in a survey section). For every non-empty field value the guard dispatches
 * {@see SectionFieldBindingEvent}; the plugin that owns the field refuses when
 * the editor may not use the resource (H7).
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
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Check every table-binding property in the property fields of one section
     * save.
     *
     * @param list<array<string, mixed>> $propertyFields Property fields as sent by the editor (`fieldId`, `value`)
     */
    public function assertPropertyFieldsBindable(array $propertyFields): void
    {
        $valuesByFieldId = [];
        foreach ($propertyFields as $propertyField) {
            $fieldId = $this->asInt($propertyField['fieldId'] ?? null);
            if ($fieldId > 0) {
                $valuesByFieldId[$fieldId] = $propertyField['value'] ?? null;
            }
        }
        if ($valuesByFieldId === []) {
            return;
        }

        $fields = $this->entityManager->getRepository(Field::class)->findBy(['id' => array_keys($valuesByFieldId)]);
        foreach ($fields as $field) {
            $value = $valuesByFieldId[(int) $field->getId()] ?? null;
            if ($field->getType()?->getName() === self::TABLE_FIELD_TYPE) {
                $this->assertCanBindTableId($this->asInt($value));
            }
            $this->assertPluginBindable($field, $value);
        }
    }

    /**
     * Check the table-binding fields of one imported section.
     *
     * @param array<array-key, mixed> $fields Imported fields: field name => locale => `{content, ...}`
     */
    public function assertImportedFieldsBindable(array $fields): void
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
                $this->assertPluginBindable($field, $translation['content'] ?? null);
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
     * Let the plugin that owns a field refuse the value (H7). Empty values bind
     * nothing; system actors (no signed-in user) are trusted.
     */
    private function assertPluginBindable(Field $field, mixed $value): void
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        $user = $this->userContextAwareService->getCurrentUser();
        if ($text === '' || $user === null || $field->getName() === null) {
            return;
        }

        $event = new SectionFieldBindingEvent($field->getName(), $field->getType()?->getName(), $text, (int) $user->getId());
        $this->eventDispatcher->dispatch($event);
        $denial = $event->getDenial();
        if ($denial !== null) {
            $this->throwForbidden($denial);
        }
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
