<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\ScheduledJob;

use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Manifest\PluginManifest;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\ScheduledJobProcessPool;
use App\Service\Core\ScheduledJobTimeouts;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * C22: parallel jobs with a timeout.
 *
 * `app:scheduled-jobs:execute-due --concurrency=<n>` starts every due job in a child process
 * (`app:scheduled-jobs:execute-one <id>`), up to `n` at once. A child that is still running after the
 * timeout of its job type (`scheduledJobs[].timeoutSeconds` of the manifest, 10 to 900, 120 when not
 * declared) is stopped and its job is marked failed with the reason `timeout`, while the other jobs go
 * on. The claim of a job is one guarded UPDATE, so no job runs twice, however many runners there are.
 *
 * The runs happen in other processes, which cannot see the uncommitted rows of the test transaction.
 * Where a test starts processes it therefore writes its fixture (the QA platform plugin, its job types
 * and the jobs) through a second connection, commits it, and removes it (and what the runs left in the
 * run history and the transaction log) again in `tearDown()`. Those tests touch nothing through the
 * test transaction, so the cleanup never waits for a lock of the test itself.
 *
 * The QA handlers ({@see \App\Tests\Support\Plugin\QaPlatformJobHandler}) sleep for `sleep_seconds` and
 * write the time they started and ended to a marker file, which is what the tests measure: how long the
 * jobs overlapped, which jobs began at all, and how often.
 */
final class ScheduledJobConcurrencyTest extends QaKernelTestCase
{
    private const SLEEP = QaPlatformPluginFixture::SLEEP_JOB;
    private const SLOW = QaPlatformPluginFixture::TIMEOUT_JOB;

    private ?Connection $committer = null;
    private bool $committed = false;
    private string $marker = '';
    private int $runsBefore = 0;
    private int $transactionsBefore = 0;
    private bool $hadRunnerSettings = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa-c22-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if ($this->committed) {
            $this->removeCommittedRows();
        }
        if ($this->committer !== null) {
            $this->committer->close();
        }
        if (is_file($this->marker)) {
            unlink($this->marker);
        }
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- the jobs run at the same time ------------------------------------------------------------

    public function testThreeSleepingJobsRunAtOnceWithConcurrencyThree(): void
    {
        $this->commitFixture();
        $ids = [$this->job(self::SLEEP, 3), $this->job(self::SLEEP, 3), $this->job(self::SLEEP, 3)];

        $wallStart = microtime(true);
        $run = $this->console(['app:scheduled-jobs:execute-due', '--concurrency=3', '--force', '--json']);
        $wall = microtime(true) - $wallStart;

        self::assertSame(0, $run->getExitCode(), $this->describe($run));
        $summary = $this->summary($run);
        self::assertSame(3, $summary['attempted_count'], $this->describe($run));
        self::assertSame(3, $summary['done_count'], $this->describe($run));
        self::assertSame(0, $summary['failed_count']);

        foreach ($ids as $id) {
            self::assertSame('done', $this->statusOf($id));
        }

        // What the handlers saw: all three began before any of them ended, and the whole run of the
        // three jobs took about as long as one of them (a sequential run would take nine seconds).
        $marks = $this->marks();
        self::assertCount(3, $marks, 'Each job ran once.');
        [$firstStart, $lastStart, $firstEnd, $lastEnd] = $this->bounds($marks);
        self::assertLessThan($firstEnd, $lastStart, 'The three jobs ran at the same time.');
        $span = $lastEnd - $firstStart;
        self::assertGreaterThanOrEqual(3.0, $span);
        self::assertLessThan(6.0, $span, 'Three 3-second jobs finish within 6 seconds with concurrency 3.');
        self::assertLessThan(45.0, $wall, 'The whole runner, process start-up included, is far from a sequential run of slow jobs.');
        fwrite(STDERR, sprintf("\n[C22] three 3 s jobs, concurrency 3: handlers overlapped for a span of %.2f s; the runner took %.2f s from start to exit.\n", $span, $wall));
    }

    public function testConcurrencyOneRunsTheJobsOneAfterTheOther(): void
    {
        $this->commitFixture();
        $ids = [$this->job(self::SLEEP, 1), $this->job(self::SLEEP, 1)];

        $run = $this->console(['app:scheduled-jobs:execute-due', '--concurrency=1', '--force', '--json']);

        self::assertSame(0, $run->getExitCode(), $this->describe($run));
        self::assertSame(2, $this->summary($run)['done_count']);
        $marks = array_values($this->marks());
        self::assertCount(2, $marks);
        usort($marks, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        self::assertGreaterThanOrEqual($marks[0]['end'], $marks[1]['start'], 'With one child at a time the second job begins after the first ended.');
        foreach ($ids as $id) {
            self::assertSame('done', $this->statusOf($id));
        }
    }

    // -- the timeout ------------------------------------------------------------------------------

    public function testAJobOverItsTimeoutIsStoppedAndFailedWhileTheOtherJobsStillRun(): void
    {
        $this->commitFixture();
        // `qa_timeout_job` declares `timeoutSeconds` 10; it would sleep for 20.
        $slow = $this->job(self::SLOW, 20);
        $quick = $this->job(self::SLEEP, 1);

        $wallStart = microtime(true);
        $run = $this->console(['app:scheduled-jobs:execute-due', '--concurrency=2', '--force', '--json']);
        $wall = microtime(true) - $wallStart;

        self::assertSame(0, $run->getExitCode(), $this->describe($run));
        $summary = $this->summary($run);
        self::assertSame(2, $summary['attempted_count'], $this->describe($run));
        self::assertSame(1, $summary['done_count']);
        self::assertSame(1, $summary['failed_count']);

        self::assertSame('failed', $this->statusOf($slow), 'The slow job is failed.');
        self::assertContains(
            'Job executed: failed (timeout)',
            $this->transactionLogs($slow),
            'The reason is recorded as timeout in the transaction log.',
        );
        self::assertSame('done', $this->statusOf($quick), 'The other job was not held up by the slow one.');

        $marks = $this->marks();
        self::assertArrayHasKey($slow, $marks);
        self::assertNull($marks[$slow]['end'] ?? null, 'The process of the slow job was stopped before the handler ended.');
        self::assertNotNull($marks[$quick]['end'] ?? null);
        self::assertLessThan($marks[$slow]['start'] + 10.0, $marks[$quick]['end'], 'The quick job ended while the slow one was still running.');
        self::assertGreaterThanOrEqual(10.0, $wall, 'The runner waited for the timeout of the type.');
        self::assertLessThan(19.0, $wall, 'The runner did not wait for the 20 seconds of the slow job.');
    }

    // -- no job runs twice ------------------------------------------------------------------------

    public function testTwoProcessesForTheSameJobRunItOnce(): void
    {
        $this->commitFixture();
        $id = $this->job(self::SLEEP, 2);

        $first = $this->startConsole(['app:scheduled-jobs:execute-one', (string) $id, '--by-cron-job']);
        $second = $this->startConsole(['app:scheduled-jobs:execute-one', (string) $id, '--by-cron-job']);
        $first->wait();
        $second->wait();

        $marks = $this->marks();
        self::assertCount(1, $marks, 'The handler ran once.');
        self::assertNotNull($marks[$id]['end'] ?? null);
        self::assertSame('done', $this->statusOf($id));
        self::assertSame(
            [0, 1],
            $this->sorted([$first->getExitCode(), $second->getExitCode()]),
            'One runner won the claim; the other lost it and did nothing: ' . $this->describe($first) . ' / ' . $this->describe($second),
        );
        $startLines = array_filter($this->markerLines(), static fn (string $line): bool => str_starts_with($line, 'start '));
        self::assertCount(1, $startLines);
    }

    public function testTwoRunnersAtTheSameTimeNeverRunAJobTwice(): void
    {
        $this->commitFixture();
        $ids = [$this->job(self::SLEEP, 1), $this->job(self::SLEEP, 1), $this->job(self::SLEEP, 1)];

        $first = $this->startConsole(['app:scheduled-jobs:execute-due', '--concurrency=2', '--force', '--json']);
        $second = $this->startConsole(['app:scheduled-jobs:execute-due', '--concurrency=2', '--force', '--json']);
        $first->wait();
        $second->wait();

        self::assertSame(0, $first->getExitCode(), $this->describe($first));
        self::assertSame(0, $second->getExitCode(), $this->describe($second));
        $lines = array_filter($this->markerLines(), static fn (string $line): bool => str_starts_with($line, 'start '));
        $started = array_map(static fn (string $line): int => (int) explode(' ', $line)[1], $lines);
        self::assertSame($this->sorted($ids), $this->sorted(array_values($started)), 'Every job started exactly once, whichever runner took it.');
        foreach ($ids as $id) {
            self::assertSame('done', $this->statusOf($id));
        }
    }

    // -- the option and the entrypoints ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConcurrencies(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['many'];
        yield 'decimal' => ['2.5'];
        yield 'empty' => [''];
        yield 'over the maximum' => [(string) (ScheduledJobProcessPool::MAX_CONCURRENCY + 1)];
    }

    #[DataProvider('invalidConcurrencies')]
    public function testAnInvalidConcurrencyIsRefusedBeforeAnyJobIsTouched(string $value): void
    {
        $tester = new CommandTester((new Application(self::bootedKernel()))->find('app:scheduled-jobs:execute-due'));

        $exit = $tester->execute(['--concurrency' => $value, '--dry-run' => true, '--json' => true]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('--concurrency', $tester->getDisplay());
    }

    public function testBothEntrypointsPassTheConcurrencySetting(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docker/scheduler/entrypoint.sh', 'docker/scheduler/prod-entrypoint.sh'] as $path) {
            $script = (string) file_get_contents($root . '/' . $path);
            self::assertNotSame('', $script, $path . ' exists.');
            self::assertStringContainsString('concurrency="${SCHEDULED_JOBS_CONCURRENCY:-4}"', $script, $path . ' reads the setting, default 4.');
            self::assertMatchesRegularExpression(
                '/app:scheduled-jobs:execute-due[^\n]*--concurrency="\$\{concurrency\}"/',
                $script,
                $path . ' passes the setting to execute-due.',
            );
        }
    }

    // -- the timeout of a job type ---------------------------------------------------------------------

    public function testTheTimeoutOfATypeComesFromTheManifestOfAnEnabledPlugin(): void
    {
        $timeouts = $this->service(ScheduledJobTimeouts::class);
        self::assertSame(PluginManifest::DEFAULT_JOB_TIMEOUT_SECONDS, $timeouts->secondsFor(self::SLOW), 'No plugin yet: the default.');

        QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );

        self::assertSame(QaPlatformPluginFixture::TIMEOUT_JOB_SECONDS, $timeouts->secondsFor(self::SLOW), 'The type declares `timeoutSeconds`.');
        self::assertSame(120, $timeouts->secondsFor(self::SLEEP), 'A plugin type without `timeoutSeconds` gets 120 seconds.');
        self::assertSame(120, $timeouts->secondsFor('email'), 'A core job type gets 120 seconds.');
        self::assertSame(120, $timeouts->secondsFor('no_such_type'));
    }

    public function testTheTimeoutOfATypeOfADisabledPluginIsTheDefault(): void
    {
        QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            enabled: false,
        );

        self::assertSame(120, $this->service(ScheduledJobTimeouts::class)->secondsFor(self::SLOW));
    }

    // -- helpers ----------------------------------------------------------------------------------------

    /**
     * Commit the QA platform plugin, its job types and the lookup ids the jobs need, through a second
     * connection, and remember what to remove again.
     */
    private function commitFixture(): void
    {
        $db = $this->committer();
        $this->removeFixtureRows();

        $this->runsBefore = self::coerceInt($db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM scheduled_job_runner_runs'));
        $this->transactionsBefore = self::coerceInt($db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM transactions'));
        $this->hadRunnerSettings = self::coerceInt($db->fetchOne('SELECT COUNT(*) FROM scheduled_job_runner_settings')) > 0;

        $now = gmdate('Y-m-d H:i:s');
        $db->insert('plugins', [
            'plugin_id' => QaPlatformPluginFixture::PLUGIN_ID,
            'name' => 'QA platform plugin',
            'version' => '1.0.0',
            'plugin_api_version' => '0.2.0',
            'enabled' => 1,
            'manifest_json' => json_encode(QaPlatformPluginFixture::manifestData(), JSON_THROW_ON_ERROR),
            'capabilities_json' => '[]',
            'installed_at' => $now,
            'updated_at' => $now,
        ]);
        $pluginRowId = (int) $db->lastInsertId();
        foreach ([self::SLEEP, self::SLOW] as $type) {
            $db->insert('lookups', [
                'type_code' => 'jobTypes',
                'lookup_code' => $type,
                'lookup_value' => $type,
                'id_plugins' => $pluginRowId,
            ]);
        }
        $this->committed = true;

        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
    }

    /**
     * A queued job of a QA type that is due, which sleeps for `$sleep` seconds and writes to the marker file.
     */
    private function job(string $type, int $sleep): int
    {
        $db = $this->committer();
        $typeId = $db->fetchOne("SELECT id FROM lookups WHERE type_code = 'jobTypes' AND lookup_code = :code", ['code' => $type]);
        $statusId = $db->fetchOne("SELECT id FROM lookups WHERE type_code = 'scheduledJobsStatus' AND lookup_code = 'queued'");
        self::assertNotFalse($typeId);
        self::assertNotFalse($statusId);

        $db->insert('scheduled_jobs', [
            'id_job_types' => $typeId,
            'id_job_status' => $statusId,
            'date_create' => gmdate('Y-m-d H:i:s'),
            'date_to_be_executed' => gmdate('Y-m-d H:i:s', time() - 60),
            'description' => 'qa c22 ' . $type,
            'config' => json_encode(['sleep_seconds' => $sleep, 'marker_file' => $this->marker], JSON_THROW_ON_ERROR),
        ]);

        return (int) $db->lastInsertId();
    }

    private function statusOf(int $jobId): string
    {
        $code = $this->committer()->fetchOne(
            'SELECT st.lookup_code FROM scheduled_jobs sj INNER JOIN lookups st ON st.id = sj.id_job_status WHERE sj.id = :id',
            ['id' => $jobId],
        );

        return self::coerceString($code);
    }

    /**
     * The transaction-log texts (`verbal_log`) written for the job.
     *
     * @return list<string>
     */
    private function transactionLogs(int $jobId): array
    {
        $rows = $this->committer()->fetchFirstColumn(
            "SELECT transaction_log FROM transactions WHERE table_name = 'scheduled_jobs' AND id_table_name = :id ORDER BY id",
            ['id' => $jobId],
        );

        $texts = [];
        foreach ($rows as $row) {
            $log = is_string($row) ? json_decode($row, true) : null;
            $text = is_array($log) ? ($log['verbal_log'] ?? null) : null;
            if (is_string($text)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * What the handlers wrote: per job id the time the handler began and, when it got that far, ended.
     *
     * @return array<int, array{start: float, end: float|null}>
     */
    private function marks(): array
    {
        $marks = [];
        foreach ($this->markerLines() as $line) {
            [$what, $id, $time] = explode(' ', $line) + [null, null, null];
            if (!is_string($what) || !is_numeric($id) || !is_numeric($time)) {
                continue;
            }
            $jobId = (int) $id;
            if ($what === 'start') {
                $marks[$jobId] = ['start' => (float) $time, 'end' => $marks[$jobId]['end'] ?? null];
            } elseif ($what === 'end') {
                $marks[$jobId] = ['start' => $marks[$jobId]['start'] ?? (float) $time, 'end' => (float) $time];
            }
        }

        return $marks;
    }

    /**
     * The lines the handlers wrote to the marker file (none while the file does not exist).
     *
     * @return list<string>
     */
    private function markerLines(): array
    {
        if (!is_file($this->marker)) {
            return [];
        }
        $lines = file($this->marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }

    /**
     * When the first and the last handler began and when the first and the last one ended. Every
     * handler in `$marks` must have ended.
     *
     * @param array<int, array{start: float, end: float|null}> $marks
     *
     * @return array{float, float, float, float} first start, last start, first end, last end
     */
    private function bounds(array $marks): array
    {
        $starts = [];
        $ends = [];
        foreach ($marks as $mark) {
            self::assertNotNull($mark['end'], 'Every handler ran to its end.');
            $starts[] = $mark['start'];
            $ends[] = $mark['end'];
        }
        self::assertNotEmpty($starts, 'At least one handler ran.');
        sort($starts);
        sort($ends);

        return [$starts[0], $starts[count($starts) - 1], $ends[0], $ends[count($ends) - 1]];
    }

    /**
     * The JSON summary `execute-due --json` prints (the last line that is a JSON object).
     *
     * @return array<string, mixed>
     */
    private function summary(Process $process): array
    {
        $lines = array_reverse(preg_split('/\R/', trim($process->getOutput())) ?: []);
        foreach ($lines as $line) {
            if (str_starts_with($line, '{')) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return self::asArray($decoded);
                }
            }
        }

        self::fail('No JSON summary in the output: ' . $this->describe($process));
    }

    private function describe(Process $process): string
    {
        return trim($process->getOutput() . "\n" . $process->getErrorOutput());
    }

    /**
     * @param list<string> $arguments
     */
    private function startConsole(array $arguments): Process
    {
        $php = (new PhpExecutableFinder())->find(false);
        $root = dirname(__DIR__, 3);
        $process = new Process(
            array_merge([is_string($php) && $php !== '' ? $php : PHP_BINARY, $root . '/bin/console'], $arguments, ['--env=test', '--no-interaction']),
            $root,
        );
        $process->setTimeout(120);
        $process->start();

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    private function console(array $arguments): Process
    {
        $process = $this->startConsole($arguments);
        $process->wait();

        return $process;
    }

    /**
     * The second connection, to the database of the test run, outside the test transaction.
     */
    private function committer(): Connection
    {
        if ($this->committer === null) {
            $source = $this->em->getConnection()->getParams();
            $this->committer = DriverManager::getConnection([
                'driver' => 'pdo_mysql',
                'host' => self::asString($source['host'] ?? '127.0.0.1'),
                'port' => self::asInt($source['port'] ?? 3306),
                'user' => self::asString($source['user'] ?? ''),
                'password' => self::asString($source['password'] ?? ''),
                'dbname' => self::asString($source['dbname'] ?? ''),
                'charset' => 'utf8mb4',
            ]);
        }

        return $this->committer;
    }

    /** What {@see commitFixture()} wrote and what the runs left behind. */
    private function removeCommittedRows(): void
    {
        $db = $this->committer();
        $this->removeFixtureRows();
        $db->executeStatement('DELETE FROM scheduled_job_runner_runs WHERE id > :id', ['id' => $this->runsBefore]);
        $db->executeStatement('DELETE FROM transactions WHERE id > :id', ['id' => $this->transactionsBefore]);
        if (!$this->hadRunnerSettings) {
            $db->executeStatement('DELETE FROM scheduled_job_runner_settings');
        }
        $this->committed = false;
    }

    /** The plugin row, its job types and the jobs of those types (also what an earlier, crashed run left). */
    private function removeFixtureRows(): void
    {
        $db = $this->committer();
        $db->executeStatement(
            "DELETE sj FROM scheduled_jobs sj INNER JOIN lookups jt ON jt.id = sj.id_job_types AND jt.type_code = 'jobTypes' AND jt.lookup_code IN (:types)",
            ['types' => [self::SLEEP, self::SLOW]],
            ['types' => ArrayParameterType::STRING],
        );
        $db->executeStatement(
            "DELETE FROM lookups WHERE type_code = 'jobTypes' AND lookup_code IN (:types)",
            ['types' => [self::SLEEP, self::SLOW]],
            ['types' => ArrayParameterType::STRING],
        );
        $db->executeStatement('DELETE FROM plugins WHERE plugin_id = :id', ['id' => QaPlatformPluginFixture::PLUGIN_ID]);
    }

    /**
     * @param list<int|string|null> $values
     *
     * @return list<int|string|null>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
