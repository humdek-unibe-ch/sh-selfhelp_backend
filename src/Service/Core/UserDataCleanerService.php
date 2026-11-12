<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

use App\Entity\DataAccessAudit;
use App\Entity\Transaction;
use App\Plugin\Event\UserDataCleanupEvent;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\FormFileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The one function that removes what a user produced. Every path that removes
 * a user (admin delete, bulk delete, self-service account deletion) and the
 * admin "clean user data" action call it, so no row is left pointing at a user
 * that no longer exists (concept rule "Removing a user removes their data", F24).
 *
 * Two phases, because files and caches cannot roll back:
 *  1. {@see removeData()} runs INSIDE the caller's database transaction, so a
 *     failure anywhere (for example while deleting the user afterwards) rolls the
 *     whole erasure back.
 *  2. {@see finishAfterCommit()} runs once that transaction has committed: it
 *     deletes the uploaded form files, runs the work plugins asked to do after
 *     the commit, and clears the caches that showed the data.
 *
 * What goes, what stays:
 *  - Removed: scheduled jobs of the user and the recipient rows that point at
 *    the user on other people's jobs, every data row the user owns with its
 *    cells, the user's API request log rows, and the form files they uploaded.
 *  - Kept, with the person removed: the `transactions` and `data_access_audits`
 *    rows. They are the audit trail of what changed and who was refused, so the
 *    rows stay and only the link to the user is cleared. (`transactions` records
 *    the ACTING user, so this cuts loose what the user did; admin actions
 *    performed ON them stay attributed to that admin.)
 *  - Not touched here: session and sign-in state (refresh tokens, 2FA codes,
 *    validation codes, navigation state). It is not user-produced content and
 *    dies with the account or expires on its own. Page views hold no user id.
 *
 * Plugin-owned user data (for example chat conversations) in tables a plugin
 * owns itself is removed by the plugin: it listens to
 * {@see UserDataCleanupEvent}, which {@see removeData()} dispatches inside the
 * transaction (F83). Plugin data in the generic data store is removed here.
 */
final class UserDataCleanerService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFileUploadService $formFileUploadService,
        private readonly CacheService $cache,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Phase 1: remove the user's data inside the caller's open transaction.
     *
     * @return UserDataRemoval what was removed (`removed` counts rows per
     *         kind; for `transactions` and `data_access_audits` the rows that
     *         were detached from the user, not deleted) and what phase 2 needs:
     *         pass it to {@see finishAfterCommit()} once the transaction commits
     */
    public function removeData(int $userId): UserDataRemoval
    {
        // Which data tables lose rows, so their caches can be cleared later.
        $dataTableIds = [];
        $rawTableIds = $this->entityManager
            ->createQuery('SELECT DISTINCT IDENTITY(dr.dataTable) FROM App\Entity\DataRow dr WHERE dr.idUsers = :userId')
            ->setParameter('userId', $userId)
            ->getSingleColumnResult();
        foreach ($rawTableIds as $rawTableId) {
            if (is_numeric($rawTableId) && (int) $rawTableId > 0) {
                $dataTableIds[] = (int) $rawTableId;
            }
        }

        // Plugins that own tables with user data remove their rows first (F83).
        // Inside this transaction, so a listener that fails stops the whole
        // erasure; before the core rows go, so a plugin row that points at one
        // can still be removed and the plugin can still read what it needs to
        // find its files. Audit rows a listener writes for the user are cut
        // loose with the others below.
        $event = new UserDataCleanupEvent($userId);
        $this->eventDispatcher->dispatch($event);

        $removed = [];

        // Recipient rows pointing at this user on jobs owned by SOMEONE ELSE.
        // They are not reachable from "jobs this user owns" below, but they are
        // a record of what this user was sent, so an erasure covers them. Their
        // FK is ON DELETE SET NULL, so deleting the jobs would otherwise leave
        // them behind with a null user.
        $removed['scheduled_job_recipients'] = $this->affected(
            'DELETE FROM App\Entity\ScheduledJobRecipient r WHERE r.user = :userId',
            $userId
        );

        // Jobs owned by the user. scheduled_job_recipients and
        // scheduled_job_reminders both FK to scheduled_jobs with ON DELETE
        // CASCADE, so their remaining rows go with these.
        $removed['scheduled_jobs'] = $this->affected(
            'DELETE FROM App\Entity\ScheduledJob j WHERE j.user = :userId',
            $userId
        );

        // Data cells are deleted explicitly rather than relying on the
        // fk_data_cells_id_data_rows ON DELETE CASCADE: a bulk DQL DELETE
        // bypasses Doctrine's ORM-level cascade entirely, so the erasure must
        // not depend on the DB constraint staying in place.
        $removed['data_cells'] = $this->affected(
            'DELETE FROM App\Entity\DataCell c WHERE c.dataRow IN ('
            . 'SELECT dr FROM App\Entity\DataRow dr WHERE dr.idUsers = :userId)',
            $userId
        );
        $removed['data_rows'] = $this->affected(
            'DELETE FROM App\Entity\DataRow dr WHERE dr.idUsers = :userId',
            $userId
        );

        // Audit rows stay, the person goes: clear the link, keep the row. Done
        // explicitly (not only by the ON DELETE SET NULL rule) because the
        // "clean data" action keeps the account, so the foreign key never fires.
        $removed['transactions'] = $this->affected(
            'UPDATE App\Entity\Transaction t SET t.idUsers = NULL WHERE t.idUsers = :userId',
            $userId
        );
        $removed['data_access_audits'] = $this->affected(
            'UPDATE App\Entity\DataAccessAudit a SET a.idUsers = NULL WHERE a.idUsers = :userId',
            $userId
        );

        // The request log stores URLs and parameters that can carry personal
        // data, so it is erased, not kept. It has no foreign key to users.
        $removed['api_request_logs'] = $this->affected(
            'DELETE FROM App\Entity\ApiRequestLog l WHERE l.userId = :userId',
            $userId
        );

        $this->refreshManagedAuditRows($userId);

        return new UserDataRemoval($removed, $dataTableIds, $event->getAfterCommitCallbacks());
    }

    /**
     * Phase 2: after the caller's transaction committed, delete the user's
     * uploaded form files, run the work plugins asked to do after the commit,
     * and clear the caches that showed their data.
     *
     * @param UserDataRemoval $removal what {@see removeData()} returned
     * @return int number of form files removed
     */
    public function finishAfterCommit(int $userId, UserDataRemoval $removal): int
    {
        // Files cannot roll back, so they go only once the rows are gone. The
        // data cells that pointed at them were just deleted.
        $files = $this->formFileUploadService->deleteUserFiles($userId);

        // The data is gone for good, so a plugin that fails here must not make
        // the removal look failed, and must not stop the plugins after it.
        foreach ($removal->afterCommit as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->logger->error('A plugin failed to finish removing user data after the commit', [
                    'userId' => $userId,
                    'exception' => $e,
                ]);
            }
        }

        if ($removal->dataTableIds !== []) {
            // Every data table read is cached under the table's own scope, so
            // bumping it also refreshes what other users see of that table.
            $this->cache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_DATA_TABLE, $removal->dataTableIds);
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateAllListsInCategory();
        }

        return $files;
    }

    /**
     * The bulk statements above bypass the unit of work. An audit row that was
     * already loaded or written in this request (the deletion log of a user who
     * deletes their own account) still points at the user in memory; once the
     * user is removed, the next flush of the same request would stop with
     * "a new entity was found through the relationship". Re-read those rows so
     * memory matches the database: kept, without the user.
     */
    private function refreshManagedAuditRows(int $userId): void
    {
        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();

        foreach ([Transaction::class, DataAccessAudit::class] as $class) {
            foreach ($identityMap[$class] ?? [] as $row) {
                if (
                    ($row instanceof Transaction || $row instanceof DataAccessAudit)
                    && $row->getUser()?->getId() === $userId
                ) {
                    $this->entityManager->refresh($row);
                }
            }
        }
    }

    /**
     * Run a bulk DQL statement and return how many rows it touched.
     */
    private function affected(string $dql, int $userId): int
    {
        $result = $this->entityManager
            ->createQuery($dql)
            ->setParameter('userId', $userId)
            ->execute();

        return is_numeric($result) ? (int) $result : 0;
    }
}
