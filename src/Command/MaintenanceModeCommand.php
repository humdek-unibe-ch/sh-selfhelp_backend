<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command;

use App\Service\System\MaintenanceModeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Toggle maintenance mode for the CURRENT instance from the CLI.
 *
 * The SelfHelp Manager calls this (via `docker compose exec backend php
 * bin/console selfhelp:maintenance --enable|--disable`) around an update so the
 * backend returns a clean 503 to normal `/cms-api` traffic while the stack is
 * being replaced. It writes the same `var/maintenance_mode.lock` file the admin
 * API toggles, so the state survives restarts without editing `.env`.
 *
 * The env hard switch (`SELFHELP_MAINTENANCE_MODE`) still wins: `--disable`
 * cannot clear an env-forced maintenance state (the command reports that).
 *
 * `--format=json` prints exactly one JSON line of {@see MaintenanceModeService::getState()}
 * after the action (or status probe), for the manager's machine-readable probe.
 * Text format (default) keeps the human sentences unchanged.
 */
#[AsCommand(
    name: 'selfhelp:maintenance',
    description: 'Enable/disable maintenance mode for the current instance (clean 503 for normal API traffic).',
)]
final class MaintenanceModeCommand extends Command
{
    public function __construct(
        private readonly MaintenanceModeService $maintenanceMode,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('enable', null, InputOption::VALUE_NONE, 'Enable maintenance mode (writes var/maintenance_mode.lock).');
        $this->addOption('disable', null, InputOption::VALUE_NONE, 'Disable maintenance mode (removes the lock file).');
        $this->addOption('message', null, InputOption::VALUE_REQUIRED, 'Operator-facing maintenance message.', 'Scheduled maintenance in progress.');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who toggled maintenance (audit field).', 'sh-manager');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text (default) or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? $formatOption : 'text';
        if ($format !== 'text' && $format !== 'json') {
            fwrite(\STDERR, "Unknown format \"{$format}\". Use text or json.\n");

            return Command::INVALID;
        }
        $json = $format === 'json';

        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');

        if ($enable && $disable) {
            if ($json) {
                fwrite(\STDERR, "Pass only one of --enable / --disable.\n");
            } else {
                (new SymfonyStyle($input, $output))->error('Pass only one of --enable / --disable.');
            }

            return Command::FAILURE;
        }

        if (!$enable && !$disable) {
            $state = $this->maintenanceMode->getState();
            if ($json) {
                $output->writeln(json_encode($state, \JSON_THROW_ON_ERROR));

                return Command::SUCCESS;
            }
            (new SymfonyStyle($input, $output))->info(sprintf(
                'Maintenance mode is currently %s%s.',
                $state['enabled'] ? 'ENABLED' : 'disabled',
                $state['forced_by_env'] ? ' (forced by SELFHELP_MAINTENANCE_MODE)' : '',
            ));

            return Command::SUCCESS;
        }

        $messageOption = $input->getOption('message');
        $message = is_string($messageOption) ? $messageOption : 'Scheduled maintenance in progress.';
        $actorOption = $input->getOption('actor');
        $actor = is_string($actorOption) ? $actorOption : 'sh-manager';

        if ($enable) {
            $state = $this->maintenanceMode->enable($message, $actor);
            if ($json) {
                $output->writeln(json_encode($state, \JSON_THROW_ON_ERROR));

                return Command::SUCCESS;
            }
            (new SymfonyStyle($input, $output))->success('Maintenance mode enabled. Normal /cms-api traffic now receives a 503.');

            return Command::SUCCESS;
        }

        $state = $this->maintenanceMode->disable();
        if ($json) {
            $output->writeln(json_encode($state, \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        $io = new SymfonyStyle($input, $output);
        if ($state['forced_by_env']) {
            $io->warning('Maintenance lock removed, but SELFHELP_MAINTENANCE_MODE still forces maintenance on. Clear it in the instance .env.');

            return Command::SUCCESS;
        }
        $io->success('Maintenance mode disabled. The instance serves normal traffic again.');

        return Command::SUCCESS;
    }
}
