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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:safe-mode',
    description: 'Toggle plugin safe mode (boots Symfony without any plugin bundles).',
)]
final class PluginSafeModeCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('enable', null, InputOption::VALUE_NONE, 'Enable safe mode (creates var/plugin_safe_mode.lock).');
        $this->addOption('disable', null, InputOption::VALUE_NONE, 'Disable safe mode and regenerate plugin bundles file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');

        if (!$enable && !$disable) {
            $io->info(sprintf('Safe mode is currently %s.', $this->pluginAdminService->isSafeModeOn() ? 'ENABLED' : 'disabled'));
            return Command::SUCCESS;
        }
        if ($enable && $disable) {
            $io->error('Pass only one of --enable / --disable.');
            return Command::FAILURE;
        }

        if ($enable) {
            $this->pluginAdminService->safeModeEnable();
            $io->success('Plugin safe mode enabled. Plugins will not load on next boot.');
        } else {
            $this->pluginAdminService->safeModeDisable();
            $io->success('Plugin safe mode disabled. Plugin bundles file regenerated.');
        }

        return Command::SUCCESS;
    }
}
