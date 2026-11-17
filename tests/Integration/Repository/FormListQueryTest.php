<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\DataQuery\DataQueryResult;
use App\Service\CMS\DataQuery\DataSearch;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\DataQuery\LegacyFilterTranslator;
use App\Service\CMS\DataService;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The structured data query engine that every list, table, repeater, prefill
 * and export reads through. Each test states one rule of the engine and proves
 * it on rows that are written straight into the generic store, so the rule is
 * checked against the data shapes that really occur: numbers and dates stored
 * as text, cells that are missing or empty, two languages for one value,
 * soft-deleted and ownerless records.
 */
#[Group('form-list')]
final class FormListQueryTest extends QaKernelTestCase
{
    private DataQueryEngine $engine;
    private DataGridFactory $grid;
    private Connection $db;
    private int $ownerOne;
    private int $ownerTwo;
    private int $tableId;

    /** @var array<string, int> record name => record id */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->service(DataQueryEngine::class);
        $this->db = $this->em->getConnection();
        $this->grid = new DataGridFactory($this->db);
        $this->ownerOne = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $this->ownerTwo = $this->userId(QaBaselineFixture::QA_EDITOR_EMAIL);
        $this->world();
    }

    // -- typed sorting -----------------------------------------------------------

    public function testNumberColumnSortsAsNumbersAndUnreadableValuesComeLast(): void
    {
        $asc = $this->names($this->read(sorts: [new DataSort('price')]));
        // 2.5, 9, 10, 10 (tie: lower id first), 100; then 'abc' and the row without a cell.
        self::assertSame(['delta', 'alpha', 'beta', 'twin', 'gamma', 'eps', 'blank'], $asc);

        $desc = $this->names($this->read(sorts: [new DataSort('price', DataSort::DESC)]));
        // Unreadable values stay last in both directions; ties follow the direction of the first key.
        self::assertSame(['gamma', 'twin', 'beta', 'alpha', 'delta', 'blank', 'eps'], $desc);
    }

    public function testTheSameValuesInATextColumnSortAsText(): void
    {
        $asc = $this->names($this->read(sorts: [new DataSort('price_text')]));
        self::assertSame(['beta', 'twin', 'gamma', 'delta', 'alpha', 'eps', 'blank'], $asc, '"10" < "100" < "2.5" < "9" < "abc" as text; the row without a cell is last.');
    }

    public function testDateAndDatetimeColumnsSortChronologically(): void
    {
        $born = $this->names($this->read(sorts: [new DataSort('born')]));
        // 2019-12-31, 2020-01-15, 2021-06-01; then the empty, invalid (2020-02-30) and missing values.
        self::assertSame(['beta', 'alpha', 'gamma'], array_slice($born, 0, 3));
        self::assertEqualsCanonicalizing(['delta', 'eps', 'twin', 'blank'], array_slice($born, 3));

        $seen = $this->names($this->read(sorts: [new DataSort('seen')]));
        // 2025-12-31 23:59, 2026-01-01 09:00 (written with T and Z), 2026-01-01 10:00.
        self::assertSame(['gamma', 'beta', 'alpha'], array_slice($seen, 0, 3));
    }

    public function testSecondSortKeyBreaksTiesAndRecordIdIsTheLastResort(): void
    {
        $names = $this->names($this->read(sorts: [new DataSort('price'), new DataSort('title', DataSort::DESC)]));
        // beta and twin both have price 10 and title "beta": the record id decides, in the direction of the first key.
        self::assertSame(['delta', 'alpha', 'beta', 'twin', 'gamma'], array_slice($names, 0, 5));

        $byTitle = $this->names($this->read(sorts: [new DataSort('title')]));
        self::assertSame(['alpha', 'beta', 'twin', 'delta', 'gamma', 'eps'], array_slice($byTitle, 0, 6), 'Equal titles keep record id order.');
        self::assertSame('blank', $byTitle[6], 'A row without a title sorts last.');
    }

    // -- typed filters -----------------------------------------------------------

    public function testNumbersCompareAsNumbersAndTextCompareAsText(): void
    {
        self::assertEqualsCanonicalizing(['beta', 'twin', 'gamma'], $this->names($this->read(new DataFilter('price', DataFilter::OP_GT, 9))), 'In a number column "100" and "10" are greater than 9; "abc" never matches.');
        self::assertSame(['eps'], $this->names($this->read(new DataFilter('price_text', DataFilter::OP_GT, '9'))), 'In a text column only "abc" sorts after "9".');
    }

    public function testBetweenInAndNotInAndNeq(): void
    {
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'twin'], $this->names($this->read(new DataFilter('price', DataFilter::OP_BETWEEN, [5, 50]))));
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'twin'], $this->names($this->read(new DataFilter('title', DataFilter::OP_IN, ['Alpha', 'beta']))));

        $notIn = $this->names($this->read(new DataFilter('title', DataFilter::OP_NOT_IN, ['Alpha'])));
        self::assertEqualsCanonicalizing(['beta', 'twin', 'gamma', 'delta', 'eps', 'blank'], $notIn, 'A row without a title is not in the list.');

        $neq = $this->names($this->read(new DataFilter('title', DataFilter::OP_NEQ, 'beta')));
        self::assertEqualsCanonicalizing(['alpha', 'gamma', 'delta', 'eps', 'blank'], $neq);
    }

    public function testTextOperatorsAreCaseInsensitiveAndPercentAndUnderscoreAreLiteral(): void
    {
        self::assertSame(['alpha'], $this->names($this->read(new DataFilter('title', DataFilter::OP_CONTAINS, 'ALPHA'))));
        self::assertSame(['alpha'], $this->names($this->read(new DataFilter('title', DataFilter::OP_STARTS_WITH, 'alp'))));
        self::assertSame(['eps'], $this->names($this->read(new DataFilter('title', DataFilter::OP_ENDS_WITH, 'code'))));
        self::assertSame(['eps'], $this->names($this->read(new DataFilter('title', DataFilter::OP_CONTAINS, 'ünï'))));
        self::assertSame(['delta'], $this->names($this->read(new DataFilter('title', DataFilter::OP_CONTAINS, '%'))), 'A % in the value is a percent sign, not a wildcard.');
        self::assertSame(['delta'], $this->names($this->read(new DataFilter('note', DataFilter::OP_CONTAINS, '_'))), 'An _ in the value is an underscore, not a wildcard.');
        self::assertSame([], $this->names($this->read(new DataFilter('title', DataFilter::OP_CONTAINS, 'a_p'))), '_ must not match any single character.');
    }

    public function testEmptyMeansMissingOrEmptyText(): void
    {
        self::assertEqualsCanonicalizing(['beta', 'twin', 'gamma', 'blank'], $this->names($this->read(new DataFilter('note', DataFilter::OP_EMPTY))));
        self::assertEqualsCanonicalizing(['alpha', 'delta', 'eps'], $this->names($this->read(new DataFilter('note', DataFilter::OP_NOT_EMPTY))));
    }

    public function testBooleanColumnReadsTheCommonSpellings(): void
    {
        self::assertEqualsCanonicalizing(['alpha', 'gamma'], $this->names($this->read(new DataFilter('done', DataFilter::OP_EQ, true))), '"1" and "true" are yes.');
        self::assertSame(['beta'], $this->names($this->read(new DataFilter('done', DataFilter::OP_EQ, 'no'))), '"0" is no; "maybe" is neither.');
        self::assertEqualsCanonicalizing(['beta', 'twin', 'delta', 'eps', 'blank'], $this->names($this->read(new DataFilter('done', DataFilter::OP_NEQ, 'yes'))));
    }

    public function testDateAndDatetimeColumnsCompareChronologically(): void
    {
        self::assertEqualsCanonicalizing(['alpha', 'gamma'], $this->names($this->read(new DataFilter('born', DataFilter::OP_GTE, '2020-01-01'))), 'The invalid date 2020-02-30 never matches.');
        self::assertEqualsCanonicalizing(['alpha', 'beta'], $this->names($this->read(new DataFilter('seen', DataFilter::OP_GTE, '2026-01-01 00:00'))), '"2026-01-01T09:00:00.000Z" is read as 09:00.');
        self::assertEqualsCanonicalizing(['gamma', 'beta'], $this->names($this->read(new DataFilter('seen', DataFilter::OP_BETWEEN, ['2025-12-31 23:00', '2026-01-01 09:30']))));
    }

    public function testInstantsWithAZoneAreComparedInUtcAndEquivalentInstantsMatch(): void
    {
        // Times are UTC: a text with a zone is the instant it names, a text without a zone is a UTC clock time.
        $at = $this->instantTable();

        $nine = [
            'utc_clock', 'z', 'plus_one', 'plus_one_compact', 'minus_five', 'fraction',
        ];
        self::assertEqualsCanonicalizing(
            $nine,
            $this->instantNames($at, new DataFilter('at', DataFilter::OP_EQ, '2026-11-14 09:00:00')),
            'Six spellings of 09:00 UTC are one instant; the second is compared, not its fraction.',
        );
        foreach (['2026-11-14T10:00:00+01:00', '2026-11-14T04:00:00-05:00', '2026-11-14T09:00:00Z', '2026-11-14T09:00:00.4+00:00'] as $spelled) {
            self::assertEqualsCanonicalizing(
                $nine,
                $this->instantNames($at, new DataFilter('at', DataFilter::OP_EQ, $spelled)),
                'A filter value with a zone names the same instant: ' . $spelled,
            );
        }

        self::assertEqualsCanonicalizing(
            ['later', 'zone_less'],
            $this->instantNames($at, new DataFilter('at', DataFilter::OP_GT, '2026-11-14T09:00:00Z')),
            '09:00:01 UTC and a text without a zone at 10:00 (a UTC clock) are after 09:00 UTC.',
        );
        self::assertSame(
            ['earlier'],
            $this->instantNames($at, new DataFilter('at', DataFilter::OP_LT, '2026-11-14 09:00:00')),
            '09:59:59 at +01:00 is 08:59:59 UTC: before 09:00, although its clock reads later.',
        );
        self::assertEqualsCanonicalizing(
            ['earlier', 'utc_clock', 'z', 'plus_one', 'plus_one_compact', 'minus_five', 'fraction'],
            $this->instantNames($at, new DataFilter('at', DataFilter::OP_BETWEEN, ['2026-11-14T09:00:00+01:00', '2026-11-14 09:00:00'])),
            'A range names its bounds as instants too.',
        );

        // Sorted by instant: the earliest first, equal instants by record id, unreadable texts last (in id order).
        self::assertSame(
            ['earlier', 'utc_clock', 'z', 'plus_one', 'plus_one_compact', 'minus_five', 'fraction', 'later', 'zone_less', 'bad_clock', 'bad_offset', 'bad_day', 'text', 'empty'],
            $this->instantNames($at, null, [new DataSort('at')]),
        );
    }

    public function testADateThatDoesNotExistIsNeverReadAndNeverBreaksAListing(): void
    {
        $at = $this->instantTable();

        // Texts that are not a date or a time (a day or a clock that does not exist, an offset nobody has) are read as
        // missing: they never match a comparison and the listing that holds them still works.
        self::assertCount(14, $this->instantNames($at, null, [new DataSort('at')]), 'The listing holds every row, readable or not.');
        self::assertEqualsCanonicalizing(
            ['earlier', 'utc_clock', 'z', 'plus_one', 'plus_one_compact', 'minus_five', 'fraction', 'later', 'zone_less'],
            $this->instantNames($at, new DataFilter('at', DataFilter::OP_GTE, '2000-01-01 00:00')),
            'Every readable row matches a bound in the past and no unreadable row does.',
        );
        self::assertEqualsCanonicalizing(
            ['bad_clock', 'bad_offset', 'bad_day', 'text', 'empty'],
            $this->instantNames($at, new DataFilter('at', DataFilter::OP_NOT_IN, ['2026-11-14 09:00:00', '2026-11-14 10:00:00', '2026-11-14 09:00:01', '2026-11-14 08:59:59'])),
            'Not in a list: a text that is not read as a time is "not in" every list.',
        );

        // The days of a calendar: leap days exist, only in leap years.
        $days = $this->grid->table('qa_fl_days_' . bin2hex(random_bytes(4)), ['day' => 'date']);
        $leap = $this->grid->row($days, $this->ownerOne, ['day' => '2024-02-29']);
        $century = $this->grid->row($days, $this->ownerOne, ['day' => '2000-02-29']);
        $this->grid->row($days, $this->ownerOne, ['day' => '2026-02-29']);
        $this->grid->row($days, $this->ownerOne, ['day' => '2100-02-29']);
        $this->grid->row($days, $this->ownerOne, ['day' => '2026-04-31']);
        $this->tableId = $days;
        $found = $this->engine->run($this->query(filter: new DataFilter('day', DataFilter::OP_GTE, '1900-01-01')));
        self::assertEqualsCanonicalizing(
            [$leap, $century],
            array_map(static fn (array $row): int => self::coerceInt($row['record_id']), $found->rows),
            '2024-02-29 and 2000-02-29 exist; 2026-02-29, 2100-02-29 and 2026-04-31 do not.',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function impossibleFilterValues(): iterable
    {
        yield 'a date that is not on the calendar' => ['day', '2026-02-29', 'must be a date that exists'];
        yield 'a month that does not exist' => ['day', '2026-13-01', 'must be a date that exists'];
        yield 'a date with a time in a date column' => ['day', '2026-02-27 10:00', 'must be a date that exists'];
        yield 'a clock that does not exist' => ['at', '2026-11-14 99:99:99', 'date with time that exists'];
        yield 'hour 24' => ['at', '2026-11-14 24:00', 'date with time that exists'];
        yield 'minute 60' => ['at', '2026-11-14 23:60', 'date with time that exists'];
        yield 'second 60' => ['at', '2026-11-14 23:59:60', 'date with time that exists'];
        yield 'an offset that does not exist' => ['at', '2026-11-14T10:00:00+99:99', 'date with time that exists'];
        yield 'an offset past +14:00' => ['at', '2026-11-14T10:00:00+14:01', 'date with time that exists'];
        yield 'an offset past -13:59' => ['at', '2026-11-14T10:00:00-14:00', 'date with time that exists'];
        yield 'minutes of an offset past 59' => ['at', '2026-11-14T10:00:00+01:60', 'date with time that exists'];
        yield 'a day that does not exist, with a time' => ['at', '2026-02-30 10:00', 'date with time that exists'];
        yield 'a lower case zone' => ['at', '2026-11-14T10:00:00z', 'date with time that exists'];
        yield 'a space before the offset' => ['at', '2026-11-14T10:00:00 +01:00', 'date with time that exists'];
    }

    #[DataProvider('impossibleFilterValues')]
    public function testAFilterValueThatIsNotADateOrTimeIsRefusedBeforeAnythingRuns(string $column, string $value, string $message): void
    {
        $table = $this->grid->table('qa_fl_bad_' . bin2hex(random_bytes(4)), ['day' => 'date', 'at' => 'datetime']);
        $this->tableId = $table;

        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter($column, DataFilter::OP_EQ, $value))), $message);
    }

    public function testFilterGroupsCombineWithAndOrOr(): void
    {
        $group = new DataFilterGroup(DataFilterGroup::MODE_AND, [
            new DataFilter('price', DataFilter::OP_GTE, 10),
            new DataFilterGroup(DataFilterGroup::MODE_OR, [
                new DataFilter('title', DataFilter::OP_STARTS_WITH, 'g'),
                new DataFilter('title', DataFilter::OP_EQ, 'beta'),
            ]),
        ]);

        self::assertEqualsCanonicalizing(['beta', 'twin', 'gamma'], $this->names($this->engine->run($this->query(filter: $group))));
    }

    public function testEveryRowFieldCanBeFilteredAndSorted(): void
    {
        self::assertSame(['alpha'], $this->names($this->read(new DataFilter('record_id', DataFilter::OP_EQ, $this->rows['alpha']))));
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'eps', 'blank'], $this->names($this->read(new DataFilter('id_users', DataFilter::OP_EQ, $this->ownerOne))));
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'eps', 'blank'], $this->names($this->read(new DataFilter('user_name', DataFilter::OP_EQ, $this->userName($this->ownerOne)))));
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'gamma', 'delta', 'eps', 'twin', 'blank'], $this->names($this->read(new DataFilter('triggerType', DataFilter::OP_EQ, 'finished'))));
        self::assertSame(['blank', 'eps', 'delta', 'gamma', 'twin', 'beta', 'alpha'], $this->names($this->read(sorts: [new DataSort('entry_date', DataSort::DESC)])), 'Newest record first.');
        self::assertSame(['twin', 'blank', 'eps', 'delta', 'gamma', 'beta', 'alpha'], $this->names($this->read(sorts: [new DataSort('record_id', DataSort::DESC)])));
    }

    // -- search ------------------------------------------------------------------

    public function testSearchIsACaseInsensitiveSubstringOverTheChosenColumns(): void
    {
        self::assertSame(['alpha'], $this->names($this->engine->run($this->query(search: new DataSearch('PH', ['title', 'note'])))));
        self::assertSame(['alpha'], $this->names($this->engine->run($this->query(search: new DataSearch('first NOTE', ['title', 'note'])))), 'The term is matched as typed, including a space.');
        self::assertSame(['delta'], $this->names($this->engine->run($this->query(search: new DataSearch('a%', ['title', 'note'])))), 'A % is literal: only "delta%" contains "a%".');
        self::assertSame([], $this->names($this->engine->run($this->query(search: new DataSearch('price', ['title'])))), 'Only the chosen columns are searched.');
    }

    public function testSearchNarrowsTheFilteredSetAndNeverWidensIt(): void
    {
        $query = $this->query(
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [new DataFilter('id_users', DataFilter::OP_EQ, $this->ownerTwo)]),
            search: new DataSearch('ta', ['title']),
        );

        // Titles with "ta" that belong to owner two: "delta%" and "beta" (twin), not "beta" of owner one.
        self::assertEqualsCanonicalizing(['delta', 'twin'], $this->names($this->engine->run($query)));
    }

    public function testSearchRejectsShortLongAndUnknownInput(): void
    {
        $this->expectError(fn () => $this->engine->run($this->query(search: new DataSearch('a', ['title']))), 'between 2 and 100');
        $this->expectError(fn () => $this->engine->run($this->query(search: new DataSearch(str_repeat('a', 101), ['title']))), 'between 2 and 100');
        $this->expectError(fn () => $this->engine->run($this->query(search: new DataSearch('abc', ['nope']))), 'Unknown column');
        $this->expectError(fn () => $this->engine->run($this->query(search: new DataSearch('abc', ['record_id']))), 'Unknown column');
        $this->expectError(fn () => $this->engine->run($this->query(search: new DataSearch('abc', []))), 'between 1 and');
    }

    // -- owner scope and deleted rows --------------------------------------------

    public function testOwnerScopesLimitWhoseRecordsAreRead(): void
    {
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'eps', 'blank'], $this->names($this->engine->run($this->query(ownerScope: DataQuery::OWNER_USER, userId: $this->ownerOne))));
        self::assertEqualsCanonicalizing(['gamma', 'delta', 'twin'], $this->names($this->engine->run($this->query(ownerScope: DataQuery::OWNER_USER, userId: $this->ownerTwo))));
        self::assertEqualsCanonicalizing(['alpha', 'beta', 'gamma', 'delta', 'eps', 'twin', 'blank'], $this->names($this->engine->run($this->query())));
    }

    public function testAGuestWithAnOwnOnlyReadSeesNothing(): void
    {
        // The stored procedure treated "no user" as "no owner filter" and returned every record to a guest.
        self::assertSame([], $this->names($this->engine->run($this->query(ownerScope: DataQuery::OWNER_USER, userId: null))));
        self::assertSame([], $this->names($this->engine->run($this->query(ownerScope: DataQuery::OWNER_USER, userId: 0))));
        self::assertSame([], $this->names($this->engine->run($this->query(ownerScope: DataQuery::OWNER_GROUPS, userId: null))));
    }

    public function testSoftDeletedRecordsAreLeftOutUnlessAskedFor(): void
    {
        self::assertNotContains('gone', $this->names($this->engine->run($this->query())));

        $all = $this->engine->run($this->query(excludeDeleted: false));
        self::assertContains('gone', $this->names($all));
        $gone = $this->rowOf($all, 'gone');
        self::assertSame('deleted', $gone['triggerType']);
    }

    // -- paging, counting and projection -----------------------------------------

    public function testPagesAreStableAndTheTotalIsTheUnpagedCount(): void
    {
        $sorts = [new DataSort('title')];
        $page1 = $this->engine->run($this->query(sorts: $sorts, limit: 3, offset: 0, withTotal: true));
        $page2 = $this->engine->run($this->query(sorts: $sorts, limit: 3, offset: 3, withTotal: true));
        $page3 = $this->engine->run($this->query(sorts: $sorts, limit: 3, offset: 6, withTotal: true));
        $beyond = $this->engine->run($this->query(sorts: $sorts, limit: 3, offset: 9, withTotal: true));

        self::assertSame(['alpha', 'beta', 'twin'], $this->names($page1));
        self::assertSame(['delta', 'gamma', 'eps'], $this->names($page2));
        self::assertSame(['blank'], $this->names($page3));
        self::assertSame([], $this->names($beyond));
        foreach ([$page1, $page2, $page3, $beyond] as $page) {
            self::assertSame(7, $page->total);
        }
        self::assertSame(7, $this->engine->count($this->query()));
        self::assertSame(3, $this->engine->count($this->query(filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [new DataFilter('price', DataFilter::OP_GT, 9)]))));
    }

    public function testProjectionReadsOnlyTheRequestedColumns(): void
    {
        $result = $this->engine->run($this->query(columns: ['title']));
        $row = $this->rowOf($result, 'alpha');

        self::assertSame('Alpha', $row['title']);
        self::assertArrayNotHasKey('price', $row);
        foreach (['record_id', 'entry_date', 'id_users', 'user_name', 'user_code', 'id_action_trigger_types', 'triggerType'] as $field) {
            self::assertArrayHasKey($field, $row);
        }

        $none = $this->engine->run($this->query(columns: []));
        self::assertCount(7, $none->rows);
        self::assertArrayNotHasKey('title', $none->rows[0]);
        $this->expectError(fn () => $this->engine->run($this->query(columns: ['nope'])), 'Unknown column');
    }

    public function testAMissingCellIsNullInTheRow(): void
    {
        $result = $this->engine->run($this->query());
        self::assertNull($this->rowOf($result, 'gamma')['note']);
        self::assertSame('', $this->rowOf($result, 'beta')['note']);
        self::assertNull($this->rowOf($result, 'blank')['title']);
    }

    public function testAnOptionalColumnNobodyHasStoredReadsAsEmptyInsteadOfFailing(): void
    {
        // The input of a form becomes a column when a record first holds a value for it. Until then a list may
        // still name it (the save check accepts it), and every way of reading it finds nothing, never an error.
        $optional = ['pending' => 'text', 'budget' => 'number'];
        $all = $this->engine->run($this->query());
        $count = count($all->rows);
        self::assertGreaterThan(3, $count);

        $this->expectError(fn () => $this->engine->run(new DataQuery(tableId: $this->tableId, columns: ['pending'])), 'Unknown column "pending"');

        $projected = $this->engine->run(new DataQuery(tableId: $this->tableId, limit: null, columns: ['title', 'pending', 'budget'], optionalColumns: $optional));
        self::assertCount($count, $projected->rows, 'The column adds nothing to which records are read.');
        foreach ($projected->rows as $row) {
            self::assertNull($row['pending']);
            self::assertNull($row['budget']);
        }

        $empty = fn (string $column): DataQuery => new DataQuery(
            tableId: $this->tableId,
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [new DataFilter($column, DataFilter::OP_EMPTY, null)]),
            limit: null,
            columns: [],
            optionalColumns: $optional,
        );
        self::assertCount($count, $this->engine->run($empty('pending'))->rows, 'A column nobody wrote is empty in every record.');

        $matching = fn (DataFilter $filter): DataQuery => new DataQuery(
            tableId: $this->tableId,
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [$filter]),
            limit: null,
            columns: [],
            optionalColumns: $optional,
        );
        self::assertSame([], $this->engine->run($matching(new DataFilter('pending', DataFilter::OP_EQ, 'open')))->rows);
        self::assertSame([], $this->engine->run($matching(new DataFilter('budget', DataFilter::OP_GT, 5)))->rows);
        $this->expectError(fn () => $this->engine->run($matching(new DataFilter('budget', DataFilter::OP_GT, 'abc'))), 'must be a number');

        $searched = $this->engine->run(new DataQuery(
            tableId: $this->tableId,
            search: new DataSearch('alp', ['title', 'pending']),
            limit: null,
            columns: [],
            optionalColumns: $optional,
        ));
        self::assertSame(['alpha'], $this->names($searched), 'The search still finds what the other columns hold.');

        $sorted = $this->engine->run(new DataQuery(tableId: $this->tableId, sorts: [new DataSort('pending')], limit: null, columns: [], optionalColumns: $optional));
        self::assertSame($this->names($all), $this->names($sorted), 'Records equal in a column that nobody wrote stay in record order.');

        $counted = new DataQuery(tableId: $this->tableId, columns: [], optionalColumns: $optional);
        self::assertSame($count, $this->engine->count($counted));
        self::assertSame([], $this->engine->facet($counted, 'pending', 10));
    }

    public function testAnOptionalColumnThatIsStoredIsReadFromTheStore(): void
    {
        // Declaring a column optional never hides one that exists.
        $result = $this->engine->run(new DataQuery(tableId: $this->tableId, limit: null, columns: ['title'], optionalColumns: ['title' => 'number']));
        self::assertSame('Alpha', $this->rowOf($result, 'alpha')['title']);
    }

    public function testRecordFieldsCarryTheOwnerAndTheEntryDate(): void
    {
        $row = $this->rowOf($this->engine->run($this->query()), 'alpha');
        self::assertSame($this->rows['alpha'], $row['record_id']);
        self::assertSame($this->ownerOne, $row['id_users']);
        self::assertSame('2026-01-01 23:30:00', $row['entry_date']);
        self::assertSame('finished', $row['triggerType']);
        self::assertSame($this->userName($this->ownerOne), $row['user_name']);
    }

    // -- language ----------------------------------------------------------------

    public function testTheRequestedLanguageWinsAndTheDefaultLanguageIsTheFallback(): void
    {
        [$tableId, $ids] = $this->languageTable();
        $english = $this->engine->run(new DataQuery($tableId, languageId: 3, limit: null));
        // The stored procedure returned the larger of the two texts ("zzz"); the requested language wins.
        self::assertSame('aaa', $this->cell($english, $ids['both'], 'label'));
        self::assertSame('only-default', $this->cell($english, $ids['default'], 'label'));
        self::assertSame('only-english', $this->cell($english, $ids['english'], 'label'));

        $german = $this->engine->run(new DataQuery($tableId, languageId: 2, limit: null));
        self::assertSame('zzz', $this->cell($german, $ids['both'], 'label'));
        self::assertSame('only-default', $this->cell($german, $ids['default'], 'label'));
        self::assertNull($this->cell($german, $ids['english'], 'label'), 'No cell in German or in the default language.');
    }

    public function testFilterAndSortUseTheEffectiveLanguage(): void
    {
        [$tableId, $ids] = $this->languageTable();
        $filter = new DataFilterGroup(DataFilterGroup::MODE_AND, [new DataFilter('label', DataFilter::OP_EQ, 'aaa')]);

        $english = $this->engine->run(new DataQuery($tableId, filter: $filter, languageId: 3, limit: null));
        self::assertSame([$ids['both']], array_map(fn (array $row): int => self::coerceInt($row['record_id']), $english->rows));

        $default = $this->engine->run(new DataQuery($tableId, filter: $filter, languageId: 1, limit: null));
        self::assertSame([], $default->rows);

        $sorted = $this->engine->run(new DataQuery($tableId, sorts: [new DataSort('label')], languageId: 3, limit: null));
        self::assertSame([$ids['both'], $ids['default'], $ids['english']], array_map(fn (array $row): int => self::coerceInt($row['record_id']), $sorted->rows));
    }

    public function testAllLanguagesReturnsOneRowPerRecordAndLanguage(): void
    {
        [$tableId, $ids] = $this->languageTable();
        $result = $this->engine->run(new DataQuery($tableId, allLanguages: true, limit: null, sorts: [new DataSort('record_id')]));

        $pairs = array_map(
            fn (array $row): string => self::coerceInt($row['record_id']) . ':' . self::coerceInt($row['id_languages']) . ':' . self::coerceString($row['language_locale']) . ':' . self::coerceString($row['label']),
            $result->rows
        );
        self::assertSame([
            $ids['both'] . ':1:all:zzz',
            $ids['both'] . ':3:en-GB:aaa',
            $ids['default'] . ':1:all:only-default',
            $ids['english'] . ':3:en-GB:only-english',
        ], $pairs);
    }

    // -- time zone ---------------------------------------------------------------

    public function testEntryDateIsShownAndFilteredInTheRequestedTimeZone(): void
    {
        $zurich = $this->engine->run($this->query(timezone: 'Europe/Zurich'));
        self::assertSame('2026-01-02 00:30:00', $this->rowOf($zurich, 'alpha')['entry_date']);

        $from = new DataFilterGroup(DataFilterGroup::MODE_AND, [new DataFilter('entry_date', DataFilter::OP_GTE, '2026-01-02')]);
        self::assertContains('alpha', $this->names($this->engine->run($this->query(filter: $from, timezone: 'Europe/Zurich'))), '23:30 UTC on 1 January is 00:30 on 2 January in Zurich.');
        self::assertNotContains('alpha', $this->names($this->engine->run($this->query(filter: $from, timezone: 'UTC'))));
    }

    // -- limits and rejected input -----------------------------------------------

    public function testMalformedQueriesAreRejectedBeforeAnythingRuns(): void
    {
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('nope', DataFilter::OP_EQ, 'x'))), 'Unknown column "nope"');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('price', DataFilter::OP_GT, 'abc'))), 'must be a number');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('born', DataFilter::OP_EQ, '2026-13-45'))), 'must be a date');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('seen', DataFilter::OP_EQ, 'yesterday'))), 'date with time');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('done', DataFilter::OP_GT, true))), 'only be compared with "eq" or "neq"');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('done', DataFilter::OP_EQ, 'perhaps'))), 'yes or no');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('price', DataFilter::OP_BETWEEN, [1, 2, 3]))), 'exactly 2 values');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('price', DataFilter::OP_IN, []))), 'list of values');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('price', DataFilter::OP_EQ, null))), 'needs a value');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('price', DataFilter::OP_EQ, [1, 2]))), 'single value');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('title', 'regexp', 'x'))), 'Unknown filter operator');
        $this->expectError(fn () => $this->engine->run($this->query(sorts: [new DataSort('nope')])), 'Unknown column');
    }

    public function testQueryBoundsAreEnforced(): void
    {
        $this->expectError(fn () => $this->engine->run($this->query(limit: 5, offset: -1)), 'must not be negative');
        $this->expectError(fn () => $this->engine->run($this->query(sorts: [new DataSort('title'), new DataSort('price'), new DataSort('born'), new DataSort('seen')])), 'At most 3 sort keys');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('title', DataFilter::OP_EQ, str_repeat('x', 501)))), 'longer than 500');
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('title', DataFilter::OP_IN, array_fill(0, 51, 'x')))), 'At most 50 values');
        $this->expectError(fn () => $this->engine->run($this->query(columns: array_map(static fn (int $i): string => 'c' . $i, range(1, 101)))), 'At most 100 columns');

        $tooMany = new DataFilterGroup(DataFilterGroup::MODE_AND, array_fill(0, 31, new DataFilter('title', DataFilter::OP_EQ, 'x')));
        $this->expectError(fn () => $this->engine->run($this->query(filter: $tooMany)), 'At most 30 filters');

        $deep = new DataFilter('title', DataFilter::OP_EQ, 'x');
        for ($level = 0; $level < 4; ++$level) {
            $deep = new DataFilterGroup(DataFilterGroup::MODE_AND, [$deep]);
        }
        $this->expectError(fn () => $this->engine->run($this->query(filter: $deep)), 'nested at most 3');
    }

    public function testValuesAreBoundNeverPastedIntoSql(): void
    {
        $attack = "x'; DROP TABLE data_rows; --";
        self::assertSame([], $this->names($this->read(new DataFilter('title', DataFilter::OP_EQ, $attack))));
        self::assertSame([], $this->names($this->read(new DataFilter('title', DataFilter::OP_CONTAINS, "' OR '1'='1"))));
        self::assertSame([], $this->names($this->engine->run($this->query(search: new DataSearch("') OR 1=1 -- ", ['title'])))));
        $this->expectError(fn () => $this->engine->run($this->query(filter: new DataFilter('title`; DROP TABLE data_rows; --', DataFilter::OP_EQ, 'x'))), 'Unknown column');

        // The table is still there and still full.
        self::assertSame(7, $this->engine->count($this->query()));
    }

    // -- batches -----------------------------------------------------------------

    public function testBatchesCoverEveryRecordOnceInIdOrder(): void
    {
        $seen = [];
        $sizes = [];
        foreach ($this->engine->batches($this->query(limit: null), 3) as $batch) {
            $sizes[] = count($batch);
            foreach ($batch as $row) {
                $seen[] = self::coerceInt($row['record_id']);
            }
        }

        self::assertSame([3, 3, 1], $sizes);
        $expected = array_map(fn (string $name): int => $this->rows[$name], ['alpha', 'beta', 'gamma', 'delta', 'eps', 'blank', 'twin']);
        sort($expected);
        self::assertSame($expected, $seen);
    }

    public function testBatchesFollowTheSortWhenOneIsGiven(): void
    {
        $names = [];
        foreach ($this->engine->batches($this->query(sorts: [new DataSort('price')], limit: null), 2) as $batch) {
            foreach ($batch as $row) {
                $names[] = array_search(self::coerceInt($row['record_id']), $this->rows, true);
            }
        }

        self::assertSame(['delta', 'alpha', 'beta', 'twin', 'gamma', 'eps', 'blank'], $names);
    }

    public function testARecordWrittenWhileBatchesAreReadIsNeverReadTwice(): void
    {
        $ids = [];
        $inserted = false;
        foreach ($this->engine->batches($this->query(limit: null), 3) as $batch) {
            foreach ($batch as $row) {
                $ids[] = self::coerceInt($row['record_id']);
            }
            if (!$inserted) {
                $this->grid->row($this->tableId, $this->ownerOne, ['title' => 'late']);
                $inserted = true;
            }
        }

        self::assertSame(count($ids), count(array_unique($ids)), 'No record may appear twice.');
        self::assertCount(8, $ids, 'A record written meanwhile follows the cursor and is read once.');
    }

    // -- cache -------------------------------------------------------------------

    public function testResultsAreCachedPerTableAndAWriteThroughTheSavePathRefreshesThem(): void
    {
        $dataService = $this->service(DataService::class);
        $tables = new DataTableFactory($this->em, $dataService);
        $name = 'qa_fl_cache_' . bin2hex(random_bytes(4));
        $tables->addRow($name, ['title' => 'one'], $this->ownerOne);
        $tableId = $this->tableIdByName($name);

        $query = new DataQuery($tableId, limit: null, withTotal: true);
        $first = $this->engine->run($query);
        self::assertSame(1, $first->total);

        // A row written behind the cache's back is not visible: the second read is a cache hit.
        $this->grid->row($tableId, $this->ownerOne, []);
        self::assertSame(1, $this->engine->run($query)->total, 'The unchanged table is served from the cache.');

        // A write through the real save path bumps the table scope.
        $tables->addRow($name, ['title' => 'two'], $this->ownerOne);
        self::assertSame(3, $this->engine->run($query)->total, 'A write through the save path refreshes cached reads of its table.');

        // Another table is not affected by the write.
        $other = $this->engine->run($this->query(limit: null, withTotal: true));
        self::assertSame(7, $other->total);
    }

    public function testExplicitInvalidationOfTheTableScopeRefreshesTheCache(): void
    {
        $query = $this->query(limit: null, withTotal: true);
        self::assertSame(7, $this->engine->run($query)->total);

        $this->grid->row($this->tableId, $this->ownerOne, ['title' => 'extra']);
        self::assertSame(7, $this->engine->run($query)->total);

        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $this->tableId);
        self::assertSame(8, $this->engine->run($query)->total);
    }

    // -- the old readers ----------------------------------------------------------

    /**
     * The engine reads what `get_data_table_filtered` read, for the filter shapes
     * authors write. Rows are clean (one language, no empty cells, equal-width
     * numbers) so only real differences show up; the known differences are
     * asserted in the tests above.
     */
    public function testEngineMatchesTheStoredProcedureForAuthorFilters(): void
    {
        $tableId = $this->grid->table('qa_fl_equiv_' . bin2hex(random_bytes(4)), ['name' => 'text', 'age' => 'number', 'city' => 'text']);
        $this->grid->row($tableId, $this->ownerOne, ['name' => 'Anna', 'age' => '30', 'city' => 'Bern']);
        $this->grid->row($tableId, $this->ownerOne, ['name' => 'Ben', 'age' => '45', 'city' => 'Basel']);
        $this->grid->row($tableId, $this->ownerTwo, ['name' => 'Carla', 'age' => '20', 'city' => 'Bern']);
        $this->grid->row($tableId, $this->ownerTwo, ['name' => 'Dino', 'age' => '60']);
        $this->grid->row($tableId, $this->ownerOne, ['name' => 'Erin', 'age' => '35', 'city' => 'Zug'], null, 'deleted');
        $first = self::coerceInt($this->db->fetchOne('SELECT MIN(id) FROM data_rows WHERE id_data_tables = :t', ['t' => $tableId]));

        $filters = [
            '',
            "AND name = 'Anna'",
            "AND city = 'Bern'",
            'AND age > 25',
            'AND age >= 30 AND age <= 45',
            "AND (age < 25 OR city = 'Basel')",
            "AND name LIKE 'a%'",
            "AND name LIKE '%a'",
            "AND name LIKE '%rl%'",
            "AND name IN ('Anna', 'Dino', 'Nobody')",
            "AND name NOT IN ('Anna')",
            'AND age BETWEEN 25 AND 50',
            'AND city IS NULL',
            'AND city IS NOT NULL',
            'record_id = ' . $first,
            'AND record_id > ' . $first,
            'ORDER BY age DESC',
            'ORDER BY age ASC LIMIT 2',
            "AND city = 'Bern' ORDER BY age DESC",
            'ORDER BY record_id DESC LIMIT 1',
            'ORDER BY name LIMIT 1, 2',
        ];

        $translator = new LegacyFilterTranslator();
        foreach ([null, $this->ownerOne, $this->ownerTwo] as $owner) {
            foreach ($filters as $filter) {
                $legacy = $this->storedProcedureRows($tableId, $owner ?? 0, $filter);
                $translated = $translator->translate($filter);
                $query = new DataQuery(
                    tableId: $tableId,
                    ownerScope: $owner === null ? DataQuery::OWNER_ANY : DataQuery::OWNER_USER,
                    userId: $owner,
                    filter: $translated->filter,
                    sorts: $translated->sorts,
                    limit: $translated->limit,
                    offset: $translated->offset,
                );
                $engine = $this->engine->run($query);

                $legacyIds = array_map(fn (array $row): int => self::coerceInt($row['record_id']), $legacy);
                $engineIds = array_map(fn (array $row): int => self::coerceInt($row['record_id']), $engine->rows);
                $context = sprintf('filter "%s" for owner %s', $filter, $owner === null ? 'any' : (string) $owner);
                if (str_contains($filter, 'ORDER BY')) {
                    self::assertSame($legacyIds, $engineIds, 'Order and rows must match for ' . $context);
                } else {
                    self::assertEqualsCanonicalizing($legacyIds, $engineIds, 'Rows must match for ' . $context);
                }
                foreach ($engine->rows as $index => $engineRow) {
                    $legacyRow = $this->legacyRowFor($legacy, self::coerceInt($engineRow['record_id']));
                    foreach (['name', 'age', 'city', 'id_users', 'triggerType', 'entry_date'] as $field) {
                        self::assertEquals($legacyRow[$field] ?? null, $engineRow[$field] ?? null, sprintf('Field %s of record %d must match for %s', $field, self::coerceInt($engineRow['record_id']), $context));
                    }
                    unset($index);
                }
            }
        }
    }

    /**
     * The group scope reads what `get_data_table_for_user_groups` read: the
     * records of the users in the groups the viewer's roles may access, and
     * nothing for a viewer whose roles reach no group.
     */
    public function testGroupScopeMatchesTheGroupProcedure(): void
    {
        $factory = new RoleDataAccessFactory($this->em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class));
        $group = (new GroupFactory($this->em))->createGroup('qa_fl_equiv_group');
        $users = new UserFactory($this->em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $memberOne = $users->createUser('qa.fl.member1@selfhelp.test', 'Member One', [$group]);
        $memberTwo = $users->createUser('qa.fl.member2@selfhelp.test', 'Member Two', [$group]);
        $outsider = $users->createUser('qa.fl.outsider@selfhelp.test', 'Outsider');
        $viewer = $users->createUser('qa.fl.viewer@selfhelp.test', 'Viewer');
        $nobody = $users->createUser('qa.fl.nobody@selfhelp.test', 'Nobody');
        $role = $factory->createRole('qa_fl_group_reader');
        $factory->grantGroupAccess($role, (int) $group->getId(), DataAccessSecurityService::PERMISSION_READ);
        $factory->assignRoleToUser($viewer, $role);

        $tableId = $this->grid->table('qa_fl_group_equiv_' . bin2hex(random_bytes(4)), ['name' => 'text', 'age' => 'number']);
        $this->grid->row($tableId, (int) $memberOne->getId(), ['name' => 'Anna', 'age' => '30']);
        $this->grid->row($tableId, (int) $memberTwo->getId(), ['name' => 'Ben', 'age' => '45']);
        $this->grid->row($tableId, (int) $outsider->getId(), ['name' => 'Carla', 'age' => '20']);
        $this->grid->row($tableId, (int) $viewer->getId(), ['name' => 'Dino', 'age' => '60']);
        $this->grid->row($tableId, null, ['name' => 'Erin', 'age' => '35']);
        $this->grid->row($tableId, (int) $memberOne->getId(), ['name' => 'Fay', 'age' => '50'], null, 'deleted');

        $translator = new LegacyFilterTranslator();
        foreach ([$viewer, $nobody] as $reader) {
            foreach (['', "AND name = 'Anna'", 'AND age > 25', 'ORDER BY age DESC', 'ORDER BY age ASC LIMIT 1'] as $filter) {
                $legacy = $this->db->executeQuery(
                    'CALL get_data_table_for_user_groups(:t, :u, :f, 1, 1, :tz)',
                    ['t' => $tableId, 'u' => (int) $reader->getId(), 'f' => $this->service(DataTableFilterService::class)->glueLeadingAnd($filter), 'tz' => 'UTC'],
                )->fetchAllAssociative();
                $translated = $translator->translate($filter);
                $engine = $this->engine->run(new DataQuery(
                    tableId: $tableId,
                    ownerScope: DataQuery::OWNER_GROUPS,
                    userId: (int) $reader->getId(),
                    filter: $translated->filter,
                    sorts: $translated->sorts,
                    limit: $translated->limit,
                    offset: $translated->offset,
                ));

                $legacyIds = array_map(fn (array $row): int => self::coerceInt($row['record_id']), $legacy);
                $engineIds = array_map(fn (array $row): int => self::coerceInt($row['record_id']), $engine->rows);
                $context = sprintf('filter "%s" for %s', $filter, $reader->getEmail());
                if (str_contains($filter, 'ORDER BY')) {
                    self::assertSame($legacyIds, $engineIds, 'Order and rows must match for ' . $context);
                } else {
                    self::assertEqualsCanonicalizing($legacyIds, $engineIds, 'Rows must match for ' . $context);
                }
                foreach ($engine->rows as $engineRow) {
                    $legacyRow = $this->legacyRowFor($legacy, self::coerceInt($engineRow['record_id']));
                    foreach (['name', 'age', 'id_users', 'triggerType'] as $field) {
                        self::assertEquals($legacyRow[$field] ?? null, $engineRow[$field] ?? null, sprintf('Field %s must match for %s', $field, $context));
                    }
                }
            }
        }

        // The viewer reads exactly the group members' live records; the viewer's own row, the outsider's, the ownerless and the deleted one are not theirs.
        $viewerNames = array_map(
            fn (array $row): string => self::coerceString($row['name']),
            $this->engine->run(new DataQuery($tableId, ownerScope: DataQuery::OWNER_GROUPS, userId: (int) $viewer->getId(), sorts: [new DataSort('name')]))->rows
        );
        self::assertSame(['Anna', 'Ben'], $viewerNames);
        self::assertSame([], $this->engine->run(new DataQuery($tableId, ownerScope: DataQuery::OWNER_GROUPS, userId: (int) $nobody->getId()))->rows);
    }

    /**
     * The all-languages read gives what `get_data_table_all_languages` gave: one
     * row per record and language that has a cell, with the language columns.
     */
    public function testAllLanguagesMatchesTheAllLanguagesProcedure(): void
    {
        [$tableId] = $this->languageTable();
        $this->grid->row($tableId, $this->ownerTwo, ['label' => [1 => 'plain']]);
        $this->grid->row($tableId, $this->ownerOne, ['label' => 'gone-one'], null, 'deleted');

        foreach ([null, $this->ownerOne, $this->ownerTwo] as $owner) {
            $legacy = $this->db->executeQuery(
                'CALL get_data_table_all_languages(:t, :u, :f, 1, :tz)',
                ['t' => $tableId, 'u' => $owner ?? 0, 'f' => '', 'tz' => 'UTC'],
            )->fetchAllAssociative();
            $engine = $this->engine->run(new DataQuery(
                tableId: $tableId,
                ownerScope: $owner === null ? DataQuery::OWNER_ANY : DataQuery::OWNER_USER,
                userId: $owner,
                sorts: [new DataSort('record_id')],
                allLanguages: true,
                limit: null,
            ));

            $key = fn (array $row): string => self::coerceInt($row['record_id']) . ':' . self::coerceInt($row['id_languages']) . ':' . self::coerceString($row['language_locale'] ?? '') . ':' . self::coerceString($row['label'] ?? '');
            self::assertSame(array_map($key, $legacy), array_map($key, $engine->rows), sprintf('One row per record and language, in record order, for owner %s', $owner === null ? 'any' : (string) $owner));
        }
    }

    // -- helpers -----------------------------------------------------------------

    private function world(): void
    {
        $this->tableId = $this->grid->table('qa_fl_' . bin2hex(random_bytes(4)), [
            'title' => 'text',
            'price' => 'number',
            'price_text' => 'text',
            'born' => 'date',
            'seen' => 'datetime',
            'done' => 'boolean',
            'note' => 'text',
        ]);

        $this->rows['alpha'] = $this->grid->row($this->tableId, $this->ownerOne, [
            'title' => 'Alpha', 'price' => '9', 'price_text' => '9', 'born' => '2020-01-15', 'seen' => '2026-01-01 10:00:00', 'done' => '1', 'note' => 'first note',
        ], '2026-01-01 23:30:00');
        $this->rows['beta'] = $this->grid->row($this->tableId, $this->ownerOne, [
            'title' => 'beta', 'price' => '10', 'price_text' => '10', 'born' => '2019-12-31', 'seen' => '2026-01-01T09:00:00.000Z', 'done' => '0', 'note' => '',
        ], '2026-01-02 08:00:00');
        $this->rows['gamma'] = $this->grid->row($this->tableId, $this->ownerTwo, [
            'title' => 'Gamma', 'price' => '100', 'price_text' => '100', 'born' => '2021-06-01', 'seen' => '2025-12-31 23:59', 'done' => 'true',
        ], '2026-01-03 08:00:00');
        $this->rows['delta'] = $this->grid->row($this->tableId, $this->ownerTwo, [
            'title' => 'delta%', 'price' => '2.5', 'price_text' => '2.5', 'born' => '', 'seen' => 'not-a-date', 'done' => 'maybe', 'note' => 'has _ underscore',
        ], '2026-01-04 08:00:00');
        $this->rows['eps'] = $this->grid->row($this->tableId, $this->ownerOne, [
            'title' => 'ünïcode', 'price' => 'abc', 'price_text' => 'abc', 'born' => '2020-02-30', 'note' => 'plain',
        ], '2026-01-05 08:00:00');
        $this->rows['gone'] = $this->grid->row($this->tableId, $this->ownerOne, [
            'title' => 'Alpha gone', 'price' => '1', 'price_text' => '1',
        ], '2026-01-06 08:00:00', 'deleted');
        $this->rows['blank'] = $this->grid->row($this->tableId, $this->ownerOne, [], '2026-01-07 08:00:00');
        $this->rows['twin'] = $this->grid->row($this->tableId, $this->ownerTwo, [
            'title' => 'beta', 'price' => '10', 'price_text' => '10',
        ], '2026-01-02 09:00:00');
    }

    /**
     * @param list<DataSort> $sorts
     * @param list<string>|null $columns
     */
    private function query(
        DataFilter|DataFilterGroup|null $filter = null,
        ?DataSearch $search = null,
        array $sorts = [],
        ?int $limit = null,
        int $offset = 0,
        ?array $columns = null,
        string $ownerScope = DataQuery::OWNER_ANY,
        ?int $userId = null,
        bool $excludeDeleted = true,
        string $timezone = 'UTC',
        bool $withTotal = false,
    ): DataQuery {
        if ($filter instanceof DataFilter) {
            $filter = new DataFilterGroup(DataFilterGroup::MODE_AND, [$filter]);
        }

        return new DataQuery(
            tableId: $this->tableId,
            ownerScope: $ownerScope,
            userId: $userId,
            filter: $filter ?? new DataFilterGroup(),
            search: $search,
            sorts: $sorts,
            limit: $limit,
            offset: $offset,
            columns: $columns,
            excludeDeleted: $excludeDeleted,
            timezone: $timezone,
            withTotal: $withTotal,
        );
    }

    /**
     * A table of datetime texts that mean the same instant in different spellings, later and earlier ones, and texts
     * that are not a time at all. Record ids follow the order of the list, so equal instants sort in that order.
     *
     * @return array{table: int, rows: array<string, int>}
     */
    private function instantTable(): array
    {
        $table = $this->grid->table('qa_fl_at_' . bin2hex(random_bytes(4)), ['at' => 'datetime']);
        $texts = [
            'earlier' => '2026-11-14T09:59:59+01:00',
            'utc_clock' => '2026-11-14 09:00:00',
            'z' => '2026-11-14T09:00:00Z',
            'plus_one' => '2026-11-14T10:00:00+01:00',
            'plus_one_compact' => '2026-11-14T10:00:00+0100',
            'minus_five' => '2026-11-14T04:00:00-05:00',
            'fraction' => '2026-11-14 09:00:00.999',
            'later' => '2026-11-14 09:00:01',
            'zone_less' => '2026-11-14 10:00:00',
            'bad_clock' => '2026-11-14 99:99:99',
            'bad_offset' => '2026-11-14T10:00:00+99:99',
            'bad_day' => '2026-02-30 10:00',
            'text' => 'soon',
            'empty' => '',
        ];
        $rows = [];
        foreach ($texts as $name => $text) {
            $rows[$name] = $this->grid->row($table, $this->ownerOne, ['at' => $text]);
        }

        return ['table' => $table, 'rows' => $rows];
    }

    /**
     * The names of the rows of an instant table a filter keeps, in result order.
     *
     * @param array{table: int, rows: array<string, int>} $at
     * @param list<DataSort> $sorts
     * @return list<string>
     */
    private function instantNames(array $at, DataFilter|DataFilterGroup|null $filter, array $sorts = []): array
    {
        $this->tableId = $at['table'];
        $byId = array_flip($at['rows']);
        $names = [];
        foreach ($this->engine->run($this->query(filter: $filter, sorts: $sorts))->rows as $row) {
            $name = $byId[self::coerceInt($row['record_id'])] ?? null;
            self::assertIsString($name, 'The row is not one of the rows of the instant table.');
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Rows as the legacy stored procedure `get_data_table_filtered` returns them:
     * the oracle that the engine is compared with.
     *
     * @return list<array<string, mixed>>
     */
    private function storedProcedureRows(int $tableId, int $userId, string $filter): array
    {
        return $this->db->executeQuery(
            'CALL get_data_table_filtered(:t, :u, :f, 1, 1, :tz, :cols)',
            ['t' => $tableId, 'u' => $userId, 'f' => $this->service(DataTableFilterService::class)->glueLeadingAnd($filter), 'tz' => 'UTC', 'cols' => ''],
        )->fetchAllAssociative();
    }

    /**
     * Run the standard table with one filter and sort keys.
     *
     * @param list<DataSort> $sorts
     */
    private function read(DataFilter|DataFilterGroup|null $filter = null, array $sorts = []): DataQueryResult
    {
        return $this->engine->run($this->query(filter: $filter, sorts: $sorts));
    }

    /**
     * Record names of a result, in result order.
     *
     * @return list<string>
     */
    private function names(DataQueryResult $result): array
    {
        $names = [];
        foreach ($result->rows as $row) {
            $name = array_search(self::coerceInt($row['record_id']), $this->rows, true);
            self::assertIsString($name, 'Result row is not one of the seeded records.');
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(DataQueryResult $result, string $name): array
    {
        foreach ($result->rows as $row) {
            if (self::coerceInt($row['record_id']) === $this->rows[$name]) {
                return $row;
            }
        }
        self::fail(sprintf('Record "%s" is not in the result.', $name));
    }

    private function cell(DataQueryResult $result, int $recordId, string $column): mixed
    {
        foreach ($result->rows as $row) {
            if (self::coerceInt($row['record_id']) === $recordId) {
                return $row[$column] ?? null;
            }
        }
        self::fail(sprintf('Record %d is not in the result.', $recordId));
    }

    /**
     * A table with one text column in several languages.
     *
     * @return array{0: int, 1: array{both: int, default: int, english: int}}
     */
    private function languageTable(): array
    {
        $tableId = $this->grid->table('qa_fl_lang_' . bin2hex(random_bytes(4)), ['label' => 'text']);

        return [$tableId, [
            'both' => $this->grid->row($tableId, $this->ownerOne, ['label' => [1 => 'zzz', 3 => 'aaa']]),
            'default' => $this->grid->row($tableId, $this->ownerOne, ['label' => 'only-default']),
            'english' => $this->grid->row($tableId, $this->ownerOne, ['label' => [3 => 'only-english']]),
        ]];
    }

    /**
     * @param list<array<string, mixed>> $legacy
     * @return array<string, mixed>
     */
    private function legacyRowFor(array $legacy, int $recordId): array
    {
        foreach ($legacy as $row) {
            if (self::coerceInt($row['record_id']) === $recordId) {
                return $row;
            }
        }
        self::fail(sprintf('The stored procedure did not return record %d.', $recordId));
    }

    /**
     * @param callable(): mixed $action
     */
    private function expectError(callable $action, string $messagePart): void
    {
        try {
            $action();
        } catch (DataQueryException $exception) {
            self::assertStringContainsString($messagePart, $exception->getMessage());
            self::assertSame(400, $exception->getCode());

            return;
        }
        self::fail(sprintf('Expected a DataQueryException containing "%s".', $messagePart));
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, $email . ' must be seeded. Run: composer test:reset-db');

        return (int) $user->getId();
    }

    private function userName(int $userId): string
    {
        return self::coerceString($this->db->fetchOne('SELECT `name` FROM users WHERE id = :id', ['id' => $userId]));
    }

    private function tableIdByName(string $name): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT id FROM data_tables WHERE `name` = :n', ['n' => $name]));
    }
}
