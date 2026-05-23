<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Entity\Plugin\PluginOperation;
use App\Repository\Plugin\PluginOperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Force-cancel a stuck plugin operation.
 *
 * Operations land in `running` status when {@see \App\Plugin\Lifecycle\PluginOperationLock}
 * grants the lock and the orchestrator calls `markRunning()`, but the
 * underlying PHP process / Messenger worker dies before reaching
 * `succeed()` / `fail()`. The DB row then blocks every subsequent
 * install / update / purge / uninstall request for any plugin with
 * "Another plugin operation is currently running (id=<n>, ...)".
 *
 * `assertCanStart()` already auto-supersedes such rows once they pass
 * the 15-minute TTL, but operators sometimes need immediate relief —
 * e.g. when a purge crashed and the operator wants to retry NOW
 * instead of waiting. This command provides that escape hatch.
 *
 * Usage:
 *
 *   php bin/console selfhelp:plugin:cancel-operation 29
 */
#[AsCommand(
    name: 'selfhelp:plugin:cancel-operation',
    description: 'Force-cancel a stuck plugin operation row (status REQUESTED or RUNNING) so new operations can proceed.',
)]
final class PluginCancelOperationCommand extends Command
{
    public function __construct(
        private readonly PluginOperationRepository $operations,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('operationId', InputArgument::REQUIRED, 'plugin_operations row id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $opId = (int) $input->getArgument('operationId');
        $operation = $this->operations->find($opId);
        if (!$operation instanceof PluginOperation) {
            $io->error(sprintf('Plugin operation #%d not found.', $opId));
            return Command::FAILURE;
        }

        $status = $operation->getStatus();
        if (!in_array($status, [PluginOperation::STATUS_REQUESTED, PluginOperation::STATUS_RUNNING], true)) {
            $io->warning(sprintf(
                'Operation #%d is in "%s" status; nothing to cancel (only REQUESTED or RUNNING operations can be cancelled).',
                $opId,
                $status
            ));
            return Command::SUCCESS;
        }

        $operation->setStatus(PluginOperation::STATUS_CANCELLED);
        $operation->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $operation->appendLog([
            'event' => 'cancelled-by-operator',
            'message' => sprintf(
                'Operation #%d (%s/%s) force-cancelled via selfhelp:plugin:cancel-operation. Previous status: %s.',
                $opId,
                $operation->getPluginId(),
                $operation->getType(),
                $status
            ),
        ]);
        $this->em->persist($operation);
        $this->em->flush();

        $io->success(sprintf(
            'Operation #%d (%s/%s) cancelled. Previous status: %s. Subsequent plugin operations can now proceed.',
            $opId,
            $operation->getPluginId(),
            $operation->getType(),
            $status
        ));
        return Command::SUCCESS;
    }
}
