<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Page;
use App\Entity\Section;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataSearch;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\Performance\FormListPerfDataset as Data;
use App\Tests\Support\QaWebTestCase;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Performance proof of the form lists on the deterministic datasets of
 * {@see Data}: 100,000 records x 50 columns and a million-record CMS table.
 *
 * It measures what the speed targets name, request by request, through the real
 * HTTP stack in one process:
 *
 *   cold      application caches cleared, first request
 *   cached    the same request again
 *   after a write   a record is written through DataService, then the same request
 *
 * and records time, MySQL statements, peak PHP memory, response bytes, rows and
 * the cells the response carries. A second test records the query plans
 * (EXPLAIN ANALYZE) and the rows MySQL examined for the canonical queries, the
 * evidence that no pivot over the table is built and that the cells read follow
 * the page size.
 *
 * Opt in: it only runs with SH_PERF=1, in the isolated database that
 * `tests/Support/Performance/seed-form-list-dataset.php` filled. Results go to
 * `var/perf/form-list-performance.{json,md}`. What is asserted is what must hold on
 * any machine: status 200, the page size bound on the rows, and (in the plan test)
 * the number of rows returned. Times are measured and reported next to the
 * approved targets (`within_budget`), not asserted: they depend on the machine,
 * and some cell-driven scenarios on the million-record table are slower than the
 * target by design of the four-table store (documented in data-query-engine.md).
 */
#[Group('performance')]
final class FormListPerformanceTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    /** The approved speed targets (milliseconds, bytes). */
    private const COLD_BUDGET_MS = 1000;
    private const CACHED_BUDGET_MS = 150;
    private const AFTER_WRITE_BUDGET_MS = 1000;
    private const MILLION_COLD_BUDGET_MS = 3000;
    private const RESPONSE_BUDGET_BYTES = 200_000;

    /** @var list<array<string, mixed>> */
    private static array $results = [];

    /** @var array<string, array<string, mixed>> */
    private static array $plans = [];

    /** @var array<string, string> What the numbers were measured on. */
    private static array $environment = [];

    private int $qaTable = 0;
    private int $millionTable = 0;
    private int $statementBaseline = 1;

    protected function setUp(): void
    {
        if (getenv('SH_PERF') !== '1') {
            self::markTestSkipped('Performance proof: set SH_PERF=1 in the isolated database seeded by tests/Support/Performance/seed-form-list-dataset.php.');
        }

        parent::setUp();
        $this->setUpFormLists();
        $this->client->disableReboot();

        $database = self::coerceString($this->db->fetchOne('SELECT DATABASE()'));
        self::assertStringEndsWith(Data::DATABASE_SUFFIX, $database, 'The performance proof only runs in its isolated database.');

        // The cache store of this database may still hold entries of an earlier incarnation of it
        // (the database is rebuilt for the proof): start from empty caches, lookups included.
        $cache = $this->service(CacheService::class);
        foreach (CacheService::ALL_CATEGORIES as $category) {
            $cache->withCategory($category)->invalidateCategory();
        }

        $dataset = new Data($this->db);
        $qa = $dataset->tableId(Data::QA_TABLE);
        $million = $dataset->tableId(Data::MILLION_TABLE);
        if ($qa === null || $million === null) {
            self::markTestSkipped('The performance datasets are not seeded; run tests/Support/Performance/seed-form-list-dataset.php.');
        }
        $this->qaTable = $qa;
        $this->millionTable = $million;

        if (self::$environment === []) {
            self::$environment = $this->environment();
        }

        // The read of a status counter costs one statement itself; find out how many.
        $first = $this->statements();
        $second = $this->statements();
        $this->statementBaseline = max(0, $second - $first);

        // One request that boots the routes, the container and the login, so that no scenario pays for them.
        $this->jsonRequest('GET', '/cms-api/v1/languages');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$results === [] && self::$plans === []) {
            return;
        }

        $directory = dirname(__DIR__, 2) . '/var/perf';
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents(
            $directory . '/form-list-performance.json',
            json_encode(['environment' => self::$environment, 'results' => self::$results, 'plans' => self::$plans], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents($directory . '/form-list-performance.md', self::markdown());
    }

    // -- the proof ------------------------------------------------------------------

    public function testRequestsOnTheMillionRecordTable(): void
    {
        [$page, $view] = $this->publicView('perf_m_view', $this->millionTable, Data::MILLION_TABLE, facets: false);
        $key = static fn (int $p): string => Data::key(Data::MILLION_TABLE, $p);

        $scenarios = [
            'million/default page 1' => '',
            'million/deep page 20000' => 'perf.page=20000',
            'million/sort amount desc (typed)' => 'perf.sort=' . $key(4) . ':desc',
            'million/sort title asc (text)' => 'perf.sort=' . $key(1) . ':asc',
            'million/search rare word (about 50 matches)' => 'perf.q=quartz',
            'million/search common word (100,000 matches)' => 'perf.q=sleep',
            'million/filters status + amount range + date range' => 'perf.f.status=open&perf.f.amount=100..200&perf.f.published=2024-06-01..2024-12-31',
        ];
        foreach ($scenarios as $label => $query) {
            $this->triple($label, $page, $view, $query, null, $this->millionTable, Data::MILLION_TABLE);
        }

        [$facetPage, $facetView] = $this->publicView('perf_m_facets', $this->millionTable, Data::MILLION_TABLE, facets: true);
        $this->triple('million/default page 1 with data-driven choices (2 facets)', $facetPage, $facetView, '', null, $this->millionTable, Data::MILLION_TABLE);

        self::assertNotSame([], self::$results);
    }

    public function testParticipantListsOnTheMillionRecordTable(): void
    {
        $heavy = $this->loginAs(Data::HEAVY_OWNER_EMAIL);
        $light = $this->loginAs(Data::LIGHT_OWNER_EMAIL);
        [$page, $list] = $this->participantList('perf_m_own', $this->millionTable, Data::MILLION_TABLE);

        $this->triple('million/own records, participant with 90,000 records', $page, $list, '', $heavy, $this->millionTable, Data::MILLION_TABLE);
        $this->triple('million/own records, participant with 200 records', $page, $list, '', $light, $this->millionTable, Data::MILLION_TABLE);
        $this->triple('million/own records, heavy participant, search common word', $page, $list, 'perf.q=sleep', $heavy, $this->millionTable, Data::MILLION_TABLE);
        $this->triple('million/own records, light participant, search common word', $page, $list, 'perf.q=sleep', $light, $this->millionTable, Data::MILLION_TABLE);
    }

    public function testRequestsOnTheHundredThousandRecordTableWith50Columns(): void
    {
        foreach ([8, 30] as $shown) {
            [$page, $view] = $this->publicView('perf_qa_' . $shown, $this->qaTable, Data::QA_TABLE, facets: false, columns: $shown);
            $this->triple(sprintf('qa/default page 1, %d of 50 columns shown', $shown), $page, $view, '', null, $this->qaTable, Data::QA_TABLE);
            $this->triple(sprintf('qa/search common word, %d of 50 columns shown', $shown), $page, $view, 'perf.q=alpha', null, $this->qaTable, Data::QA_TABLE);
            $this->triple(sprintf('qa/sort priority desc (typed), %d of 50 columns shown', $shown), $page, $view, 'perf.sort=' . Data::key(Data::QA_TABLE, 3) . ':desc', null, $this->qaTable, Data::QA_TABLE);
        }

        self::assertNotSame([], self::$results);
    }

    public function testQueryPlansAndRowsExamined(): void
    {
        $engine = $this->service(DataQueryEngine::class);
        $heavy = $this->userId(Data::HEAVY_OWNER_EMAIL);
        $light = $this->userId(Data::LIGHT_OWNER_EMAIL);
        $key = static fn (int $p): string => Data::key(Data::MILLION_TABLE, $p);
        $all = array_map($key, range(1, Data::MILLION_COLUMNS));
        $notBlocked = new DataFilterGroup(DataFilterGroup::MODE_AND, [new DataFilter($key(3), DataFilter::OP_NEQ, 'blocked')]);

        $queries = [
            'default page, newest first' => new DataQuery($this->millionTable, DataQuery::OWNER_ANY, null, new DataFilterGroup(), null, [new DataSort('entry_date', DataSort::DESC)], 25, 0, $all),
            'default page with a fixed filter' => new DataQuery($this->millionTable, DataQuery::OWNER_ANY, null, $notBlocked, null, [new DataSort('entry_date', DataSort::DESC)], 25, 0, $all),
            'typed sort (amount desc)' => new DataQuery($this->millionTable, DataQuery::OWNER_ANY, null, new DataFilterGroup(), null, [new DataSort($key(4), DataSort::DESC)], 25, 0, $all),
            'search rare word' => new DataQuery($this->millionTable, DataQuery::OWNER_ANY, null, new DataFilterGroup(), new DataSearch('quartz', [$key(1), $key(7), $key(8)]), [new DataSort('entry_date', DataSort::DESC)], 25, 0, $all),
            'search common word' => new DataQuery($this->millionTable, DataQuery::OWNER_ANY, null, new DataFilterGroup(), new DataSearch('sleep', [$key(1), $key(7), $key(8)]), [new DataSort('entry_date', DataSort::DESC)], 25, 0, $all),
            'own records, heavy participant' => new DataQuery($this->millionTable, DataQuery::OWNER_USER, $heavy, new DataFilterGroup(), null, [new DataSort('entry_date', DataSort::DESC)], 25, 0, $all),
            'own records, light participant' => new DataQuery($this->millionTable, DataQuery::OWNER_USER, $light, new DataFilterGroup(), null, [new DataSort('entry_date', DataSort::DESC)], 25, 0, $all),
        ];

        $cache = $this->service(CacheService::class);
        foreach ($queries as $label => $query) {
            $cache->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $this->millionTable);

            $mark = $this->lastEventId();
            $started = hrtime(true);
            $result = $engine->run($query);
            $runMs = (hrtime(true) - $started) / 1e6;
            $statements = $this->statementsSince($mark);

            $started = hrtime(true);
            $count = $engine->count($query);
            $countMs = (hrtime(true) - $started) / 1e6;

            $compiled = $engine->compile($query);
            $plan = $this->explainAnalyze($compiled['sql'], $compiled['params']);

            self::$plans[$label] = [
                'rows_returned' => count($result->rows),
                'cells_returned' => count($result->rows) * count($all),
                'run_ms' => round($runMs, 1),
                'count' => $count,
                'count_ms' => round($countMs, 1),
                'statements' => $statements,
                'plan' => $plan,
            ];
            self::assertLessThanOrEqual(25, count($result->rows), $label);
        }
    }

    // -- scenario machinery -----------------------------------------------------------

    /**
     * Cold, cached and after-a-write request of one scenario.
     */
    private function triple(string $label, Page $page, Section $list, string $query, ?string $token, int $tableId, string $tableName): void
    {
        $this->clearApplicationCaches($tableId);
        $cold = $this->call($page, $list, $query, $token);
        $cached = $this->call($page, $list, $query, $token);

        $this->writeRecord($tableName);
        $afterWrite = $this->call($page, $list, $query, $token);

        $isMillion = $tableName === Data::MILLION_TABLE;
        self::$results[] = [
            'scenario' => $label,
            'query' => $query,
            'rows' => $cold['rows'],
            'total' => $cold['total'],
            'cells_in_response' => $cold['cells'],
            'cold' => $cold,
            'cached' => $cached,
            'after_write' => $afterWrite,
            'within_budget' => [
                'cold' => $cold['ms'] <= ($isMillion ? self::MILLION_COLD_BUDGET_MS : self::COLD_BUDGET_MS),
                'cached' => $cached['ms'] <= self::CACHED_BUDGET_MS,
                'after_write' => $afterWrite['ms'] <= ($isMillion ? self::MILLION_COLD_BUDGET_MS : self::AFTER_WRITE_BUDGET_MS),
                'bytes' => $cold['bytes'] <= self::RESPONSE_BUDGET_BYTES,
            ],
        ];

        // The response is bounded by the page size whatever the table holds.
        self::assertLessThanOrEqual(25, $cold['rows'], $label);
        self::assertSame(200, $cold['status'], $label);
    }

    /**
     * @return array{ms: float, statements: int, peak_mb: float, bytes: int, status: int, rows: int, total: int, cells: int}
     */
    private function call(Page $page, Section $list, string $query, ?string $token): array
    {
        memory_reset_peak_usage();
        $before = $this->statements();
        $started = hrtime(true);

        $envelope = $this->state($page, $list, $query, $token);

        $ms = (hrtime(true) - $started) / 1e6;
        $after = $this->statements();
        $peak = memory_get_peak_usage();
        $response = $this->client->getResponse();

        $data = is_array($envelope['data'] ?? null) ? $this->at($envelope, 'data') : [];
        $rows = $data === [] ? 0 : count($this->listOf($data, 'rows'));
        $columns = $data === [] ? 0 : count($this->listOf($data, 'columns'));
        $pagination = $data === [] ? [] : $this->at($data, 'pagination');

        return [
            'ms' => round($ms, 1),
            'statements' => max(0, $after - $before - $this->statementBaseline),
            'peak_mb' => round($peak / 1048576, 1),
            'bytes' => strlen((string) $response->getContent()),
            'status' => $response->getStatusCode(),
            'rows' => $rows,
            'total' => self::coerceInt($pagination['total'] ?? 0),
            'cells' => $rows * $columns,
        ];
    }

    /**
     * What the numbers are measured on: they depend on the memory MySQL has for its data pages.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = [
            'php' => PHP_VERSION,
            'mysql' => self::coerceString($this->db->fetchOne('SELECT VERSION()')),
            'innodb_buffer_pool_mb' => (string) round(self::coerceInt($this->db->fetchOne('SELECT @@innodb_buffer_pool_size')) / 1048576),
            'os' => PHP_OS_FAMILY,
        ];
        $tables = $this->db->fetchAllAssociative(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS table_rows, ROUND(DATA_LENGTH / 1048576) AS data_mb, ROUND(INDEX_LENGTH / 1048576) AS index_mb'
            . " FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('data_rows', 'data_cells') ORDER BY TABLE_NAME"
        );
        foreach ($tables as $table) {
            $environment[self::coerceString($table['name'] ?? '')] = sprintf(
                'about %s rows, data %s MB, indexes %s MB',
                self::coerceString($table['table_rows'] ?? ''),
                self::coerceString($table['data_mb'] ?? ''),
                self::coerceString($table['index_mb'] ?? ''),
            );
        }

        return $environment;
    }

    private function statements(): int
    {
        $row = $this->db->fetchAssociative("SHOW SESSION STATUS LIKE 'Questions'");

        return is_array($row) ? self::coerceInt($row['Value'] ?? 0) : 0;
    }

    /**
     * A record written through the ordinary write path: the table's cache scope moves, as after any save.
     */
    private function writeRecord(string $tableName): void
    {
        $data = [];
        $columns = $tableName === Data::QA_TABLE ? Data::QA_COLUMNS : Data::MILLION_COLUMNS;
        for ($p = 1; $p <= $columns; ++$p) {
            $data[Data::key($tableName, $p)] = match (true) {
                $tableName === Data::QA_TABLE && $p === 3, $tableName === Data::MILLION_TABLE && $p === 4 => '7',
                $tableName === Data::QA_TABLE && $p === 4, $tableName === Data::MILLION_TABLE && $p === 5 => '2025-12-31',
                $tableName === Data::QA_TABLE && $p === 5, $tableName === Data::MILLION_TABLE && $p === 6 => '1',
                $tableName === Data::QA_TABLE && $p === 6 => '2025-12-31 12:00:00',
                default => 'written during the proof ' . $p,
            };
        }

        $saved = $this->service(DataService::class)->saveData($tableName, $data, LookupService::TRANSACTION_BY_BY_SYSTEM, null, false);
        self::assertNotFalse($saved, 'The write of the proof must succeed.');
    }

    private function clearApplicationCaches(int $tableId): void
    {
        $cache = $this->service(CacheService::class);
        $cache->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateAllListsInCategory();
        $cache->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId);
    }

    // -- fixtures ------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function columnsConfig(string $table, int $shown): array
    {
        $key = static fn (int $p): string => Data::key($table, $p);
        if ($table === Data::MILLION_TABLE) {
            return [
                ['field' => $key(1), 'label' => 'Title', 'sortable' => true, 'searchable' => true],
                ['field' => $key(2), 'label' => 'Category', 'sortable' => true],
                ['field' => $key(3), 'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
                ['field' => $key(4), 'label' => 'Amount', 'sortable' => true, 'format' => 'number'],
                ['field' => $key(5), 'label' => 'Published', 'sortable' => true, 'format' => 'date'],
                ['field' => $key(6), 'label' => 'Featured', 'format' => 'boolean'],
                ['field' => $key(7), 'label' => 'Description', 'searchable' => true],
                ['field' => $key(8), 'label' => 'Note', 'searchable' => true],
            ];
        }

        $columns = [
            ['field' => $key(1), 'label' => 'Title', 'sortable' => true, 'searchable' => true],
            ['field' => $key(2), 'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
            ['field' => $key(3), 'label' => 'Priority', 'sortable' => true, 'format' => 'number'],
            ['field' => $key(4), 'label' => 'Due', 'sortable' => true, 'format' => 'date'],
            ['field' => $key(5), 'label' => 'Done', 'format' => 'boolean'],
            ['field' => $key(6), 'label' => 'Created', 'sortable' => true, 'format' => 'datetime'],
        ];
        for ($p = 7; $p <= $shown; ++$p) {
            $columns[] = ['field' => $key($p), 'label' => 'Filler ' . $p];
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function controlsConfig(string $table, bool $facets): array
    {
        if ($table !== Data::MILLION_TABLE) {
            return [];
        }
        $key = static fn (int $p): string => Data::key($table, $p);
        $status = ['id' => 'status', 'type' => 'select', 'field' => $key(3), 'label' => 'Status'];
        $status += $facets
            ? ['options_from' => 'data']
            : ['options' => [['value' => 'open', 'label' => 'Open'], ['value' => 'done', 'label' => 'Done'], ['value' => 'archived', 'label' => 'Archived'], ['value' => 'blocked', 'label' => 'Blocked']]];
        $controls = [
            $status,
            ['id' => 'amount', 'type' => 'number_range', 'field' => $key(4), 'label' => 'Amount'],
            ['id' => 'published', 'type' => 'date_range', 'field' => $key(5), 'label' => 'Published'],
        ];
        if ($facets) {
            $controls[] = ['id' => 'category', 'type' => 'select', 'field' => $key(2), 'label' => 'Category', 'options_from' => 'data'];
        }

        return $controls;
    }

    /**
     * @return array{0: Page, 1: Section}
     */
    private function publicView(string $prefix, int $tableId, string $table, bool $facets, int $columns = 8): array
    {
        $page = $this->pages->createPage($prefix, true);
        $view = $this->pages->createSection($prefix . '_view', 'data-view');
        $this->pages->linkSectionToPage($page, $view, 10);

        $key = static fn (int $p): string => Data::key($table, $p);
        $fields = [
            'list_key' => 'perf',
            'data_table' => (string) $tableId,
            'page_size' => '25',
            'list_columns' => json_encode($this->columnsConfig($table, $columns), JSON_THROW_ON_ERROR),
            'list_controls' => json_encode($this->controlsConfig($table, $facets), JSON_THROW_ON_ERROR),
            'list_sort' => json_encode([['field' => 'entry_date', 'direction' => 'desc']], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => json_encode(['mode' => 'and', 'children' => [['column' => $key($table === Data::MILLION_TABLE ? 3 : 2), 'operator' => 'neq', 'value' => 'blocked']]], JSON_THROW_ON_ERROR),
        ];
        foreach ($fields as $name => $content) {
            $this->pages->setSectionField($view, $name, $content);
        }

        return [$page, $view];
    }

    /**
     * @return array{0: Page, 1: Section}
     */
    private function participantList(string $prefix, int $tableId, string $table): array
    {
        $page = $this->pages->createPage($prefix, true);
        $list = $this->pages->createSection($prefix . '_list', 'entry-form-list');
        $this->pages->linkSectionToPage($page, $list, 10);

        $fields = [
            'list_key' => 'perf',
            'data_table' => (string) $tableId,
            'own_entries_only' => '1',
            'page_size' => '25',
            'list_columns' => json_encode($this->columnsConfig($table, 8), JSON_THROW_ON_ERROR),
            'list_controls' => json_encode($this->controlsConfig($table, false), JSON_THROW_ON_ERROR),
            'list_sort' => json_encode([['field' => 'entry_date', 'direction' => 'desc']], JSON_THROW_ON_ERROR),
        ];
        foreach ($fields as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }

        return [$page, $list];
    }

    // -- plan evidence ---------------------------------------------------------------------

    /**
     * Id of the newest statement MySQL remembers for this connection (0 when it remembers none).
     */
    private function lastEventId(): int
    {
        try {
            return self::coerceInt($this->db->fetchOne(
                'SELECT COALESCE(MAX(e.EVENT_ID), 0) FROM performance_schema.events_statements_history e'
                . ' JOIN performance_schema.threads t ON t.THREAD_ID = e.THREAD_ID WHERE t.PROCESSLIST_ID = CONNECTION_ID()'
            ));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The statements of this connection after a mark (MySQL remembers the last ten per connection):
     * the rows each examined and sent, and the time it took.
     *
     * @return list<array{sql: string, rows_examined: int, rows_sent: int, ms: float}>
     */
    private function statementsSince(int $eventId): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT LEFT(e.SQL_TEXT, 150) AS sql_text, e.ROWS_EXAMINED AS examined, e.ROWS_SENT AS sent, e.TIMER_WAIT / 1000000000 AS ms'
                . ' FROM performance_schema.events_statements_history e'
                . ' JOIN performance_schema.threads t ON t.THREAD_ID = e.THREAD_ID'
                . " WHERE t.PROCESSLIST_ID = CONNECTION_ID() AND e.EVENT_ID > :mark AND e.SQL_TEXT IS NOT NULL"
                . " AND e.SQL_TEXT NOT LIKE '%performance_schema%' AND e.SQL_TEXT NOT LIKE 'SHOW SESSION%'"
                . ' ORDER BY e.EVENT_ID ASC',
                ['mark' => $eventId],
                ['mark' => ParameterType::INTEGER]
            );
        } catch (\Throwable) {
            return [];
        }

        $statements = [];
        foreach ($rows as $row) {
            $statements[] = [
                'sql' => preg_replace('/\s+/', ' ', self::coerceString($row['sql_text'] ?? '')) ?? '',
                'rows_examined' => self::coerceInt($row['examined'] ?? 0),
                'rows_sent' => self::coerceInt($row['sent'] ?? 0),
                'ms' => round((float) self::coerceString($row['ms'] ?? '0'), 1),
            ];
        }

        return $statements;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function explainAnalyze(string $sql, array $params): string
    {
        $types = [];
        foreach ($params as $name => $value) {
            $types[$name] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        }

        try {
            return self::coerceString($this->db->fetchOne('EXPLAIN ANALYZE ' . $sql, $params, $types));
        } catch (\Throwable $e) {
            return 'EXPLAIN ANALYZE failed: ' . $e->getMessage();
        }
    }

    private static function markdown(): string
    {
        $lines = [
            '# Form list performance proof',
            '',
            'Generated by `tests/Golden/FormListPerformanceTest.php` on ' . gmdate('Y-m-d H:i:s') . ' UTC.',
            '',
            'Measured on: ' . implode('; ', array_map(static fn (string $name, string $value): string => $name . ' = ' . $value, array_keys(self::$environment), self::$environment)) . '.',
            '',
            '## Requests (milliseconds; statements = MySQL statements of the request; peak = PHP peak memory)',
            '',
            '| Scenario | Rows / total | Cells | Cold ms | Cached ms | After write ms | Statements cold / cached / after | Peak MB (cold) | Bytes | Budgets |',
            '| --- | --- | --- | ---: | ---: | ---: | --- | ---: | ---: | --- |',
        ];
        foreach (self::$results as $result) {
            $cold = self::section($result, 'cold');
            $cached = self::section($result, 'cached');
            $after = self::section($result, 'after_write');
            $budgets = is_array($result['within_budget'] ?? null) ? $result['within_budget'] : [];
            $failed = array_keys(array_filter($budgets, static fn (mixed $ok): bool => $ok === false));
            $lines[] = sprintf(
                '| %s | %s / %s | %s | %s | %s | %s | %s / %s / %s | %s | %s | %s |',
                self::text($result['scenario'] ?? ''),
                self::text($result['rows'] ?? ''),
                self::text($result['total'] ?? ''),
                self::text($result['cells_in_response'] ?? ''),
                self::text($cold['ms'] ?? ''),
                self::text($cached['ms'] ?? ''),
                self::text($after['ms'] ?? ''),
                self::text($cold['statements'] ?? ''),
                self::text($cached['statements'] ?? ''),
                self::text($after['statements'] ?? ''),
                self::text($cold['peak_mb'] ?? ''),
                self::text($cold['bytes'] ?? ''),
                $failed === [] ? 'all met' : 'missed: ' . implode(', ', $failed),
            );
        }

        $lines[] = '';
        $lines[] = '## Query plans and rows examined (million-record table)';
        foreach (self::$plans as $label => $plan) {
            $lines[] = '';
            $lines[] = '### ' . $label;
            $lines[] = '';
            $lines[] = sprintf(
                'Rows returned %s, cells returned %s, run %s ms, count %s in %s ms.',
                self::text($plan['rows_returned'] ?? ''),
                self::text($plan['cells_returned'] ?? ''),
                self::text($plan['run_ms'] ?? ''),
                self::text($plan['count'] ?? ''),
                self::text($plan['count_ms'] ?? ''),
            );
            $statements = is_array($plan['statements'] ?? null) ? $plan['statements'] : [];
            if ($statements !== []) {
                $lines[] = '';
                $lines[] = '| Statement | Rows examined | Rows sent | ms |';
                $lines[] = '| --- | ---: | ---: | ---: |';
                foreach ($statements as $statement) {
                    if (!is_array($statement)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '| `%s` | %s | %s | %s |',
                        str_replace('|', '\\|', self::text($statement['sql'] ?? '')),
                        self::text($statement['rows_examined'] ?? ''),
                        self::text($statement['rows_sent'] ?? ''),
                        self::text($statement['ms'] ?? ''),
                    );
                }
            }
            $lines[] = '';
            $lines[] = '```';
            $lines[] = self::text($plan['plan'] ?? '');
            $lines[] = '```';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function section(array $result, string $key): array
    {
        $section = $result[$key] ?? [];
        if (!is_array($section)) {
            return [];
        }
        $typed = [];
        foreach ($section as $name => $value) {
            $typed[(string) $name] = $value;
        }

        return $typed;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
