<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Exception\ServiceException;
use App\Plugin\Service\PluginAdminService;
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
        private readonly PluginAdminService $adminService,
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
        try {
            $result = $this->adminService->cancelOperation($opId);
        } catch (ServiceException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $status = (string) ($result['status'] ?? 'unknown');
        $pluginId = (string) ($result['pluginId'] ?? '');
        $type = (string) ($result['type'] ?? '');
        if ($status !== 'cancelled') {
            $io->warning(sprintf(
                'Operation #%d (%s/%s) was not cancellable; current status is "%s".',
                $opId,
                $pluginId,
                $type,
                $status
            ));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Operation #%d (%s/%s) cancelled. Subsequent plugin operations can now proceed.',
            $opId,
            $pluginId,
            $type
        ));
        return Command::SUCCESS;
    }
}
