<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Service\PluginAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `selfhelp:plugin:install <manifestPath>` — install a plugin from a
 * local plugin.json file. In development / trusted modes the same
 * command also finalizes the install in-process; in managed mode it
 * only records the operation and prints the next CLI step.
 */
#[AsCommand(
    name: 'selfhelp:plugin:install',
    description: 'Install a plugin from a plugin.json manifest path.',
)]
final class PluginInstallCommand extends Command
{
    public function __construct(
        private readonly PluginManifestLoader $manifestLoader,
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('manifest', InputArgument::REQUIRED, 'Absolute path to plugin.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('manifest');
        try {
            $manifest = $this->manifestLoader->loadFromFile($path);
        } catch (\Throwable $e) {
            $io->error('Manifest load failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        try {
            $operation = $this->pluginAdminService->install([
                'source' => 'paste',
                'manifest' => $manifest->toArray(),
            ]);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Install requested for "%s" v%s. Operation #%d (mode=%s) dispatched to the Messenger worker.',
            $manifest->getPluginId(),
            $manifest->getVersion(),
            (int) $operation['id'],
            $operation['installMode'],
        ));

        if ($operation['installMode'] === 'managed') {
            $io->note(sprintf(
                'Managed mode: the worker recorded a runbook. Run composer + migrations from the runbook, then call selfhelp:plugin:run-operation %d.',
                (int) $operation['id'],
            ));
        } else {
            $io->note(sprintf(
                'The Messenger worker is now running composer + finalize for operation #%d. Monitor selfhelp:plugin:operations:list or the Mercure stream.',
                (int) $operation['id'],
            ));
        }

        return Command::SUCCESS;
    }
}
