<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Service\PluginAdminService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared base for enable/disable commands. Subclasses ship the
 * `#[AsCommand]` attribute pointing at `selfhelp:plugin:enable` or
 * `selfhelp:plugin:disable` respectively.
 */
abstract class PluginEnableDisableCommand extends Command
{
    public function __construct(
        protected readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    abstract protected function shouldDisable(): bool;

    protected function configure(): void
    {
        $this->addArgument('pluginId', InputArgument::REQUIRED, 'Plugin id (e.g. sh2-shp-survey-js).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginIdArg = $input->getArgument('pluginId');
        $pluginId = is_string($pluginIdArg) ? $pluginIdArg : '';

        try {
            $plugin = $this->shouldDisable()
                ? $this->pluginAdminService->disable($pluginId)
                : $this->pluginAdminService->enable($pluginId);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Plugin "%s" is now %s.',
            is_string($plugin['pluginId'] ?? null) ? $plugin['pluginId'] : $pluginId,
            !empty($plugin['enabled']) ? 'enabled' : 'disabled',
        ));
        return Command::SUCCESS;
    }
}
