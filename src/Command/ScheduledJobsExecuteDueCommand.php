<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Command;

use App\Entity\ScheduledJobRunnerRun;
use App\Service\Core\ScheduledJobProcessPool;
use App\Service\Core\ScheduledJobRunnerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Docker-safe console command that runs all due scheduled jobs through the
 * {@see ScheduledJobRunnerService}. Safe to invoke every minute forever.
 */
#[AsCommand(
    name: 'app:scheduled-jobs:execute-due',
    description: 'Execute all queued scheduled jobs that are due (Docker scheduler entrypoint)'
)]
class ScheduledJobsExecuteDueCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobRunnerService $runnerService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of jobs to execute this run', null);
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass the enabled flag and interval gate');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report due counts and policy state without executing jobs');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON summary');
        $this->addOption(
            'concurrency',
            'c',
            InputOption::VALUE_REQUIRED,
            sprintf('Run each job in its own child process, up to this many at once (1 to %d), with the timeout of its job type; without the option the jobs run one after the other in this process', ScheduledJobProcessPool::MAX_CONCURRENCY),
            null
        );
        $this->addOption('woken', null, InputOption::VALUE_NONE, 'The tick follows a wake signal (app:scheduled-jobs:wait printed "woken"): skip the interval gate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $json = (bool) $input->getOption('json');
        $woken = (bool) $input->getOption('woken');

        $concurrencyOption = $input->getOption('concurrency');
        $concurrency = 0;
        if ($concurrencyOption !== null) {
            if (!is_string($concurrencyOption) || !ctype_digit($concurrencyOption)
                || (int) $concurrencyOption < 1 || (int) $concurrencyOption > ScheduledJobProcessPool::MAX_CONCURRENCY) {
                $message = sprintf('The --concurrency option must be a whole number from 1 to %d.', ScheduledJobProcessPool::MAX_CONCURRENCY);
                if ($json) {
                    $output->writeln((string) json_encode(['status' => 'error', 'error' => $message]));
                } else {
                    $io->error($message);
                }

                return Command::INVALID;
            }
            $concurrency = (int) $concurrencyOption;
        }

        try {
            $result = $this->runnerService->runDueJobs(
                ScheduledJobRunnerRun::TRIGGER_SCHEDULER,
                $limit,
                $force,
                $dryRun,
                $concurrency,
                $woken
            );
        } catch (\Throwable $e) {
            if ($json) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $e->getMessage()]));
            } else {
                $io->error('Scheduled-job runner failed: ' . $e->getMessage());
            }

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode($result->toArray()));
        } else {
            $io->title('Execute Due Scheduled Jobs');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Run status', $result->status],
                    ['Lock acquired', $result->lockAcquired ? 'yes' : 'no'],
                    ['Due', $result->dueCount],
                    ['Attempted', $result->attemptedCount],
                    ['Done', $result->doneCount],
                    ['Failed', $result->failedCount],
                    ['Skipped', $result->skippedCount],
                ]
            );
            if ($result->errorMessage !== null) {
                $io->warning($result->errorMessage);
            }
        }

        // Individual job failures/skips are not command failures; only an
        // infrastructure-level runner failure returns a non-zero exit code.
        return $result->isInfrastructureSuccess() ? Command::SUCCESS : Command::FAILURE;
    }
}
