<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base for Doctrine migration round-trip tests (Slice 4 / plan §15).
 *
 * Every migration must be reversible AND produce a schema that matches the
 * ORM mapping. This base proves that by driving the real Doctrine console
 * against a DEDICATED throwaway database, so it never touches the shared
 * `*_test` database that the rest of the suite + DAMA depend on:
 *
 *   drop -> create -> migrate latest -> schema:validate
 *        -> migrate prev -> migrate latest -> schema:validate   (round-trip)
 *
 * WHY a plain TestCase (not KernelTestCase): these tests run DDL, which
 * commits implicitly in MySQL and would break DAMA's transaction rollback.
 * By using subprocess console commands against a separate database and never
 * opening the Doctrine default connection, DAMA is never engaged.
 *
 * WHY a separate database: `migrate prev` reverts seed migrations (lookups /
 * styles / api_routes), so doing it on the shared test DB would corrupt the
 * QA baseline for every later test in the run. The throwaway DB name is the
 * configured db name + `_migrt`, and the `when@test` config appends `_test`,
 * so the effective database (e.g. `selfhelp_migrt_test`) is isolated and
 * still satisfies the "name must contain _test" safety convention.
 *
 * These tests are slow (a full migrate from scratch) and require CREATE
 * DATABASE privilege, so they are tagged `#[Group('migration')]` and run in
 * the release-tier `migration-test.yml` workflow, not the PR gate.
 */
abstract class MigrationRoundTripTestCase extends TestCase
{
    private string $projectDir;
    private string $throwawayDatabaseUrl;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 2);

        $rawUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $baseUrl = is_string($rawUrl) ? $rawUrl : '';
        if ($baseUrl === '') {
            self::markTestSkipped('DATABASE_URL is not set; migration round-trip needs a reachable test DB server.');
        }

        $this->throwawayDatabaseUrl = $this->deriveThrowawayUrl($baseUrl);

        // Fresh throwaway database for this test.
        $this->console(['doctrine:database:drop', '--force', '--if-exists']);
        $create = $this->console(['doctrine:database:create']);
        if ($create['exit'] !== 0) {
            self::markTestSkipped(
                "Could not create the throwaway migration database (need CREATE DATABASE privilege):\n" . $create['output']
            );
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->throwawayDatabaseUrl)) {
            $this->console(['doctrine:database:drop', '--force', '--if-exists']);
        }
    }

    /**
     * Full chain: migrate to latest, validate, revert the latest migration,
     * re-apply it, validate again. Proves the head migration is reversible and
     * the whole chain produces the ORM-mapped schema.
     */
    protected function assertChainRoundTrips(): void
    {
        $this->migrate('latest');
        $this->assertSchemaInSync('after first migrate to latest');

        // Round-trip the head migration: down one, then back up.
        $this->migrate('prev');
        $this->migrate('latest');
        $this->assertSchemaInSync('after prev -> latest round-trip');
    }

    /**
     * Per-migration round-trip: migrate up to and including $version, revert
     * just that migration (prev), re-apply it, then continue to latest and
     * validate the ORM is in sync.
     */
    protected function assertMigrationRoundTrips(string $version): void
    {
        $this->migrate($version);
        $this->migrate('prev'); // down $version
        $this->migrate($version); // up $version again
        $this->migrate('latest');
        $this->assertSchemaInSync(sprintf('after round-trip of migration %s', $version));
    }

    private function migrate(string $target): void
    {
        $result = $this->console(['doctrine:migrations:migrate', $target, '--no-interaction', '--allow-no-migration']);
        self::assertSame(
            0,
            $result['exit'],
            sprintf("doctrine:migrations:migrate %s failed:\n%s", $target, $result['output'])
        );
    }

    private function assertSchemaInSync(string $context): void
    {
        // `doctrine:schema:validate` checks BOTH the mapping and DB sync; its
        // exit code is non-zero if either fails and the output names which.
        $result = $this->console(['doctrine:schema:validate']);
        self::assertSame(
            0,
            $result['exit'],
            sprintf("Schema/mapping not in sync (%s):\n%s", $context, $result['output'])
        );
    }

    /**
     * Run `bin/console <args> --env=test` against the throwaway DB, capturing
     * output. DATABASE_URL is overridden in the child env (Symfony Dotenv does
     * not override already-set vars), and TEST_TOKEN is pinned empty so the
     * `_test` suffix is deterministic.
     *
     * @param list<string> $args
     * @return array{exit:int, output:string}
     */
    private function console(array $args): array
    {
        $command = array_merge([PHP_BINARY, $this->projectDir . '/bin/console'], $args, ['--env=test']);

        $env = $this->childEnv();
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $this->projectDir, $env);

        if (!is_resource($process)) {
            return ['exit' => 1, 'output' => 'Failed to start console subprocess.'];
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => trim($stdout . "\n" . $stderr)];
    }

    /**
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $env = getenv();
        $env['DATABASE_URL'] = $this->throwawayDatabaseUrl;
        $env['APP_ENV'] = 'test';
        $env['TEST_TOKEN'] = '';

        return $env;
    }

    /**
     * Append `_migrt` to the database name in a DATABASE_URL so the throwaway
     * DB is isolated from the shared test DB. The dbname is the path segment
     * after `@host[:port]/` and before any `?query`.
     */
    private function deriveThrowawayUrl(string $url): string
    {
        $derived = preg_replace('#(@[^/]+/)([^?]+)#', '${1}${2}_migrt', $url, 1);

        // Fallback: if the URL had no recognizable dbname segment, fail loudly
        // rather than silently reusing the shared test database.
        self::assertIsString($derived);
        self::assertNotSame($url, $derived, 'Could not derive an isolated throwaway database name from DATABASE_URL.');

        return $derived;
    }
}
