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

/**
 * `selfhelp:plugin:status [pluginId]` — list installed plugins or
 * print a single plugin's status.
 */
#[AsCommand(
    name: 'selfhelp:plugin:status',
    description: 'List installed plugins or show one plugin status.',
)]
final class PluginStatusCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginId', InputArgument::OPTIONAL, 'Optional plugin id (omit to list all plugins).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginId = $input->getArgument('pluginId');

        if (is_string($pluginId) && $pluginId !== '') {
            try {
                $plugin = $this->pluginAdminService->getPlugin($pluginId);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
            $io->title(sprintf('Plugin: %s', $plugin['pluginId']));
            $io->definitionList(
                ['Name' => $plugin['name']],
                ['Version' => $plugin['version']],
                ['Enabled' => $plugin['enabled'] ? 'yes' : 'no'],
                ['Trust' => $plugin['trustLevel']],
                ['Install mode' => $plugin['installMode']],
                ['Updated at' => $plugin['updatedAt']],
            );
            return Command::SUCCESS;
        }

        $plugins = $this->pluginAdminService->listPlugins();
        if ($plugins === []) {
            $io->info('No plugins installed.');
        } else {
            $rows = array_map(
                static fn(array $p): array => [
                    $p['pluginId'],
                    $p['version'],
                    $p['enabled'] ? 'enabled' : 'disabled',
                    $p['trustLevel'],
                    $p['installMode'],
                ],
                $plugins,
            );
            $io->table(['Plugin', 'Version', 'Status', 'Trust', 'Install mode'], $rows);
        }
        $io->success(sprintf('Install mode: %s; safe mode: %s', $this->pluginAdminService->getInstallMode(), $this->pluginAdminService->isSafeModeOn() ? 'on' : 'off'));
        return Command::SUCCESS;
    }
}
