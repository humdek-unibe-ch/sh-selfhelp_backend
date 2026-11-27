<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\Core\ScheduledJobWakeSignal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The pause of the scheduler loop (contract C13): wait for the wake signal, but
 * not longer than the timeout.
 *
 * Both scheduler entrypoints run `execute-due`, then this command with the tick
 * length, and start again. A job that is created and due at once pushes the
 * signal after its commit, so the wait ends early and the next `execute-due`
 * starts the job within about two seconds. Without a signal the wait lasts the
 * whole timeout: the tick of before.
 *
 * It prints `woken` or `timeout` and exits 0 in both cases (an unreachable Redis
 * sleeps for the timeout and prints `timeout`), so a loop can use the word to run
 * the next tick as a woken one (`execute-due --woken`).
 */
#[AsCommand(
    name: 'app:scheduled-jobs:wait',
    description: 'Wait for the scheduled-job wake signal, at most --timeout seconds'
)]
final class ScheduledJobsWaitCommand extends Command
{
    public const WOKEN = 'woken';
    public const TIMEOUT = 'timeout';

    public function __construct(private readonly ScheduledJobWakeSignal $wakeSignal)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Longest wait in seconds', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('timeout');
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new \InvalidArgumentException('The --timeout option must be a whole number of seconds (0 or more).');
        }

        $output->writeln($this->wakeSignal->wait((int) $raw) ? self::WOKEN : self::TIMEOUT);

        return Command::SUCCESS;
    }
}
