<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Exception\ServiceException;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rolls a failed plugin operation back to its last known good state.
 *
 * The rollbacker reads `rollback_plan_json` + `snapshots_json` from a
 * `plugin_operations` row in `failed` status and executes the steps
 * in reverse order:
 *
 *   - restore the previous lock file from snapshot,
 *   - regenerate the bundles file,
 *   - clear plugin caches,
 *   - mark the operation as `rolled_back`.
 *
 * DB rollback is intentionally *not* automatic — the plan calls for
 * `manual_intervention_required` whenever migrations have run. The
 * rollbacker only touches files/config.
 */
final class PluginRollbacker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationRepository $operations,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginRegistryService $registry,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly InstallModeResolver $installModeResolver,
        private readonly TransactionService $transactions,
    ) {
    }

    public function rollback(int $operationId): PluginOperation
    {
        $operation = $this->operations->find($operationId);
        if (!$operation instanceof PluginOperation) {
            throw new ServiceException('Plugin operation not found.', Response::HTTP_NOT_FOUND);
        }
        if ($operation->getStatus() !== PluginOperation::STATUS_FAILED) {
            throw new ServiceException(sprintf(
                'Operation %d is not in failed state (current: %s); only failed operations may be rolled back.',
                $operationId,
                $operation->getStatus()
            ), Response::HTTP_CONFLICT);
        }

        $this->lock->assertCanStart($operation->getPluginId());

        try {
            $rollback = $operation->getRollbackPlanJson() ?? [];
            $snapshots = $operation->getSnapshotsJson() ?? [];

            $rollbackOp = $this->recorder->start(
                $operation->getPluginId(),
                PluginOperation::TYPE_ROLLBACK,
                $this->installModeResolver->resolve(),
                null,
                $operation->getFromVersion(),
            );
            $this->recorder->markRunning($rollbackOp, sprintf('Rolling back operation %d', $operationId));

            try {
                if (($rollback['restoreLockFile'] ?? false) && array_key_exists('lockFileBefore', $snapshots)) {
                    $this->lockFileWriter->restore($snapshots['lockFileBefore']);
                    $this->recorder->appendLog($rollbackOp, 'Restored selfhelp.plugins.lock.json');
                }

                if ($rollback['regenerateBundles'] ?? false) {
                    $this->bundlesWriter->regenerate();
                    $this->recorder->appendLog($rollbackOp, 'Regenerated config/selfhelp_plugin_bundles.php');
                }

                $this->registry->invalidate();
                $this->recorder->appendLog($rollbackOp, 'Invalidated plugin caches');

                $this->em->beginTransaction();
                try {
                    $operation->setStatus(PluginOperation::STATUS_ROLLED_BACK);
                    $this->em->flush();
                    $this->em->commit();
                } catch (\Throwable $e) {
                    $this->em->rollback();
                    throw $e;
                }

                $this->transactions->logTransaction(
                    LookupService::TRANSACTION_TYPES_UPDATE,
                    LookupService::TRANSACTION_BY_BY_USER,
                    'plugin_operations',
                    $operation->getId(),
                    false,
                    sprintf('Plugin operation %d rolled back', $operationId)
                );

                $this->recorder->succeed($rollbackOp, 'Rollback complete', $this->plugins->findOneByPluginId($operation->getPluginId()));
                return $rollbackOp;
            } catch (\Throwable $e) {
                $this->recorder->fail($rollbackOp, $e, 'rollback');
                throw $e;
            }
        } finally {
            $this->lock->release($operation->getPluginId());
        }
    }
}
