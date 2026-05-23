<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Lifecycle\InstallModeResolver;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Service\PluginAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:update',
    description: 'Update an installed plugin from a new plugin.json manifest.',
)]
final class PluginUpdateCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly PluginManifestLoader $manifestLoader,
        private readonly InstallModeResolver $installModeResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('manifest', InputArgument::REQUIRED, 'Path to the new plugin.json manifest file.');
        $this->addOption('force-major', null, InputOption::VALUE_NONE, 'Acknowledge a major version bump (otherwise refused).');
        $this->addOption('finalize', null, InputOption::VALUE_NONE, 'Finalize the operation after composer/npm + migrations have run.');
        $this->addOption(
            'backup-before',
            null,
            InputOption::VALUE_NONE,
            'Run the configured PluginBackupHook before staging the update so plugin-owned tables can be restored on failure. Combine with a DB dump in production.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('manifest');
        if (!is_file($path)) {
            $io->error(sprintf('Manifest file "%s" does not exist.', $path));
            return Command::FAILURE;
        }

        try {
            $manifest = $this->manifestLoader->loadFromFile($path);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $force = (bool) $input->getOption('force-major');
        $backupBefore = (bool) $input->getOption('backup-before');
        $mode = $this->installModeResolver->resolve();

        try {
            $result = $this->pluginAdminService->update([
                'source' => 'paste',
                'manifest' => $manifest->toArray(),
                'forceMajor' => $force,
                'backupBefore' => $backupBefore,
            ]);
            $operationId = (int) $result['id'];
            $io->success(sprintf(
                'Update operation requested. Operation id #%d, mode=%s — dispatched to the Messenger worker.',
                $operationId,
                $mode,
            ));

            if ($input->getOption('finalize')) {
                // Operator wants to inline-finalize after running composer themselves.
                $plugin = $this->pluginAdminService->finalizeUpdate($operationId, $manifest->toArray());
                $io->success(sprintf('Plugin "%s" updated to %s.', $plugin['pluginId'], $plugin['version']));
            } elseif ($mode === 'managed') {
                $io->note(sprintf(
                    'Managed mode: run composer + migrations from the recorded runbook, then call selfhelp:plugin:run-operation %d.',
                    $operationId,
                ));
            } else {
                $io->note(sprintf(
                    'The Messenger worker is now running composer + finalize for operation #%d.',
                    $operationId,
                ));
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
