<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DataTable;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Grants the admin role full CRUD on every newly-persisted DataTable.
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
    private const ADMIN_ROLE_NAME = 'admin';

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

        $adminRole = $this->entityManager->getRepository(Role::class)->findOneBy(['name' => self::ADMIN_ROLE_NAME]);
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

        $fullCrud = DataAccessSecurityService::PERMISSION_CREATE
            | DataAccessSecurityService::PERMISSION_READ
            | DataAccessSecurityService::PERMISSION_UPDATE
            | DataAccessSecurityService::PERMISSION_DELETE;

        $needsFlush = false;
        foreach ($batch as $dataTable) {
            $dataTableId = $dataTable->getId();
            if ($dataTableId === null) {
                continue;
            }

            $existing = $this->entityManager->getRepository(RoleDataAccess::class)->findOneBy([
                'idRoles' => $adminRole->getId(),
                'idResourceTypes' => $resourceType->getId(),
                'resourceId' => $dataTableId,
            ]);
            if ($existing instanceof RoleDataAccess) {
                continue;
            }

            $grant = new RoleDataAccess();
            $grant->setRole($adminRole)
                ->setResourceType($resourceType)
                ->setResourceId($dataTableId)
                ->setCrudPermissions($fullCrud);
            $this->entityManager->persist($grant);
            $needsFlush = true;
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
        } catch (\Throwable $e) {
            $this->logger->error('DataTableAdminAccessListener: failed to persist admin grants', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
