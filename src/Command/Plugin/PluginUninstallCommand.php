<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Service\PluginAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:uninstall',
    description: 'Uninstall a plugin (packages removed; data preserved).',
)]
final class PluginUninstallCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginId', InputArgument::REQUIRED, 'Plugin id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginId = (string) $input->getArgument('pluginId');
        try {
            $operation = $this->pluginAdminService->uninstall($pluginId);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Uninstall requested for "%s". Operation #%d (mode=%s) dispatched to the Messenger worker.',
            $pluginId,
            (int) $operation['id'],
            (string) $operation['installMode'],
        ));
        if (($operation['installMode'] ?? null) === 'managed') {
            $io->note(sprintf(
                'Managed mode: the worker recorded a runbook (composer remove). After the operator deploys the change, call selfhelp:plugin:run-operation %d to delete the plugin row + regenerate the lock file.',
                (int) $operation['id'],
            ));
        } else {
            $io->note(sprintf(
                'The Messenger worker is now running composer remove + finalize for operation #%d. Monitor selfhelp:plugin:operations:list or the Mercure stream.',
                (int) $operation['id'],
            ));
        }
        return Command::SUCCESS;
    }
}
