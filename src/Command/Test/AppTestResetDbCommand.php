<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Test;

use App\DataFixtures\Test\QaBaselineFixture;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drops, recreates, migrates and re-seeds the TEST database with the QA
 * baseline so every developer and CI run starts from an identical,
 * reproducible state:
 *
 *   php bin/console app:test:reset-db --force --env=test
 *   # (or) composer test:reset-db
 *
 * This is the single source of seeded state. DAMA then wraps each test in
 * a transaction rolled back on tearDown, so this command only needs to run
 * when the schema or the fixtures change (or on a fresh checkout).
 *
 * SAFETY (plan §32 / AGENTS.md Testing Rule 31): the command is destructive
 * so it refuses to run unless ALL of these hold:
 *   1. APP_ENV === 'test'
 *   2. the resolved database name contains "_test"
 *   3. the database host is in the allow-list
 *   4. --force was passed
 *   5. DATABASE_URL does not look like prod/production/live
 * and it prints the target database name BEFORE destroying anything.
 */
#[AsCommand(
    name: 'app:test:reset-db',
    description: 'Drop, recreate, migrate and re-seed the TEST database with the QA baseline (guarded; test env only).'
)]
final class AppTestResetDbCommand extends Command
{
    private const ALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1', 'mysql', 'db', 'database'];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Required acknowledgement that this DROPS the test database.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- Guard 1: env ---------------------------------------------------
        if ($this->appEnv !== 'test') {
            $io->error(sprintf(
                'Refusing to reset: APP_ENV is "%s", not "test". Run with --env=test.',
                $this->appEnv
            ));
            return Command::FAILURE;
        }

        $params = $this->connection->getParams();
        $dbName = isset($params['dbname']) && is_string($params['dbname']) ? $params['dbname'] : '';
        $dbHost = isset($params['host']) && is_string($params['host']) ? $params['host'] : '';
        $rawDatabaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
        $databaseUrl = is_string($rawDatabaseUrl) ? $rawDatabaseUrl : '';

        // --- Guard 2: db name must contain _test ----------------------------
        if ($dbName === '' || !str_contains($dbName, '_test')) {
            $io->error(sprintf(
                'Refusing to reset: database name "%s" does not contain "_test". '
                . 'The when@test Doctrine config appends "_test"; check your DATABASE_URL.',
                $dbName
            ));
            return Command::FAILURE;
        }

        // --- Guard 3: host allow-list ---------------------------------------
        $allowedHosts = self::ALLOWED_HOSTS;
        $rawExtra = $_SERVER['TEST_DB_ALLOWED_HOSTS'] ?? $_ENV['TEST_DB_ALLOWED_HOSTS'] ?? '';
        $extra = is_string($rawExtra) ? $rawExtra : '';
        if ($extra !== '') {
            $allowedHosts = array_merge($allowedHosts, array_map('trim', explode(',', $extra)));
        }
        if ($dbHost !== '' && !in_array($dbHost, $allowedHosts, true)) {
            $io->error(sprintf(
                'Refusing to reset: database host "%s" is not in the test allow-list (%s). '
                . 'Set TEST_DB_ALLOWED_HOSTS to extend it.',
                $dbHost,
                implode(', ', $allowedHosts)
            ));
            return Command::FAILURE;
        }

        // --- Guard 4: prod-looking URL --------------------------------------
        foreach (['prod', 'production', 'live'] as $needle) {
            if ($databaseUrl !== '' && stripos($databaseUrl, $needle) !== false) {
                $io->error(sprintf(
                    'Refusing to reset: DATABASE_URL contains "%s" which looks like a real environment.',
                    $needle
                ));
                return Command::FAILURE;
            }
        }

        // --- Guard 5: explicit --force --------------------------------------
        if (!$input->getOption('force')) {
            $io->error('Refusing to reset without --force. This DROPS and recreates the test database.');
            return Command::FAILURE;
        }

        // Print the target BEFORE destroying anything (plan §32 #5).
        $io->section('Resetting TEST database');
        $io->writeln(sprintf('  database : <info>%s</info>', $dbName));
        $io->writeln(sprintf('  host     : <info>%s</info>', $dbHost !== '' ? $dbHost : '(socket/unknown)'));
        $io->writeln(sprintf('  fixtures : <info>QaBaselineFixture v%s</info>', QaBaselineFixture::QA_FIXTURE_VERSION));
        $io->newLine();

        // Each step runs as a SEPARATE `bin/console` process. Running
        // drop/create/migrate/fixtures in one process corrupts the active
        // connection (the database handle is dropped underneath it,
        // producing "SAVEPOINT ... does not exist" during the fixtures
        // commit). Separate processes each get a fresh connection — which
        // is also exactly how CI runs them as discrete steps.
        $steps = [
            ['doctrine:database:drop', '--force', '--if-exists'],
            ['doctrine:database:create'],
            ['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
            ['doctrine:fixtures:load', '--no-interaction', '--append', '--group=qa'],
        ];

        foreach ($steps as $step) {
            $io->writeln(sprintf('==> <comment>%s</comment>', $step[0]));
            $exitCode = $this->runConsole($step);
            if ($exitCode !== Command::SUCCESS) {
                $io->error(sprintf('Step "%s" failed with exit code %d.', $step[0], $exitCode));
                return Command::FAILURE;
            }
        }

        $io->success(sprintf(
            'Test database "%s" reset and seeded. QA_FIXTURE_VERSION=%s',
            $dbName,
            QaBaselineFixture::QA_FIXTURE_VERSION
        ));

        return Command::SUCCESS;
    }

    /**
     * Run `bin/console <args> --env=test` as a fresh isolated process,
     * inheriting this process's environment (so DATABASE_URL etc. carry
     * over) and stdio (so output streams live).
     *
     * @param list<string> $args command name followed by its arguments
     */
    private function runConsole(array $args): int
    {
        $command = array_merge(
            [PHP_BINARY, $this->projectDir . '/bin/console'],
            $args,
            ['--env=test']
        );

        $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $process = proc_open($command, $descriptors, $pipes, $this->projectDir);

        if (!is_resource($process)) {
            return Command::FAILURE;
        }

        return proc_close($process);
    }
}
