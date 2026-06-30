<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\DataCol;
use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Exception\ServiceException;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\CmsPreferenceService;
use App\Repository\DataTableRepository;
use App\Repository\RoleDataAccessRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Core\BaseService;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for managing data_tables creation and column management.
 *
 * The admin role is granted full CRUD on every newly-persisted DataTable by
 * {@see \App\EventListener\DataTableAdminAccessListener}, so no service in
 * this file needs to grant that permission explicitly.
 */
class DataTableService extends BaseService
{
    /**
     * Always-present projection columns every data row implicitly carries (from
     * the read stored procedure), as immutable `field_key => default human label`.
     *
     * They are surfaced by {@see self::getColumns()} flagged `standard:true` with
     * `id:null` so the data-config builder / SQL filter can reference them
     * (e.g. `{{scope.record_id}}`) while the Data browser keeps them read-only
     * (no rename, no delete). All of them are reserved keys, so a user field can
     * never collide with them ({@see DataColumnService::RESERVED_KEYS}).
     *
     * @var array<string, string>
     */
    public const STANDARD_COLUMNS = [
        'record_id' => 'Record ID',
        'entry_date' => 'Entry date',
        'user_code' => 'User code',
        'id_users' => 'User ID',
        'user_name' => 'User name',
        'triggerType' => 'Trigger type',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly DataTableRepository $dataTableRepository,
        private readonly CacheService $cache,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly LookupService $lookupService,
        private readonly CmsPreferenceService $cmsPreferenceService
    ) {
    }

    /**
     * Create dataTable for form section if it's a form type
     * 
     * @param Section $section The section to check and create dataTable for
     * @return DataTable|null The created dataTable or null if not a form section
     * @throws ServiceException
     */
    public function createDataTableForFormSection(Section $section): ?DataTable
    {
        $formName = $section->getId();


        // Check if dataTable already exists
        $existingDataTable = $this->dataTableRepository->findOneBy(['name' => $formName]);
        if ($existingDataTable) {
            return $existingDataTable;
        }

        $this->entityManager->beginTransaction();
        
        try {
            // Create new dataTable
            $dataTable = new DataTable();
            $dataTable->setName((string) $formName);
            $dataTable->setTimestamp(new \DateTime());
            
            // Set displayName from section's displayName field if available
            $displayName = $this->getDisplayNameFromSection($section);
            if ($displayName) {
                $dataTable->setDisplayName($displayName);
            }

            $this->entityManager->persist($dataTable);
            $this->entityManager->flush();

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'data_tables',
                $dataTable->getId()
            );

            $this->entityManager->commit();
            
            // Invalidate cache after creating data table
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();
            
            return $dataTable;
            
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to create dataTable for form section: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'sectionId' => $section->getId()]
            );
        }
    }

    /**
     * Update dataTable displayName when section field is updated
     * 
     * @param Section $section The form section
     * @param string $newDisplayName The new display name
     * @return bool Success status
     * @throws ServiceException
     */
    public function updateDataTableDisplayName(Section $section, string $newDisplayName): bool
    {

        $formName = $section->getId();
        if (!$formName) {
            return false;
        }

        $dataTable = $this->dataTableRepository->findOneBy(['name' => $formName]);
        if (!$dataTable) {
            // Create dataTable for form section if it doesn't exist
            $this->createDataTableForFormSection($section);
            $dataTable = $this->dataTableRepository->findOneBy(['name' => $formName]);
        }

        if (!$dataTable) {
            throw new ServiceException('Data table not found', Response::HTTP_NOT_FOUND);
        }

        // Respect a manual lock: once an admin renames the table in the Data
        // browser its provenance becomes `manual`, so the form section's
        // `displayName` field must not overwrite it on save (issue #56).
        if ($dataTable->isDisplayNameManual()) {
            return false;
        }

        $this->entityManager->beginTransaction();
        
        try {
            $dataTable->setDisplayName($newDisplayName);
            
            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_SYSTEM,
                'data_tables',
                $dataTable->getId()
            );

            $this->entityManager->flush();
            $this->entityManager->commit();
            
            // Invalidate cache after updating data table
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();

            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, (int) $dataTable->getId());

            return true;
            
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to update dataTable displayName: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'sectionId' => $section->getId()]
            );
        }
    }

    /**
     * Check if a section is a form section
     * 
     * @param Section $section The section to check
     * @return bool True if it's a form section
     */
    public function isFormSection(Section $section): bool
    {
        $style = $section->getStyle();
        if (!$style) {
            return false;
        }
        
        return in_array($style->getName(), StyleNames::FORM_STYLE_NAMES);
    }    

    /**
     * Get display name from section's "displayName" field
     * 
     * @param Section $section The section
     * @return string|null The display name
     */
    private function getDisplayNameFromSection(Section $section): ?string
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('sft')
           ->from(SectionsFieldsTranslation::class, 'sft')
           ->join('sft.field', 'f')
           ->where('sft.section = :section')
           ->andWhere('f.name = :fieldName')
           ->setParameter('section', $section)
           ->setParameter('fieldName', 'displayName')
           ->setMaxResults(1);
        
        /** @var SectionsFieldsTranslation|null $translation */
        $translation = $qb->getQuery()->getOneOrNullResult();
        
        return $translation ? $translation->getContent() : null;
    }

    /**
     * Content of a section's style field named `name` — the human label that
     * drives the AUTO display name of the form's data table and of its input
     * columns (the same field the Save path reads). Null when the section has no
     * such field/translation or it is empty (issue #56).
     */
    private function getNameFieldContentFromSection(Section $section): ?string
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('sft')
           ->from(SectionsFieldsTranslation::class, 'sft')
           ->join('sft.field', 'f')
           ->where('sft.section = :section')
           ->andWhere('f.name = :fieldName')
           ->setParameter('section', $section)
           ->setParameter('fieldName', 'name')
           ->setMaxResults(1);

        /** @var SectionsFieldsTranslation|null $translation */
        $translation = $qb->getQuery()->getOneOrNullResult();

        $content = $translation?->getContent();

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * Re-derive the AUTO display label for a column addressed by its immutable
     * field key. Core CMS columns use `section_<id>` -> the input section's
     * `name` field; external keys (e.g. SurveyJS `question.name`) have no CMS
     * section, so they fall back to null and the next write re-applies the
     * incoming label (issue #56).
     */
    private function deriveAutoColumnLabel(string $fieldKey): ?string
    {
        if (preg_match('/^section_(\d+)$/', $fieldKey, $matches) !== 1) {
            return null;
        }

        $section = $this->entityManager->getRepository(Section::class)->find((int) $matches[1]);

        return $section !== null ? $this->getNameFieldContentFromSection($section) : null;
    }

    /**
     * Get all form data tables
     *
     * @return DataTable[] Array of data tables that correspond to forms
     */
    public function getFormDataTables(): array
    {
        // For now, return all data tables
        // In the future, we could add a flag or naming convention to identify form data tables
        return $this->dataTableRepository->findAll();
    }

    /**
     * Delete data table and all associated data
     * 
     * @param string $tableName The name of the table to delete
     * @return bool Success status
     * @throws ServiceException
     */
    public function deleteDataTable(string $tableName): bool
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return false;
        }

        $deletedDataTableId = $dataTable->getId();

        $this->entityManager->beginTransaction();

        try {
            // Log transaction before deletion
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_SYSTEM,
                'data_tables',
                $dataTable->getId()
            );

            // Doctrine will cascade delete all related rows, columns, and cells
            $this->entityManager->remove($dataTable);
            $this->entityManager->flush();
            $this->entityManager->commit();
            
            // Invalidate cache after deleting data table
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();

            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, (int) $deletedDataTableId);

            return true;
            
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to delete dataTable: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'tableName' => $tableName]
            );
        }
    }

    /**
     * Get dataTable statistics
     * 
     * @param string $tableName The table name
     * @return array<string, mixed> Statistics array
     */
    public function getDataTableStats(string $tableName): array
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        
        // Count total rows
        $totalRows = $qb->select('COUNT(dr.id)')
            ->from(DataRow::class, 'dr')
            ->where('dr.dataTable = :dataTable')
            ->setParameter('dataTable', $dataTable)
            ->getQuery()
            ->getSingleScalarResult();

        // Count columns
        $totalColumns = count($dataTable->getDataCols());

        return [
            'tableName' => $tableName,
            'displayName' => $dataTable->getDisplayName(),
            'locked' => $dataTable->isDisplayNameManual(),
            'totalRows' => $totalRows,
            'totalColumns' => $totalColumns,
            'created' => $dataTable->getTimestamp()
        ];
    }

    /**
     * Compact table info for the CMS form section inspector: the underlying data
     * table id, its storage name (== form section id, used for the Data browser
     * deep link), the current display label and whether it is manually locked.
     * Returns null when the form section has no data table yet (issue #56).
     *
     * @return array{id: int, name: string, display_name: string|null, locked: bool}|null
     */
    public function getFormSectionTableInfo(int $sectionId): ?array
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => (string) $sectionId]);
        if (!$dataTable) {
            return null;
        }

        return [
            'id' => (int) $dataTable->getId(),
            'name' => (string) $dataTable->getName(),
            'display_name' => $dataTable->getDisplayName(),
            'locked' => $dataTable->isDisplayNameManual(),
        ];
    }

    /**
     * Delete selected columns from a data table, addressed by immutable field key.
     * Returns number of deleted columns, false if table not found
     *
     * @param list<string> $fieldKeys Immutable column keys (data_cols.field_key)
     */
    public function deleteColumns(string $tableName, array $fieldKeys): int|false
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return false;
        }

        if (count($fieldKeys) === 0) {
            return 0;
        }

        $this->entityManager->beginTransaction();

        try {
            $deletedCount = 0;

            // Fetch columns by immutable field key
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('dc')
                ->from(DataCol::class, 'dc')
                ->where('dc.dataTable = :dataTable')
                ->andWhere($qb->expr()->in('dc.fieldKey', ':fieldKeys'))
                ->setParameter('dataTable', $dataTable)
                ->setParameter('fieldKeys', $fieldKeys);

            /** @var array<int, \App\Entity\DataCol> $columns */
            $columns = $qb->getQuery()->getResult();

            foreach ($columns as $column) {
                $this->entityManager->remove($column);
                $deletedCount++;
            }

            if ($deletedCount > 0) {
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_DELETE,
                    LookupService::TRANSACTION_BY_BY_SYSTEM,
                    'data_tables',
                    $dataTable->getId()
                );
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $deletedCount;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to delete columns: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'tableName' => $tableName]
            );
        }
    }

    /**
     * Get columns for a data table by name.
     *
     * Returns the always-present {@see self::STANDARD_COLUMNS} projection columns
     * first (`id:null`, `standard:true`, `locked:true` — read-only, not editable
     * in the Data browser) followed by the table's dynamic data columns
     * (`standard:false`). `fieldKey` is the immutable storage key; `displayName`
     * is the mutable human label (null when never curated). Returns false if the
     * table is not found.
     *
     * @return list<array<string, mixed>>|false
     */
    public function getColumns(string $tableName): array|false
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return false;
        }

        $result = [];
        foreach (self::STANDARD_COLUMNS as $fieldKey => $label) {
            $result[] = [
                'id' => null,
                'fieldKey' => $fieldKey,
                'displayName' => $label,
                'locked' => true,
                'standard' => true,
            ];
        }
        foreach ($dataTable->getDataCols() as $col) {
            $result[] = [
                'id' => $col->getId(),
                'fieldKey' => $col->getFieldKey(),
                'displayName' => $col->getDisplayName(),
                'locked' => $col->isDisplayNameManual(),
                'standard' => false,
            ];
        }
        return $result;
    }

     /**
     * Get column keys for a data table by name (immutable field keys plus the
     * always-present projection columns). Returns false if not found.
     *
     * @return list<string|null>|false
     */
    public function getColumnsNames(string $tableName): array|false
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return false;
        }

        $result = array_keys(self::STANDARD_COLUMNS);
        foreach ($dataTable->getDataCols() as $col) {
            $result[] = $col->getFieldKey();
        }
        return $result;
    }

    /**
     * Curate a column's human-facing label. Addressed by immutable field key;
     * sets `display_name` and marks the label as manually curated so future
     * auto label pushes from submissions never overwrite it.
     *
     * Returns true on success, false when the table or column is unknown.
     *
     * @throws ServiceException
     */
    public function updateColumnDisplayName(string $tableName, string $fieldKey, ?string $displayName): bool
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return false;
        }

        $column = $this->entityManager->getRepository(DataCol::class)
            ->findOneBy(['dataTable' => $dataTable, 'fieldKey' => $fieldKey]);
        if (!$column) {
            return false;
        }

        $this->entityManager->beginTransaction();

        try {
            $normalized = is_string($displayName) && $displayName !== '' ? $displayName : null;
            if ($normalized === null) {
                // Reset to auto: clear the manual lock (NULL FK = auto) and
                // immediately re-derive the label from the input section's `name`
                // field so the column header is not left as the opaque section_<id>
                // key. External (SurveyJS) keys re-derive to null and pick the
                // label back up on the next write (issue #56).
                $column->setDisplayName($this->deriveAutoColumnLabel($fieldKey));
                $column->setDisplayNameSource(null);
            } else {
                // Setting a label marks it admin-curated (the `manual` lookup row,
                // type dataColDisplayNameSource) so future auto pushes from
                // submissions never overwrite it.
                $column->setDisplayName($normalized);
                $column->setDisplayNameSource(
                    $this->lookupService->findByTypeAndCode(
                        LookupService::DATA_COL_DISPLAY_NAME_SOURCE,
                        LookupService::DATA_COL_DISPLAY_NAME_SOURCE_MANUAL
                    )
                );
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'data_tables',
                $dataTable->getId()
            );

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Bust the column list + variable-picker caches (the data-table
            // entity-scope generation is folded into both).
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, (int) $dataTable->getId());

            return true;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to update column display name: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'tableName' => $tableName, 'fieldKey' => $fieldKey]
            );
        }
    }

    /**
     * Manually curate a data table's human label from the Data browser. Sets
     * display_name and marks provenance `manual` so the form section's
     * `displayName` field never overwrites it again. Clearing the label
     * (null/empty) reverts to `auto` and re-derives the label from the owning
     * form section so the table is not left blank (issue #56).
     *
     * Returns true on success, false when the table is unknown.
     *
     * @throws ServiceException
     */
    public function setDataTableDisplayNameCurated(string $tableName, ?string $displayName): bool
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);
        if (!$dataTable) {
            return false;
        }

        $this->entityManager->beginTransaction();

        try {
            $normalized = is_string($displayName) && $displayName !== '' ? $displayName : null;

            if ($normalized === null) {
                // Reset to auto: re-derive from the owning form section's `name`
                // field (the table name is the form section id), matching the live
                // Save path so the label stays readable and consistent.
                $section = $this->entityManager->getRepository(Section::class)
                    ->find((int) $dataTable->getName());
                $dataTable->setDisplayName($section !== null ? $this->getNameFieldContentFromSection($section) : null);
                $dataTable->setDisplayNameSource(null);
            } else {
                $dataTable->setDisplayName($normalized);
                $dataTable->setDisplayNameSource(
                    $this->lookupService->findByTypeAndCode(
                        LookupService::DATA_COL_DISPLAY_NAME_SOURCE,
                        LookupService::DATA_COL_DISPLAY_NAME_SOURCE_MANUAL
                    )
                );
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'data_tables',
                $dataTable->getId()
            );

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, (int) $dataTable->getId());
            // The form section inspector embeds this table's name + lock state.
            // Only form-section tables are named after a numeric section id, so
            // bust the SECTION scope only for those (standalone/SurveyJS tables
            // have no owning section and a 0 id would be rejected by the cache).
            $tableNameStr = (string) $dataTable->getName();
            if (ctype_digit($tableNameStr) && (int) $tableNameStr > 0) {
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, (int) $tableNameStr);
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateAllListsInCategory();
            }

            return true;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to update data table display name: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'tableName' => $tableName]
            );
        }
    }

    /**
     * Get filtered data tables with permission-based access control
     * Includes proper caching with user scope
     * Uses RoleDataAccessRepository optimized methods
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function getFilteredDataTables(int $userId, array $filters = []): array
    {
        // Create cache key based on user and filters
        $cacheKey = "filtered_data_tables_{$userId}_" . md5(serialize($filters));

        return $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList(
                $cacheKey,
                fn() => $this->fetchFilteredDataTablesFromRepository($userId, $filters)
            );
    }

    /**
     * Check if user can access a specific data table for a given permission
     */
    public function canAccessDataTable(int $userId, int $dataTableId, int $permission): bool
    {
        return $this->dataAccessSecurityService->hasStoredPermission(
            $userId,
            LookupService::RESOURCE_TYPES_DATA_TABLE,
            $dataTableId,
            $permission
        );
    }

    /**
     * Fetch filtered data tables from repository with permission checking
     * Uses RoleDataAccessRepository optimized SQL queries
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function fetchFilteredDataTablesFromRepository(int $userId, array $filters): array
    {
        // Get resource type ID
        $resourceTypeId = $this->lookupService->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_DATA_TABLE
        );

        if (!$resourceTypeId) {
            return [];
        }

        $dataTables = $this->roleDataAccessRepository->getAccessibleDataTablesForUser($userId, $resourceTypeId);

        // Apply additional filters if provided (name)
        if (!empty($filters) && isset($filters['name']) && $filters['name']) {
            $filterName = $this->asString($filters['name']);
            $dataTables = array_filter($dataTables, function (array $dataTable) use ($filterName) {
                return stripos($this->asString($dataTable['name'] ?? ''), $filterName) !== false;
            });
        }

        // Convert timezone and format the timestamp for consistency
        $cmsTimezone = new \DateTimeZone($this->cmsPreferenceService->getDefaultTimezoneCode());
        $dataTables = array_map(function ($table) use ($cmsTimezone) {
            $created = $table['created'];
            if ($created instanceof \DateTime) {
                $created = $created->setTimezone($cmsTimezone)->format('Y-m-d H:i:s');
            }
            return [
                'id' => $table['id'],
                'name' => $table['name'],
                'displayName' => $table['displayName'] ?? $table['display_name'] ?? null,
                // NULL provenance FK == auto; any non-null value is the `manual`
                // lock (issue #56).
                'locked' => ($table['displayNameSourceId'] ?? null) !== null,
                'created' => $created,
                'crud' => $table['crud']
            ];
        }, $dataTables);

        return array_values($dataTables);
    }

}
