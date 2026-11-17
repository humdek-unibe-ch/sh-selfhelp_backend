<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\DataQuery;

use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\DataQuery\LegacyFilterTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The author's SQL filter fragment becomes a structured filter. These tests pin
 * what is read, what each part means, and what is refused (with a message that
 * names the offending part so the author can fix it when saving).
 */
#[Group('form-list')]
final class LegacyFilterTranslatorTest extends TestCase
{
    private LegacyFilterTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new LegacyFilterTranslator();
    }

    public function testAnEmptyFragmentIsNoFilter(): void
    {
        $result = $this->translator->translate('   ');

        self::assertTrue($result->filter->isEmpty());
        self::assertSame([], $result->sorts);
        self::assertNull($result->limit);
        self::assertSame(0, $result->offset);
    }

    public function testALeadingAndIsOptional(): void
    {
        self::assertEquals($this->translator->translate("status = 'open'")->filter, $this->translator->translate("AND status = 'open'")->filter);
    }

    public function testComparisonsMapToOperators(): void
    {
        $cases = [
            '=' => DataFilter::OP_EQ,
            '!=' => DataFilter::OP_NEQ,
            '<>' => DataFilter::OP_NEQ,
            '<' => DataFilter::OP_LT,
            '<=' => DataFilter::OP_LTE,
            '>' => DataFilter::OP_GT,
            '>=' => DataFilter::OP_GTE,
        ];
        foreach ($cases as $symbol => $operator) {
            $filter = $this->single("AND section_5 $symbol 'x'");
            self::assertSame($operator, $filter->operator, $symbol);
            self::assertSame('section_5', $filter->column);
            self::assertSame('x', $filter->value);
        }
    }

    public function testANumberWithoutQuotesComparesAsANumberAndQuotedTextFollowsTheColumn(): void
    {
        self::assertSame('number', $this->single('age > 18')->valueType);
        self::assertSame('18', $this->single('age > 18')->value);
        self::assertNull($this->single("age > '18'")->valueType, 'A quoted value follows the declared type of the column.');
        self::assertSame('number', $this->single("record_id = '38'")->valueType, 'Row ids always compare as numbers.');
        self::assertSame('datetime', $this->single("entry_date >= '2026-01-01'")->valueType);
    }

    public function testQuotedTextKeepsEscapedQuotesAndBackticksQuoteIdentifiers(): void
    {
        self::assertSame("O'Brien", $this->single("name = 'O''Brien'")->value);
        self::assertSame("O'Brien", $this->single("name = 'O\\'Brien'")->value);
        self::assertSame('x', $this->single('name = "x"')->value);
        self::assertSame('section_7', $this->single("`section_7` = 'x'")->column);
        self::assertSame('record_id', $this->single('r.record_id = 4')->column, 'The legacy derived-table alias is accepted.');
    }

    public function testInNotInAndBetween(): void
    {
        $in = $this->single("name IN ('a', 'b', 'c')");
        self::assertSame(DataFilter::OP_IN, $in->operator);
        self::assertSame(['a', 'b', 'c'], $in->value);

        $notIn = $this->single('age NOT IN (1, 2)');
        self::assertSame(DataFilter::OP_NOT_IN, $notIn->operator);
        self::assertSame('number', $notIn->valueType);

        $between = $this->single('age BETWEEN 18 AND 65');
        self::assertSame(DataFilter::OP_BETWEEN, $between->operator);
        self::assertSame(['18', '65'], $between->value);
    }

    public function testIsNullAndIsNotNullAreReadAsEmptyAndNotEmpty(): void
    {
        self::assertSame(DataFilter::OP_EMPTY, $this->single('note IS NULL')->operator);
        self::assertSame(DataFilter::OP_NOT_EMPTY, $this->single('note IS NOT NULL')->operator);
    }

    #[DataProvider('likePatterns')]
    public function testLikeWithOuterWildcardsBecomesATextOperator(string $pattern, string $operator, string $value): void
    {
        $filter = $this->single("name LIKE '$pattern'");
        self::assertSame($operator, $filter->operator);
        self::assertSame($value, $filter->value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function likePatterns(): array
    {
        return [
            'starts with' => ['abc%', DataFilter::OP_STARTS_WITH, 'abc'],
            'ends with' => ['%abc', DataFilter::OP_ENDS_WITH, 'abc'],
            'contains' => ['%abc%', DataFilter::OP_CONTAINS, 'abc'],
            'no wildcard is equality' => ['abc', DataFilter::OP_EQ, 'abc'],
            'escaped percent is literal' => ['100\\%%', DataFilter::OP_STARTS_WITH, '100%'],
        ];
    }

    public function testAndBindsTighterThanOrAndParenthesesGroup(): void
    {
        $result = $this->translator->translate("AND a = 1 OR b = 2 AND c = 3");
        self::assertSame(DataFilterGroup::MODE_OR, $result->filter->mode);
        self::assertCount(2, $result->filter->children);
        self::assertInstanceOf(DataFilter::class, $result->filter->children[0]);
        self::assertInstanceOf(DataFilterGroup::class, $result->filter->children[1]);
        self::assertSame(DataFilterGroup::MODE_AND, $result->filter->children[1]->mode);

        $grouped = $this->translator->translate("AND (a = 1 OR b = 2) AND c = 3");
        self::assertSame(DataFilterGroup::MODE_AND, $grouped->filter->mode);
        self::assertInstanceOf(DataFilterGroup::class, $grouped->filter->children[0]);
        self::assertSame(DataFilterGroup::MODE_OR, $grouped->filter->children[0]->mode);
    }

    public function testOrderByAndLimit(): void
    {
        $result = $this->translator->translate('ORDER BY age DESC, name LIMIT 10 OFFSET 5');
        self::assertTrue($result->filter->isEmpty());
        self::assertEquals([new DataSort('age', DataSort::DESC), new DataSort('name', DataSort::ASC)], $result->sorts);
        self::assertSame(10, $result->limit);
        self::assertSame(5, $result->offset);

        $mysqlStyle = $this->translator->translate("AND status = 'open' ORDER BY record_id DESC LIMIT 3, 7");
        self::assertSame(7, $mysqlStyle->limit);
        self::assertSame(3, $mysqlStyle->offset);
        self::assertFalse($mysqlStyle->filter->isEmpty());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedFragments(): array
    {
        return [
            'function' => ["AND LOWER(name) = 'x'", 'function "LOWER()"'],
            'sub query' => ["AND id IN (SELECT id FROM users)", 'Expected a value'],
            'arithmetic' => ['AND age + 1 > 5', 'The character "+" is not supported'],
            'group by' => ['AND a = 1 GROUP BY a', 'Unexpected "GROUP"'],
            'not like' => ["AND name NOT LIKE '%x%'", 'NOT LIKE'],
            'inner wildcard' => ["AND name LIKE 'a%b'", 'wildcard'],
            'underscore wildcard' => ["AND name LIKE 'a_b'", 'wildcard'],
            'leading or' => ["OR a = 1", 'cannot start with OR'],
            'unclosed quote' => ["AND a = 'x", 'quote is not closed'],
            'unclosed backtick' => ["AND `a = 'x'", 'backtick is not closed'],
            'null equality' => ['AND a = NULL', 'Expected a value'],
            'comparison with a column' => ['AND a = b', 'Expected a value'],
            'semicolon' => ["AND a = 1; DROP TABLE x", 'not supported'],
            'comment' => ["AND a = 1 -- x", 'The character "-" is not supported'],
            'text for a numeric row field' => ["AND record_id = 'abc'", 'compared with a number'],
            'mixed value types in a list' => ["AND a IN (1, 'x')", 'all be numbers or all be text'],
            'order by function' => ['ORDER BY RAND()', 'function "RAND()"'],
            'limit with text' => ['LIMIT x', 'Expected a value, found "x"'],
        ];
    }

    #[DataProvider('refusedFragments')]
    public function testUnsupportedPartsAreRefusedWithTheirName(string $fragment, string $messagePart): void
    {
        try {
            $this->translator->translate($fragment);
        } catch (DataQueryException $exception) {
            self::assertStringContainsString($messagePart, $exception->getMessage());
            self::assertStringContainsString('Rewrite it', $exception->getMessage());
            self::assertSame(400, $exception->getCode());

            return;
        }
        self::fail('The fragment must be refused: ' . $fragment);
    }

    public function testBoundsOfTheStructuredFilterApplyToFragmentsToo(): void
    {
        $many = 'AND ' . implode(' AND ', array_fill(0, 31, 'a = 1'));
        $this->expectException(DataQueryException::class);
        $this->expectExceptionMessage('At most 30 conditions');
        $this->translator->translate($many);
    }

    public function testNestingIsBounded(): void
    {
        $this->expectException(DataQueryException::class);
        $this->expectExceptionMessage('nested at most 3');
        $this->translator->translate('AND (((((a = 1)))))');
    }

    public function testTheTranslatorCanBeReusedAfterAFailure(): void
    {
        try {
            $this->translator->translate('AND LOWER(a) = 1');
            self::fail('must be refused');
        } catch (DataQueryException) {
            // expected
        }

        self::assertSame('a', $this->single('AND a = 1')->column);
    }

    private function single(string $fragment): DataFilter
    {
        $result = $this->translator->translate($fragment);
        self::assertCount(1, $result->filter->children, 'Expected exactly one condition for: ' . $fragment);
        $child = $result->filter->children[0];
        self::assertInstanceOf(DataFilter::class, $child);

        return $child;
    }
}
