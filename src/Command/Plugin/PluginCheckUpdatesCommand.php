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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `selfhelp:plugin:check-updates` — cross-reference installed plugins
 * against the registry index. Prints one row per upgradeable plugin
 * (installed version → available version, source, diff kind).
 *
 * Exits with non-zero status when at least one upgrade is available,
 * so CI / cron / scheduled jobs can use the exit code to gate a
 * follow-up `composer require` step or to surface a notification.
 */
#[AsCommand(
    name: 'selfhelp:plugin:check-updates',
    description: 'List installed plugins with newer versions available in any enabled registry source.',
)]
final class PluginCheckUpdatesCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->pluginAdminService->listAvailableUpdates();
        if ($rows === []) {
            $io->success('All installed plugins are up-to-date.');
            return Command::SUCCESS;
        }
        $io->title(sprintf('%d plugin update(s) available', count($rows)));
        $table = [];
        foreach ($rows as $row) {
            $table[] = [
                $row['pluginId'],
                $row['installedVersion'],
                $row['availableVersion'],
                $row['diffKind'],
                $row['sourceName'],
                $row['trustLevel'],
            ];
        }
        $io->table(['Plugin', 'Installed', 'Available', 'Diff', 'Source', 'Trust'], $table);
        return 2;
    }
}
