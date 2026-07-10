<?php



/*

 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern

 * SPDX-License-Identifier: MPL-2.0

 */



declare(strict_types=1);



namespace App\Tests\Unit\Service\CMS\Common;



use App\Service\CMS\Common\DataTableFilterService;

use App\Service\Core\InterpolationService;

use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\TestCase;



final class DataTableFilterServiceTest extends TestCase

{

    private DataTableFilterService $service;



    protected function setUp(): void

    {

        $this->service = new DataTableFilterService(new InterpolationService());

    }



    public function testValidateRecordIdRejectsInjection(): void

    {

        self::assertNull($this->service->validateRecordId('1 OR 1=1'));

        self::assertNull($this->service->validateRecordId("1'; DROP TABLE data_rows; --"));

        self::assertNull($this->service->validateRecordId("1' OR '1'='1"));

        self::assertSame(42, $this->service->validateRecordId('42'));

        self::assertSame(42, $this->service->validateRecordId(42));

        self::assertNull($this->service->validateRecordId(0));

        self::assertNull($this->service->validateRecordId('-1'));

    }



    public function testFilterConstrainsRecordIdRequiresLiteralMatch(): void
    {
        self::assertTrue($this->service->filterConstrainsRecordId('AND record_id = 7', 7));
        self::assertTrue($this->service->filterConstrainsRecordId('AND status = 1 AND record_id = 9', 9));
        self::assertFalse($this->service->filterConstrainsRecordId('AND status = 1', 9));
        self::assertFalse($this->service->filterConstrainsRecordId('AND record_id = 8', 9));
        self::assertFalse($this->service->filterConstrainsRecordId('', 7));
        self::assertFalse($this->service->filterConstrainsRecordId('AND record_id = 7', 0));
    }



    public function testPrepareFilterInterpolatesRouteRecordIdAsInteger(): void

    {

        $filter = $this->service->prepareFilter(

            'record_id = {{route.record_id}}',

            ['route' => ['record_id' => '15']],

        );



        self::assertSame('AND record_id = 15', $filter);

    }



    public function testPrepareFilterInterpolatesStringRouteTokenWithQuotes(): void

    {

        $filter = $this->service->prepareFilter(

            "status = {{route.category}}",

            ['route' => ['category' => 'release']],

            ['category' => '[a-z]+'],

        );



        self::assertSame("AND status = 'release'", $filter);

    }



    public function testPrepareFilterRejectsStringRouteTokenWithEmbeddedQuote(): void

    {

        $filter = $this->service->prepareFilter(

            "status = {{route.category}}",

            ['route' => ['category' => "x' OR '1'='1"]],

        );



        self::assertSame('', $filter);

    }



    public function testPrepareFilterRejectsMaliciousRouteRecordId(): void

    {

        $filter = $this->service->prepareFilter(

            'record_id = {{route.record_id}}',

            ['route' => ['record_id' => '1 OR 1=1']],

        );



        self::assertSame('', $filter);

    }



    public function testPrepareFilterRejectsUnsubstitutedRouteToken(): void

    {

        $filter = $this->service->prepareFilter(

            'record_id = {{route.record_id}}',

            [],

        );



        self::assertSame('', $filter);

    }



    public function testPrepareFilterRejectsOverLengthFilter(): void

    {

        $raw = 'status = ' . str_repeat('a', DataTableFilterService::MAX_FILTER_LENGTH);

        self::assertSame('', $this->service->prepareFilter($raw, []));

    }



    public function testGuardForStoredProcedureRejectsUnresolvedTokensAndOverLength(): void

    {

        self::assertSame('', $this->service->guardForStoredProcedure('AND x = {{route.record_id}}'));

        self::assertSame('', $this->service->guardForStoredProcedure(str_repeat('A', DataTableFilterService::MAX_FILTER_LENGTH + 1)));

        self::assertSame('AND status = 1', $this->service->guardForStoredProcedure('AND status = 1'));

    }



    #[DataProvider('unsafeFilterProvider')]

    public function testPrepareFilterRejectsUnsafeSql(string $raw): void

    {

        self::assertSame('', $this->service->prepareFilter($raw, []));

        self::assertFalse($this->service->isSafeFilterFragment($raw));

    }



    /**

     * @return list<array{string}>

     */

    public static function unsafeFilterProvider(): array

    {

        return [

            ["1; DROP TABLE data_rows"],

            ["'; DELETE FROM data_rows WHERE '1'='1"],

            ["1' OR '1'='1"],

            ["1 UNION SELECT 1"],

            ["1 -- comment"],

            ["DELETE FROM data_rows"],

            ["/* comment */ status = 1"],

            ["status = 1; UPDATE data_rows SET id_users = 0"],

        ];

    }



    public function testPrepareFilterAcceptsLegitimateEqualityFilter(): void

    {

        self::assertSame(

            "AND qa_answer = 'Ada'",

            $this->service->prepareFilter("qa_answer = 'Ada'", []),

        );

    }



    public function testBuildStringEqualityPredicateEscapesQuotes(): void

    {

        self::assertSame(

            " AND title = 'O''Reilly'",

            $this->service->buildStringEqualityPredicate('title', "O'Reilly"),

        );

    }



    public function testBuildStringEqualityPredicateRejectsInvalidColumn(): void

    {

        self::assertSame('', $this->service->buildStringEqualityPredicate("x; DROP", 'value'));

        self::assertSame('', $this->service->buildStringEqualityPredicate('', 'value'));

    }



    public function testGlueLeadingAndIsIdempotentForPrefixedFilters(): void

    {

        self::assertSame('AND status = 1', $this->service->glueLeadingAnd('AND status = 1'));

        self::assertSame('AND status = 1', $this->service->glueLeadingAnd('status = 1'));

    }



    public function testSanitizeSelectedColumnsAcceptsFieldKeys(): void

    {

        self::assertSame('section_42,section_99', $this->service->sanitizeSelectedColumns('section_42, section_99'));

    }



    public function testSanitizeSelectedColumnsRejectsUnsafeTokens(): void

    {

        self::assertSame('', $this->service->sanitizeSelectedColumns("section_1; DROP"));

        self::assertSame('', $this->service->sanitizeSelectedColumns(str_repeat('a', DataTableFilterService::MAX_SELECTED_COLUMNS_LENGTH + 1)));

    }

}

