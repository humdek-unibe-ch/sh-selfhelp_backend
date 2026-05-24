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
    name: 'selfhelp:plugin:repair',
    description: 'Regenerate config/selfhelp_plugin_bundles.php + selfhelp.plugins.lock.json from DB state.',
    aliases: ['selfhelp:plugin:sync-lock'],
)]
final class PluginRepairCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // `selfhelp:plugin:sync-lock` is kept as an alias so existing
        // operator runbooks / CI scripts that call the old name keep
        // working. They both call `PluginAdminService::repair()` —
        // the old command was a strict subset of this one.
        $this->addArgument('pluginId', InputArgument::OPTIONAL, 'Plugin id (omit to repair every plugin / regenerate the lock file and bundles file).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginId = $input->getArgument('pluginId');
        try {
            $result = $this->pluginAdminService->repair(is_string($pluginId) && $pluginId !== '' ? $pluginId : null);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success('Plugin layer repaired.');
        if (isset($result['plugins'])) {
            $io->listing($result['plugins']);
        } elseif (isset($result['pluginId'])) {
            $io->writeln(sprintf('Plugin: %s', $result['pluginId']));
        }
        return Command::SUCCESS;
    }
}
