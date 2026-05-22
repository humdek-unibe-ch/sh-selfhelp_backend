<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:check-compatibility',
    description: 'Run compatibility checks for every installed plugin against the current CMS / SDK version.',
)]
final class PluginCheckCompatibilityCommand extends Command
{
    public function __construct(
        private readonly PluginRepository $plugins,
        private readonly PluginCompatibilityValidator $compatibility,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plugins = $this->plugins->findAllOrderedByName();

        if ($plugins === []) {
            $io->info('No plugins installed.');
            return Command::SUCCESS;
        }

        $rows = [];
        $exit = Command::SUCCESS;
        foreach ($plugins as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $report = $this->compatibility->check($manifest);
            $rows[] = [
                $plugin->getPluginId(),
                $plugin->getVersion(),
                $report['severity'],
                implode('; ', $report['reasons']) ?: 'ok',
            ];
            if ($report['severity'] === 'blocking') {
                $exit = Command::FAILURE;
            }
        }

        $io->table(['Plugin', 'Version', 'Severity', 'Notes'], $rows);
        return $exit;
    }
}
