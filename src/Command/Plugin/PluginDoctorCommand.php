<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Health\PluginHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:doctor',
    description: 'Run plugin health + drift checks; emit a JSON or pretty report.',
)]
final class PluginDoctorCommand extends Command
{
    public function __construct(
        private readonly PluginHealthService $healthService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the full report as JSON (for CI / monitoring).');
        $this->addOption('ci', null, InputOption::VALUE_NONE, 'Exit with non-zero status when any check is not "ok".');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->healthService->runGlobalDoctor();

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $this->resolveExit($input, $report);
        }

        $io->title('SelfHelp plugin doctor');
        $io->section('Site checks');
        $rows = [];
        foreach ($report['siteChecks'] as $check) {
            $rows[] = [$check['name'], $check['status'], $check['message']];
        }
        $io->table(['Check', 'Status', 'Message'], $rows);

        $io->section('Plugins');
        if ($report['plugins'] === []) {
            $io->info('No plugins installed.');
        } else {
            foreach ($report['plugins'] as $plugin) {
                $compat = $plugin['compatibility'] ?? null;
                $io->writeln(sprintf(
                    '<info>%s</info> v%s — enabled: %s — compatibility: %s',
                    $plugin['pluginId'],
                    $plugin['version'],
                    $plugin['enabled'] ? 'yes' : 'no',
                    $compat['severity'] ?? 'unknown',
                ));
            }
        }

        return $this->resolveExit($input, $report);
    }

    /**
     * @param array<string,mixed> $report
     */
    private function resolveExit(InputInterface $input, array $report): int
    {
        if (!$input->getOption('ci')) {
            return Command::SUCCESS;
        }
        foreach ($report['siteChecks'] as $check) {
            if (($check['status'] ?? 'ok') !== 'ok') {
                return Command::FAILURE;
            }
        }
        foreach ($report['plugins'] as $plugin) {
            $severity = $plugin['compatibility']['severity'] ?? 'ok';
            if ($severity !== 'ok') {
                return Command::FAILURE;
            }
        }
        return Command::SUCCESS;
    }
}
