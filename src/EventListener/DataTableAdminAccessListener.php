<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DataTable;
use App\Entity\PagesSection;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\Section;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\CreatorGrantService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Grants full CRUD on every newly-persisted DataTable to:
 *   - the admin role (always);
 *   - the roles that can edit the form's page, when the table belongs to a form
 *     section (F15): page grant with update bit and `admin.page.update`.
 *
 * ## Why a Doctrine listener?
 *
 * data_tables rows are created from several places:
 *   - `DataTableService::createDataTableForFormSection()` for form sections.
 *   - `DataService::getOrCreateDataTable()` for runtime saves.
 *   - Plugins (e.g. the SurveyJS plugin's `CoreDataTableWriter`) for plugin-owned data.
 *
 * Hooking the permission grant on every caller would be brittle. Listening
 * at the persistence boundary guarantees the admin always sees newly created
 * data tables in Data Management, regardless of which service created them.
 *
 * ## Two-phase persist
 *
 * 1. {@see onFlush()} collects every DataTable scheduled for insertion. We
 *    cannot persist the matching `RoleDataAccess` row here because Doctrine
 *    is mid-flush and the `data_tables` row does not have its generated id
 *    yet from the application's point of view.
 *
 * 2. {@see postFlush()} runs after the flush succeeds. At that point the
 *    DataTable id is durable and we persist the `RoleDataAccess` row in a
 *    second flush. If the second flush fails we log and move on - the
 *    backfill migration `Version20260525091440` can be re-run manually if
 *    operators need to recover.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DataTableAdminAccessListener
{
    private const PAGE_UPDATE_PERMISSION = 'admin.page.update';

    /**
     * DataTables waiting for their `RoleDataAccess` row. Drained in postFlush.
     *
     * @var array<int, DataTable>
     */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof DataTable) {
                $this->pending[] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];

        $adminRole = $this->entityManager->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        if (!$adminRole instanceof Role || $adminRole->getId() === null) {
            $this->logger->warning('DataTableAdminAccessListener: admin role not found; skipping grant');
            return;
        }

        $resourceType = $this->lookupService->findByTypeAndCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_DATA_TABLE,
        );
        if ($resourceType === null) {
            $this->logger->warning('DataTableAdminAccessListener: data_table resource type lookup missing; skipping grant');
            return;
        }

        $pageResourceType = $this->lookupService->findByTypeAndCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES,
        );

        $fullCrud = CreatorGrantService::FULL_ACCESS;
        $adminRoleId = (int) $adminRole->getId();
        $resourceTypeId = (int) $resourceType->getId();

        $needsFlush = false;
        /** @var list<int> $touchedRoleIds */
        $touchedRoleIds = [];

        foreach ($batch as $dataTable) {
            $dataTableId = $dataTable->getId();
            if ($dataTableId === null) {
                continue;
            }

            if ($this->ensureGrant($adminRole, $resourceTypeId, $dataTableId, $fullCrud)) {
                $needsFlush = true;
                $touchedRoleIds[] = $adminRoleId;
            }

            $pageId = $this->pageIdForFormTable($dataTable);
            if ($pageId === null || $pageResourceType === null) {
                continue;
            }

            foreach ($this->editorRoleIdsForPage($pageId, (int) $pageResourceType->getId(), $adminRoleId) as $roleId) {
                $role = $this->entityManager->find(Role::class, $roleId);
                if (!$role instanceof Role) {
                    continue;
                }
                if ($this->ensureGrant($role, $resourceTypeId, $dataTableId, $fullCrud)) {
                    $needsFlush = true;
                    $touchedRoleIds[] = $roleId;
                }
            }
        }

        if (!$needsFlush) {
            return;
        }

        try {
            $this->entityManager->flush();
            // Drop cached filtered data-table lists so the admin sees the new
            // row on the next request without waiting for the natural TTL.
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();

            foreach (array_unique($touchedRoleIds) as $roleId) {
                $this->dataAccessSecurityService->invalidateRolePermissions($roleId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('DataTableAdminAccessListener: failed to persist admin grants', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist a full-CRUD grant when missing or incomplete. Returns true when a
     * new row was scheduled for insert or an existing row was changed.
     */
    private function ensureGrant(Role $role, int $resourceTypeId, int $dataTableId, int $fullCrud): bool
    {
        $existing = $this->entityManager->getRepository(RoleDataAccess::class)->findOneBy([
            'idRoles' => $role->getId(),
            'idResourceTypes' => $resourceTypeId,
            'resourceId' => $dataTableId,
        ]);
        if ($existing instanceof RoleDataAccess) {
            if ($existing->getCrudPermissions() === $fullCrud) {
                return false;
            }
            $existing->setCrudPermissions($fullCrud);
            return true;
        }

        $resourceType = $this->lookupService->findByTypeAndCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_DATA_TABLE,
        );
        if ($resourceType === null) {
            return false;
        }

        $grant = new RoleDataAccess();
        $grant->setRole($role)
            ->setResourceType($resourceType)
            ->setResourceId($dataTableId)
            ->setCrudPermissions($fullCrud);
        $this->entityManager->persist($grant);

        return true;
    }

    /**
     * Form tables are named with the section id. Plugin or free-named tables
     * have no page; grant nothing extra (F15).
     */
    private function pageIdForFormTable(DataTable $dataTable): ?int
    {
        $name = $dataTable->getName();
        if ($name === null || $name === '' || !ctype_digit($name)) {
            return null;
        }

        $section = $this->entityManager->find(Section::class, (int) $name);
        if (!$section instanceof Section) {
            return null;
        }

        $link = $this->entityManager->getRepository(PagesSection::class)->findOneBy(['section' => $section]);
        if (!$link instanceof PagesSection || $link->getPage() === null || $link->getPage()->getId() === null) {
            return null;
        }

        return (int) $link->getPage()->getId();
    }

    /**
     * Roles that may edit the page: hold `admin.page.update` and the update bit
     * on the page grant. The admin role is excluded (never stored).
     *
     * @return list<int>
     */
    private function editorRoleIdsForPage(int $pageId, int $pageResourceTypeId, int $adminRoleId): array
    {
        /** @var list<array{id: int|string, crud: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT r.id', 'rda.crudPermissions AS crud')
            ->from(Role::class, 'r')
            ->innerJoin('r.permissions', 'p')
            ->innerJoin(RoleDataAccess::class, 'rda', 'WITH', 'rda.role = r')
            ->where('p.name = :permission')
            ->andWhere('rda.idResourceTypes = :pageType')
            ->andWhere('rda.resourceId = :pageId')
            ->andWhere('r.id != :adminRoleId')
            ->setParameter('permission', self::PAGE_UPDATE_PERMISSION)
            ->setParameter('pageType', $pageResourceTypeId)
            ->setParameter('pageId', $pageId)
            ->setParameter('adminRoleId', $adminRoleId)
            ->getQuery()
            ->getArrayResult();

        $updateBit = DataAccessSecurityService::PERMISSION_UPDATE;
        $ids = [];
        foreach ($rows as $row) {
            if (((int) $row['crud'] & $updateBit) === $updateBit) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
