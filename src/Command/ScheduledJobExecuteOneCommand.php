<?php

namespace App\Command;

use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that executes one scheduled job by id.
 */
#[AsCommand(
    name: 'app:scheduled-jobs:execute-one',
    description: 'Execute one scheduled job by ID'
)]
class ScheduledJobExecuteOneCommand extends Command
{
    public function __construct(
        private readonly JobSchedulerService $jobSchedulerService
    ) {
        parent::__construct();
    }

    /**
     * Configure CLI arguments for single scheduled-job execution.
     */
    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED, 'Scheduled job ID');
    }

    /**
     * Execute one scheduled job and report the result in the console.
     *
     * @param InputInterface $input
     *   The console input carrying the job id argument.
     * @param OutputInterface $output
     *   The console output used for human-readable status messages.
     *
     * @return int
     *   Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobId = (int) $input->getArgument('jobId');

        $result = $this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);
        if (!$result) {
            $io->error(sprintf('Scheduled job %d failed to execute.', $jobId));
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Scheduled job %d executed with status %s.',
            $jobId,
            $result->getStatus()->getLookupCode()
        ));

        return Command::SUCCESS;
    }
}
