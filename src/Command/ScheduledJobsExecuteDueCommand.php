<?php

namespace App\Command;

use App\Repository\ScheduledJobRepository;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that executes all queued scheduled jobs that are currently due.
 */
#[AsCommand(
    name: 'app:scheduled-jobs:execute-due',
    description: 'Execute all queued scheduled jobs that are due'
)]
class ScheduledJobsExecuteDueCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobRepository $scheduledJobRepository,
        private readonly JobSchedulerService $jobSchedulerService
    ) {
        parent::__construct();
    }

    /**
     * Configure CLI options for the bulk scheduled-job executor.
     */
    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of jobs to execute', null);
    }

    /**
     * Execute queued scheduled jobs up to the optional limit.
     *
     * @param InputInterface $input
     *   The console input carrying CLI arguments/options.
     * @param OutputInterface $output
     *   The console output used for human-readable progress.
     *
     * @return int
     *   Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit');
        $jobs = $this->scheduledJobRepository->findJobsToExecute();

        if ($limit !== null) {
            $jobs = array_slice($jobs, 0, max(0, (int) $limit));
        }

        $io->title('Execute Due Scheduled Jobs');
        $io->text(sprintf('Found %d queued job(s) ready for execution.', count($jobs)));

        $executed = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $result = $this->jobSchedulerService->executeJob($job->getId(), LookupService::TRANSACTION_BY_BY_CRON_JOB);
            if ($result) {
                $executed++;
                continue;
            }

            $failed++;
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Jobs Found', count($jobs)],
                ['Jobs Executed', $executed],
                ['Jobs Failed', $failed],
            ]
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
