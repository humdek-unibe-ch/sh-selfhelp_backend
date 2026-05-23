<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Service\PluginAdminService;
use App\Repository\Plugin\PluginOperationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Managed-mode runner.
 *
 * The admin UI records the operation in `requested` state and prints
 * a CLI command. The CI / CLI worker then runs this command with the
 * operation id; it inspects the operation type, runs composer/npm and
 * Doctrine migrations as appropriate, and calls the matching
 * `finalize-*` method on `PluginAdminService`.
 *
 * The actual composer / npm invocations are out-of-scope for the
 * command itself — they are deployment-specific scripts. Here we
 * simply finalize the install when the operator has already run the
 * external package work.
 */
#[AsCommand(
    name: 'selfhelp:plugin:run-operation',
    description: 'Finalize a plugin operation after composer/npm + migrations have been executed.',
)]
final class PluginRunOperationCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly PluginOperationRepository $operations,
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

        $snapshots = $operation->getSnapshotsJson() ?? [];

        try {
            switch ($operation->getType()) {
                case PluginOperation::TYPE_INSTALL:
                    $manifestData = $snapshots['manifest'] ?? null;
                    if (!is_array($manifestData)) {
                        $io->error('Operation snapshot is missing the manifest payload; cannot finalize install.');
                        return Command::FAILURE;
                    }
                    $this->pluginAdminService->finalizeInstall($opId, $manifestData);
                    break;
                case PluginOperation::TYPE_UPDATE:
                    $manifestData = $snapshots['newManifest'] ?? $snapshots['manifest'] ?? null;
                    if (!is_array($manifestData)) {
                        $io->error('Operation snapshot is missing the new manifest payload; cannot finalize update.');
                        return Command::FAILURE;
                    }
                    $this->pluginAdminService->finalizeUpdate($opId, $manifestData);
                    break;
                case PluginOperation::TYPE_UNINSTALL:
                    $this->pluginAdminService->finalizeUninstall($opId);
                    break;
                default:
                    $io->error(sprintf('Operation type "%s" cannot be finalized by run-operation.', $operation->getType()));
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Operation #%d (%s) finalized.', $opId, $operation->getType()));
        return Command::SUCCESS;
    }
}
