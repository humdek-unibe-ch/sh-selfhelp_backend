<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Entity\User;
use App\Plugin\Event\Lifecycle\PluginOperationProgressEvent;
use App\Repository\Plugin\PluginOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Helper that records plugin operations in the staged
 * `plugin_operations` table and emits progress events.
 *
 * All orchestrators (installer / updater / enabler / uninstaller /
 * purger / repairer / rollbacker) use this recorder to keep the
 * `plugin_operations` table consistent. The recorder is intentionally
 * not transactional on its own — orchestrators wrap composer/npm/
 * Doctrine work in transactions and call the recorder to persist
 * stage transitions.
 *
 * Every state transition emits a `PluginOperationProgressEvent` so
 * the Mercure publisher can broadcast the change to admin UIs (no
 * polling).
 */
final class PluginOperationRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginOperationRepository $operations,
        private readonly UserContextService $userContext,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(
        string $pluginId,
        string $type,
        string $installMode,
        ?string $requestedVersion = null,
        ?string $fromVersion = null,
    ): PluginOperation {
        $operation = new PluginOperation($pluginId, $type);
        $operation->setInstallMode($installMode);
        if ($requestedVersion !== null) {
            $operation->setRequestedVersion($requestedVersion);
        }
        if ($fromVersion !== null) {
            $operation->setFromVersion($fromVersion);
        }

        // The User instance returned by UserContextService comes from the
        // security token, which JWTTokenAuthenticator caches via CacheService
        // — so it is *detached* from this EntityManager. Setting a detached
        // entity on the relationship makes Doctrine think it's a new entity
        // and demand cascade persist. Resolve a managed reference by id
        // instead, mirroring the pattern in TransactionService /
        // DataAccessSecurityService.
        $currentUser = $this->userContext->getCurrentUser();
        if ($currentUser instanceof User && $currentUser->getId() !== null) {
            $operation->setRequestedBy(
                $this->em->getReference(User::class, $currentUser->getId())
            );
        }

        $this->em->persist($operation);
        $this->em->flush();

        $this->logOperation('Plugin operation requested', $operation);
        $this->emitProgress($operation, sprintf('Operation %s requested', $type), 0);

        return $operation;
    }

    public function markRunning(PluginOperation $operation, ?string $stage = null): void
    {
        $operation->setStatus(PluginOperation::STATUS_RUNNING);
        $operation->setStartedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->flush();
        $this->logOperation($stage ?? 'Plugin operation running', $operation);
        $this->emitProgress($operation, $stage ?? 'Running', 5);
    }

    public function snapshot(PluginOperation $operation, array $snapshot): void
    {
        $existing = $operation->getSnapshotsJson() ?? [];
        $operation->setSnapshotsJson(array_merge($existing, $snapshot));
        $this->em->flush();
    }

    public function setRollbackPlan(PluginOperation $operation, array $plan): void
    {
        $operation->setRollbackPlanJson($plan);
        $this->em->flush();
    }

    public function appendLog(PluginOperation $operation, string $stage, array $extra = [], ?int $percent = null): void
    {
        $entry = array_merge(['stage' => $stage], $extra);
        $operation->appendLog($entry);
        $this->em->flush();
        $this->emitProgress($operation, $stage, $percent);
    }

    public function succeed(PluginOperation $operation, ?string $stage = null, ?Plugin $plugin = null, ?string $toVersion = null): void
    {
        if ($plugin !== null) {
            $operation->setPlugin($plugin);
        }
        if ($toVersion !== null) {
            $operation->setToVersion($toVersion);
        }
        $operation->setStatus(PluginOperation::STATUS_SUCCEEDED);
        $operation->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $operation->appendLog(['stage' => $stage ?? 'Completed', 'status' => 'succeeded']);
        $this->em->flush();
        $this->logOperation('Plugin operation succeeded', $operation);
        $this->emitProgress($operation, $stage ?? 'Completed', 100);
    }

    public function fail(PluginOperation $operation, \Throwable $error, ?string $stage = null): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $operation->setStatus(PluginOperation::STATUS_FAILED);
        $operation->setFinishedAt($now);
        $operation->setErrorSummary($error->getMessage());
        $operation->appendLog([
            'stage' => $stage ?? 'Failed',
            'status' => 'failed',
            'error' => $error->getMessage(),
            'class' => $error::class,
        ]);

        // Orchestrators frequently land in this method *because* the EM was
        // poisoned by an earlier DB error (an exception during flush()
        // automatically closes the EM and any open transaction is left in a
        // half-committed state — most visibly when DDL inside the surrounding
        // transaction implicitly committed and a later rollback raises
        // NoActiveTransaction). If we tried em->flush() blindly we would
        // throw a NEW "EntityManager is closed" / "There is no active
        // transaction" exception that would replace the real cause and leave
        // the operation row stuck in `running`, blocking every subsequent
        // plugin operation until the 15-minute lock TTL expired. Persist the
        // failure via raw DBAL when the ORM cannot help us anymore.
        try {
            $this->em->flush();
        } catch (\Throwable $flushFailure) {
            $this->logger->warning('Plugin operation fail() could not flush via EntityManager — falling back to raw DBAL update', [
                'plugin_id' => $operation->getPluginId(),
                'operation_id' => $operation->getId(),
                'flush_error' => $flushFailure->getMessage(),
            ]);
            $this->persistFailureViaDbal($operation, $now);
        }

        $this->logger->error('Plugin operation failed', [
            'plugin_id' => $operation->getPluginId(),
            'operation_id' => $operation->getId(),
            'type' => $operation->getType(),
            'error' => $error->getMessage(),
        ]);
        $this->emitProgress($operation, $stage ?? 'Failed', null);
    }

    /**
     * Persists the failed-state fields directly via DBAL. Used as the
     * last-resort path inside {@see fail()} when {@see em->flush()} is no
     * longer usable. Keeps the `plugin_operations` row honest so the
     * concurrent-operation guard does not see a phantom RUNNING row.
     */
    private function persistFailureViaDbal(PluginOperation $operation, \DateTimeImmutable $finishedAt): void
    {
        try {
            // Roll back any half-finished transaction so the UPDATE below
            // can run cleanly. Best-effort — if the connection has no
            // active transaction, this is a no-op for our purposes.
            if ($this->connection->isTransactionActive()) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable) {
                    // Swallow: we are already in a failure recovery path.
                }
            }
            $this->connection->update(
                'plugin_operations',
                [
                    'status' => $operation->getStatus(),
                    'finished_at' => $finishedAt,
                    'error_summary' => $operation->getErrorSummary(),
                    'logs_json' => $operation->getLogsJson() !== null ? json_encode($operation->getLogsJson(), JSON_UNESCAPED_SLASHES) : null,
                ],
                ['id' => $operation->getId()],
                [
                    'status' => Types::STRING,
                    'finished_at' => Types::DATETIME_IMMUTABLE,
                    'error_summary' => Types::TEXT,
                    'logs_json' => Types::TEXT,
                    'id' => Types::INTEGER,
                ],
            );
        } catch (\Throwable $dbalFailure) {
            $this->logger->critical('Plugin operation fail() raw DBAL fallback also failed — operation row will need manual cancellation via selfhelp:plugin:cancel-operation', [
                'plugin_id' => $operation->getPluginId(),
                'operation_id' => $operation->getId(),
                'dbal_error' => $dbalFailure->getMessage(),
            ]);
        }
    }

    public function markRolledBack(PluginOperation $operation, ?string $stage = null): void
    {
        $operation->setStatus(PluginOperation::STATUS_ROLLED_BACK);
        $operation->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $operation->appendLog([
            'stage' => $stage ?? 'Rolled back',
            'status' => 'rolled_back',
        ]);
        $this->em->flush();
        $this->emitProgress($operation, $stage ?? 'Rolled back', null);
    }

    private function emitProgress(PluginOperation $operation, ?string $stage, ?int $percent): void
    {
        $this->events->dispatch(new PluginOperationProgressEvent($operation, $stage, $percent));
    }

    private function logOperation(string $verbalLog, PluginOperation $operation): void
    {
        $this->transactions->logTransaction(
            LookupService::TRANSACTION_TYPES_INSERT,
            LookupService::TRANSACTION_BY_BY_USER,
            'plugin_operations',
            $operation->getId(),
            false,
            sprintf('%s: plugin=%s op=%s status=%s', $verbalLog, $operation->getPluginId(), $operation->getType(), $operation->getStatus())
        );
    }
}
