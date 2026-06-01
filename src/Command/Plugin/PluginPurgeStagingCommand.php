<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `selfhelp:plugin:purge-staging <pluginId> [--all]` — unconditional
 * purge of `var/plugins/<pluginId>-*` (every version's `staging/`
 * folder) so an operator can wipe a corrupted upload without waiting
 * for the retention window. With `--all`, wipes the whole `var/plugins`
 * directory.
 *
 * Intentionally does not touch `installed/` directories or
 * `public/plugin-artifacts/` — those belong to the live plugin and are
 * managed by `selfhelp:plugin:uninstall` / `selfhelp:plugin:purge`.
 */
#[AsCommand(
    name: 'selfhelp:plugin:purge-staging',
    description: 'Force-delete `.shplugin` staging dirs for one plugin (or all plugins with --all). Leaves installed/ + public artifacts intact.',
)]
final class PluginPurgeStagingCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pluginId', InputArgument::OPTIONAL, 'Plugin id whose staging dirs should be purged.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Purge staging dirs for every plugin under var/plugins/. Mutually exclusive with pluginId.')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required to actually delete (dry-run otherwise).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginIdRaw = $input->getArgument('pluginId');
        $pluginId = is_string($pluginIdRaw) ? $pluginIdRaw : '';
        $all = (bool) $input->getOption('all');
        $confirm = (bool) $input->getOption('confirm');

        if ($all && $pluginId !== '') {
            $io->error('Pass either <pluginId> or --all, not both.');
            return Command::FAILURE;
        }
        if (!$all && $pluginId === '') {
            $io->error('Provide <pluginId> or pass --all.');
            return Command::FAILURE;
        }

        $base = rtrim($this->projectDir, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'plugins';
        if (!is_dir($base)) {
            $io->success(sprintf('No staging dirs to purge: %s does not exist.', $base));
            return Command::SUCCESS;
        }

        $pattern = $all ? $base . '/*-*/staging' : $base . '/' . $pluginId . '-*/staging';
        $matches = glob($pattern) ?: [];
        if ($matches === []) {
            $io->success(sprintf('No staging dirs match %s.', $pattern));
            return Command::SUCCESS;
        }

        if (!$confirm) {
            $io->warning('Dry-run. Re-run with --confirm to actually delete:');
            foreach ($matches as $dir) {
                $io->writeln('  - ' . $dir);
            }
            return Command::SUCCESS;
        }

        foreach ($matches as $dir) {
            try {
                $this->filesystem->remove($dir);
                $io->writeln('Removed ' . $dir);
            } catch (\Throwable $e) {
                $io->warning(sprintf('Failed to remove %s: %s', $dir, $e->getMessage()));
            }
        }
        $io->success(sprintf('Purged %d staging dir(s).', count($matches)));
        return Command::SUCCESS;
    }
}
