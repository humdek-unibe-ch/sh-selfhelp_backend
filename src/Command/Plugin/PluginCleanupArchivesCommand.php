<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Archive\PluginArchiveCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `selfhelp:plugin:cleanup-archives` — reaps orphaned `.shplugin`
 * staging directories under `var/plugins/<id>-<ver>/staging/` that are
 * older than the configured retention window
 * (`SELFHELP_PLUGIN_ARCHIVE_RETENTION_DAYS`, default 7).
 *
 * Failed installs intentionally leave their staging dir behind so an
 * operator can re-run validation, but they accumulate over time on
 * busy hosts. Wire this command into cron / scheduled jobs once per
 * day in production.
 */
#[AsCommand(
    name: 'selfhelp:plugin:cleanup-archives',
    description: 'Purge orphaned .shplugin staging dirs older than the configured retention window.',
)]
final class PluginCleanupArchivesCommand extends Command
{
    public function __construct(
        private readonly PluginArchiveCleaner $cleaner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $purged = $this->cleaner->purgeOldStaging();
        if ($purged === []) {
            $io->success('No orphaned staging dirs to purge.');
            return Command::SUCCESS;
        }
        $io->success(sprintf('Purged %d orphaned staging dir(s).', count($purged)));
        foreach ($purged as $dir) {
            $io->writeln('  - ' . $dir);
        }
        return Command::SUCCESS;
    }
}
