<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\CMS\DataQuery\AggregateSpec;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * C17: `DataQueryEngine::aggregate()` groups the records a query matches and computes counts, sums,
 * averages, minimums and maximums per group of up to two columns and per calendar period of a date
 * column. The records are the ones `run()` would read (table, owner scope, deleted records, fixed filter),
 * the answer is typed values, and a spec that does not fit the table, or data with more groups than
 * `maxGroups`, is refused.
 *
 * The records are written straight into the generic store ({@see DataGridFactory}) so each rule is checked on the
 * shapes that occur: cells that are missing, empty or unreadable as their type, and a deleted record.
 *
 * | record | owner | city | kind | score | day | seen | ok |
 * | --- | --- | --- | --- | --- | --- | --- | --- |
 * | r1 | one | Bern | a | 10 | 2026-01-05 | 2026-01-05 09:00 | true |
 * | r2 | one | Bern | b | 20 | 2026-01-20 | 2026-01-20T12:00:00Z | false |
 * | r3 | two | Bern | a | 30 | 2026-02-03 | 2026-02-03 23:30 | yes |
 * | r4 | two | Zug | a | 5 | 2026-02-28 | 2026-02-28 00:00 | no |
 * | r5 | one | Zug | b | (empty) | 2026-03-01 | (none) | (empty) |
 * | r6 | two | (none) | a | 7.5 | 2027-01-01 | (none) | (none) |
 * | r7 | one | Bern | a | 1000 | 2026-01-06 | (none) | (none) | deleted
 * | r8 | two | Zug | a | abc | junk | (none) | (none) |
 *
 * @phpstan-type SpecArguments array{
 *   groupBy?: array<mixed>,
 *   dateBucket?: array<mixed>|null,
 *   metrics?: array<mixed>,
 *   maxGroups?: int,
 * }
 */
#[Group('form-list')]
final class DataQueryAggregateTest extends QaKernelTestCase
{
    private DataQueryEngine $engine;
    private Connection $db;
    private DataGridFactory $grid;
    private int $ownerOne;
    private int $ownerTwo;
    private int $tableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->service(DataQueryEngine::class);
        $this->db = $this->em->getConnection();
        $this->grid = new DataGridFactory($this->db);
        $this->ownerOne = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $this->ownerTwo = $this->userId(QaBaselineFixture::QA_EDITOR_EMAIL);

        $this->tableId = $this->grid->table('qa_c17_aggregate', [
            'city' => 'text',
            'kind' => 'text',
            'score' => 'number',
            'day' => 'date',
            'seen' => 'datetime',
            'ok' => 'boolean',
        ]);
        $this->grid->row($this->tableId, $this->ownerOne, ['city' => 'Bern', 'kind' => 'a', 'score' => '10', 'day' => '2026-01-05', 'seen' => '2026-01-05 09:00', 'ok' => 'true']);
        $this->grid->row($this->tableId, $this->ownerOne, ['city' => 'Bern', 'kind' => 'b', 'score' => '20', 'day' => '2026-01-20', 'seen' => '2026-01-20T12:00:00Z', 'ok' => 'false']);
        $this->grid->row($this->tableId, $this->ownerTwo, ['city' => 'Bern', 'kind' => 'a', 'score' => '30', 'day' => '2026-02-03', 'seen' => '2026-02-03 23:30', 'ok' => 'yes']);
        $this->grid->row($this->tableId, $this->ownerTwo, ['city' => 'Zug', 'kind' => 'a', 'score' => '5', 'day' => '2026-02-28', 'seen' => '2026-02-28 00:00', 'ok' => 'no']);
        $this->grid->row($this->tableId, $this->ownerOne, ['city' => 'Zug', 'kind' => 'b', 'score' => '', 'day' => '2026-03-01', 'ok' => '']);
        $this->grid->row($this->tableId, $this->ownerTwo, ['kind' => 'a', 'score' => '7.5', 'day' => '2027-01-01']);
        $this->grid->row($this->tableId, $this->ownerOne, ['city' => 'Bern', 'kind' => 'a', 'score' => '1000', 'day' => '2026-01-06'], null, 'deleted');
        $this->grid->row($this->tableId, $this->ownerTwo, ['city' => 'Zug', 'kind' => 'a', 'score' => 'abc', 'day' => 'junk']);
    }

    // -- groups by one and two columns --------------------------------------------------

    public function testCountsPerValueOfOneColumnWithMissingValuesLastAndDeletedRecordsLeftOut(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            groupBy: ['city'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));

        self::assertSame([
            ['city' => 'Bern', 'n' => 3],
            ['city' => 'Zug', 'n' => 3],
            ['city' => null, 'n' => 1],
        ], $rows, 'The deleted record r7 is not counted; the record without a city is a group of its own, last.');
    }

    public function testAllFiveFunctionsPerGroup(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            groupBy: ['city'],
            metrics: [
                ['fn' => 'count', 'as' => 'n'],
                ['fn' => 'count', 'column' => 'score', 'as' => 'scored'],
                ['fn' => 'sum', 'column' => 'score', 'as' => 'total'],
                ['fn' => 'avg', 'column' => 'score', 'as' => 'mean'],
                ['fn' => 'min', 'column' => 'score', 'as' => 'lowest'],
            ],
        ));

        self::assertSame([
            ['city' => 'Bern', 'n' => 3, 'scored' => 3, 'total' => 60, 'mean' => 20.0, 'lowest' => 10],
            // "abc" and the empty cell are no numbers: they count as records, not as scores.
            ['city' => 'Zug', 'n' => 3, 'scored' => 1, 'total' => 5, 'mean' => 5.0, 'lowest' => 5],
            ['city' => null, 'n' => 1, 'scored' => 1, 'total' => 7.5, 'mean' => 7.5, 'lowest' => 7.5],
        ], $rows);

        $max = $this->engine->aggregate($this->query(), new AggregateSpec(
            groupBy: ['kind'],
            metrics: [['fn' => 'max', 'column' => 'score', 'as' => 'highest']],
        ));
        self::assertSame([['kind' => 'a', 'highest' => 30], ['kind' => 'b', 'highest' => 20]], $max);
    }

    public function testTheTotalOfAGroupWithoutNumbersIsNull(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            groupBy: ['city', 'kind'],
            metrics: [['fn' => 'count', 'as' => 'n'], ['fn' => 'sum', 'column' => 'score', 'as' => 'total']],
        ));

        self::assertSame([
            ['city' => 'Bern', 'kind' => 'a', 'n' => 2, 'total' => 40],
            ['city' => 'Bern', 'kind' => 'b', 'n' => 1, 'total' => 20],
            ['city' => 'Zug', 'kind' => 'a', 'n' => 2, 'total' => 5],
            ['city' => 'Zug', 'kind' => 'b', 'n' => 1, 'total' => null],
            ['city' => null, 'kind' => 'a', 'n' => 1, 'total' => 7.5],
        ], $rows);
    }

    public function testWithoutAGroupTheAnswerIsOneRow(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(metrics: [
            ['fn' => 'count', 'as' => 'n'],
            ['fn' => 'sum', 'column' => 'score', 'as' => 'total'],
        ]));

        self::assertSame([['n' => 7, 'total' => 72.5]], $rows);
    }

    public function testABooleanColumnGroupsAsTrueAndFalse(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            groupBy: ['ok'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));

        // false: false, no, and the empty text; true: true, yes; no cell: unknown.
        self::assertSame([
            ['ok' => false, 'n' => 3],
            ['ok' => true, 'n' => 2],
            ['ok' => null, 'n' => 2],
        ], $rows);
    }

    // -- date buckets ----------------------------------------------------------------------

    public function testAMonthBucketOfADateColumn(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            dateBucket: ['column' => 'day', 'unit' => 'month'],
            metrics: [['fn' => 'count', 'as' => 'n'], ['fn' => 'sum', 'column' => 'score', 'as' => 'total']],
        ));

        // The record with the day "junk" belongs to no period.
        self::assertSame([
            ['day' => '2026-01-01', 'n' => 2, 'total' => 30],
            ['day' => '2026-02-01', 'n' => 2, 'total' => 35],
            ['day' => '2026-03-01', 'n' => 1, 'total' => null],
            ['day' => '2027-01-01', 'n' => 1, 'total' => 7.5],
        ], $rows);
    }

    public function testAWeekStartsOnMonday(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            dateBucket: ['column' => 'day', 'unit' => 'week'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));

        self::assertSame([
            ['day' => '2026-01-05', 'n' => 1],
            ['day' => '2026-01-19', 'n' => 1],
            ['day' => '2026-02-02', 'n' => 1],
            ['day' => '2026-02-23', 'n' => 2], // Saturday the 28th and Sunday March 1st
            ['day' => '2026-12-28', 'n' => 1],
        ], $rows);
    }

    public function testADayAndAYearBucket(): void
    {
        $days = $this->engine->aggregate($this->query(), new AggregateSpec(
            dateBucket: ['column' => 'day', 'unit' => 'day'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));
        self::assertSame(
            ['2026-01-05', '2026-01-20', '2026-02-03', '2026-02-28', '2026-03-01', '2027-01-01'],
            array_column($days, 'day'),
        );

        $years = $this->engine->aggregate($this->query(), new AggregateSpec(
            dateBucket: ['column' => 'day', 'unit' => 'year'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));
        self::assertSame([['day' => '2026-01-01', 'n' => 5], ['day' => '2027-01-01', 'n' => 1]], $years);
    }

    public function testADateTimeColumnIsBucketedByItsUtcDay(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            dateBucket: ['column' => 'seen', 'unit' => 'month'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));

        // Only four records have a time; 2026-02-03 23:30 stays in February.
        self::assertSame([['seen' => '2026-01-01', 'n' => 2], ['seen' => '2026-02-01', 'n' => 2]], $rows);
    }

    public function testAGroupColumnAndABucketTogether(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(
            groupBy: ['kind'],
            dateBucket: ['column' => 'day', 'unit' => 'month'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));

        self::assertSame([
            ['kind' => 'a', 'day' => '2026-01-01', 'n' => 1],
            ['kind' => 'a', 'day' => '2026-02-01', 'n' => 2],
            ['kind' => 'a', 'day' => '2027-01-01', 'n' => 1],
            ['kind' => 'b', 'day' => '2026-01-01', 'n' => 1],
            ['kind' => 'b', 'day' => '2026-03-01', 'n' => 1],
        ], $rows);
    }

    public function testTheEntryDateIsBucketedInTheTimeZoneOfTheQuery(): void
    {
        $table = $this->grid->table('qa_c17_entry_date', ['kind' => 'text']);
        $this->grid->row($table, $this->ownerOne, ['kind' => 'a'], '2026-01-31 23:30:00');
        $this->grid->row($table, $this->ownerOne, ['kind' => 'a'], '2026-02-10 12:00:00');
        $spec = new AggregateSpec(dateBucket: ['column' => 'entry_date', 'unit' => 'month'], metrics: [['fn' => 'count', 'as' => 'n']]);

        $utc = $this->engine->aggregate(new DataQuery($table), $spec);
        $shifted = $this->engine->aggregate(new DataQuery($table, timezone: '+01:00'), $spec);

        self::assertSame([['entry_date' => '2026-01-01', 'n' => 1], ['entry_date' => '2026-02-01', 'n' => 1]], $utc);
        self::assertSame([['entry_date' => '2026-02-01', 'n' => 2]], $shifted, '23:30 UTC on January 31st is February 1st one hour east.');
    }

    // -- minimum and maximum of dates ---------------------------------------------------------

    public function testMinimumAndMaximumOfADateAndOfADateTime(): void
    {
        $rows = $this->engine->aggregate($this->query(), new AggregateSpec(metrics: [
            ['fn' => 'min', 'column' => 'day', 'as' => 'first_day'],
            ['fn' => 'max', 'column' => 'day', 'as' => 'last_day'],
            ['fn' => 'min', 'column' => 'seen', 'as' => 'first_seen'],
            ['fn' => 'max', 'column' => 'seen', 'as' => 'last_seen'],
        ]));

        self::assertSame([[
            'first_day' => '2026-01-05',
            'last_day' => '2027-01-01',
            'first_seen' => '2026-01-05 09:00:00',
            'last_seen' => '2026-02-28 00:00:00',
        ]], $rows);
    }

    // -- which records ----------------------------------------------------------------------

    public function testTheOwnerScopeOfTheQueryApplies(): void
    {
        $spec = new AggregateSpec(groupBy: ['city'], metrics: [['fn' => 'count', 'as' => 'n'], ['fn' => 'sum', 'column' => 'score', 'as' => 'total']]);

        $own = $this->engine->aggregate($this->query(owner: DataQuery::OWNER_USER, userId: $this->ownerOne), $spec);
        self::assertSame([['city' => 'Bern', 'n' => 2, 'total' => 30], ['city' => 'Zug', 'n' => 1, 'total' => null]], $own);

        $anonymous = $this->engine->aggregate($this->query(owner: DataQuery::OWNER_USER, userId: null), $spec);
        self::assertSame([], $anonymous, 'Own-only reads without a signed-in user match nothing.');
    }

    public function testTheFixedFilterOfTheQueryApplies(): void
    {
        $filter = new DataFilterGroup(children: [new DataFilter('kind', DataFilter::OP_EQ, 'a')]);

        $rows = $this->engine->aggregate($this->query(filter: $filter), new AggregateSpec(
            groupBy: ['city'],
            metrics: [['fn' => 'count', 'as' => 'n']],
        ));

        self::assertSame([['city' => 'Bern', 'n' => 2], ['city' => 'Zug', 'n' => 2], ['city' => null, 'n' => 1]], $rows);
    }

    public function testDeletedRecordsAreCountedOnlyWhenTheQueryAsksForThem(): void
    {
        $rows = $this->engine->aggregate($this->query(excludeDeleted: false), new AggregateSpec(
            groupBy: ['city'],
            metrics: [['fn' => 'count', 'as' => 'n'], ['fn' => 'sum', 'column' => 'score', 'as' => 'total']],
        ));

        self::assertSame(['city' => 'Bern', 'n' => 4, 'total' => 1060], $rows[0]);
    }

    public function testNothingIsReadFromAnotherTable(): void
    {
        $other = $this->grid->table('qa_c17_other', ['city' => 'text']);
        $this->grid->row($other, $this->ownerOne, ['city' => 'Basel']);

        $rows = $this->engine->aggregate(new DataQuery($other), new AggregateSpec(groupBy: ['city'], metrics: [['fn' => 'count', 'as' => 'n']]));

        self::assertSame([['city' => 'Basel', 'n' => 1]], $rows);
    }

    // -- refused specs ------------------------------------------------------------------------

    /**
     * @return iterable<string, array{AggregateSpec}>
     */
    public static function specsThatDoNotFitTheTable(): iterable
    {
        yield 'sum of a text column' => [new AggregateSpec(metrics: [['fn' => 'sum', 'column' => 'city', 'as' => 'x']])];
        yield 'avg of a text column' => [new AggregateSpec(metrics: [['fn' => 'avg', 'column' => 'kind', 'as' => 'x']])];
        yield 'avg of a date column' => [new AggregateSpec(metrics: [['fn' => 'avg', 'column' => 'day', 'as' => 'x']])];
        yield 'sum of a boolean column' => [new AggregateSpec(metrics: [['fn' => 'sum', 'column' => 'ok', 'as' => 'x']])];
        yield 'min of a text column' => [new AggregateSpec(metrics: [['fn' => 'min', 'column' => 'city', 'as' => 'x']])];
        yield 'max of a boolean column' => [new AggregateSpec(metrics: [['fn' => 'max', 'column' => 'ok', 'as' => 'x']])];
        yield 'a bucket of a number column' => [new AggregateSpec(dateBucket: ['column' => 'score', 'unit' => 'month'], metrics: [['fn' => 'count', 'as' => 'n']])];
        yield 'a bucket of a text column' => [new AggregateSpec(dateBucket: ['column' => 'city', 'unit' => 'day'], metrics: [['fn' => 'count', 'as' => 'n']])];
        yield 'an unknown group column' => [new AggregateSpec(groupBy: ['nope'], metrics: [['fn' => 'count', 'as' => 'n']])];
        yield 'an unknown metric column' => [new AggregateSpec(metrics: [['fn' => 'sum', 'column' => 'nope', 'as' => 'x']])];
        yield 'an unknown bucket column' => [new AggregateSpec(dateBucket: ['column' => 'nope', 'unit' => 'day'], metrics: [['fn' => 'count', 'as' => 'n']])];
    }

    #[DataProvider('specsThatDoNotFitTheTable')]
    public function testASpecThatDoesNotFitTheTableIsRefused(AggregateSpec $spec): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->aggregate($this->query(), $spec);
    }

    public function testMoreGroupsThanMaxGroupsIsRefusedAndExactlyMaxGroupsIsNot(): void
    {
        // Three groups: Bern, Zug and the records without a city.
        $spec = static fn (int $max): AggregateSpec => new AggregateSpec(groupBy: ['city'], metrics: [['fn' => 'count', 'as' => 'n']], maxGroups: $max);

        self::assertCount(3, $this->engine->aggregate($this->query(), $spec(3)));

        try {
            $this->engine->aggregate($this->query(), $spec(2));
            self::fail('More groups than maxGroups must be refused.');
        } catch (DataQueryException $e) {
            self::assertSame(400, $e->getCode());
            self::assertSame(['max_groups' => 2], $e->getData());
        }
    }

    /**
     * @return iterable<string, array{SpecArguments}>
     */
    public static function specsThatAreInvalidOnTheirOwn(): iterable
    {
        yield 'maxGroups over the limit' => [['metrics' => [['fn' => 'count', 'as' => 'n']], 'maxGroups' => 2000]];
        yield 'maxGroups of zero' => [['metrics' => [['fn' => 'count', 'as' => 'n']], 'maxGroups' => 0]];
        yield 'three group columns' => [['groupBy' => ['a', 'b', 'c'], 'metrics' => [['fn' => 'count', 'as' => 'n']]]];
        yield 'the same group column twice' => [['groupBy' => ['a', 'a'], 'metrics' => [['fn' => 'count', 'as' => 'n']]]];
        yield 'no metric' => [['metrics' => []]];
        yield 'six metrics' => [['metrics' => array_map(static fn (int $i): array => ['fn' => 'count', 'as' => 'n' . $i], range(1, 6))]];
        yield 'an unknown function' => [['metrics' => [['fn' => 'median', 'column' => 'score', 'as' => 'n']]]];
        yield 'a sum without a column' => [['metrics' => [['fn' => 'sum', 'as' => 'n']]]];
        yield 'a name that is no identifier' => [['metrics' => [['fn' => 'count', 'as' => 'n; DROP TABLE users']]]];
        yield 'the same name twice' => [['metrics' => [['fn' => 'count', 'as' => 'n'], ['fn' => 'sum', 'column' => 'score', 'as' => 'n']]]];
        yield 'an unknown bucket unit' => [['dateBucket' => ['column' => 'day', 'unit' => 'decade'], 'metrics' => [['fn' => 'count', 'as' => 'n']]]];
        yield 'a column both grouped and bucketed' => [['groupBy' => ['day'], 'dateBucket' => ['column' => 'day', 'unit' => 'day'], 'metrics' => [['fn' => 'count', 'as' => 'n']]]];
    }

    /**
     * @param SpecArguments $arguments
     */
    #[DataProvider('specsThatAreInvalidOnTheirOwn')]
    public function testASpecThatIsInvalidOnItsOwnIsRefusedWhenItIsBuilt(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // The arguments are named like the constructor's; an invalid combination must not construct.
        new AggregateSpec(...$arguments);
    }

    public function testTheDefaultAndTheLargestMaxGroups(): void
    {
        $metrics = [['fn' => 'count', 'as' => 'n']];

        self::assertSame(200, (new AggregateSpec(metrics: $metrics))->maxGroups);
        self::assertSame(1000, (new AggregateSpec(metrics: $metrics, maxGroups: 1000))->maxGroups);
        self::assertSame(1, (new AggregateSpec(metrics: $metrics, maxGroups: 1))->maxGroups);
    }

    // -- helpers --------------------------------------------------------------------------------

    private function query(
        string $owner = DataQuery::OWNER_ANY,
        ?int $userId = null,
        ?DataFilterGroup $filter = null,
        bool $excludeDeleted = true,
    ): DataQuery {
        return new DataQuery(
            tableId: $this->tableId,
            ownerScope: $owner,
            userId: $userId,
            filter: $filter ?? new DataFilterGroup(),
            excludeDeleted: $excludeDeleted,
        );
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
