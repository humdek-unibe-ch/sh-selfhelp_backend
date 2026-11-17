<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\FormList;

use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\FormList\FormListColumn;
use App\Service\CMS\FormList\FormListConfig;
use App\Service\CMS\FormList\FormListControl;
use App\Service\CMS\FormList\FormListStateException;
use App\Service\CMS\FormList\FormListUrlState;
use App\Service\CMS\FormList\FormListUrlStateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The URL grammar of a list: `<key>.page|q|sort|f.<id>|record|mode`. The parser
 * is strict so a bookmarked or hand-edited URL never means something the
 * visitor did not ask for; these cases pin every refusal and the round trip.
 */
#[Group('form-list')]
final class FormListUrlStateParserTest extends TestCase
{
    private FormListUrlStateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FormListUrlStateParser();
    }

    public function testAnEmptyQueryIsTheListItself(): void
    {
        $state = $this->parser->parse('', $this->config());

        self::assertSame(1, $state->page);
        self::assertNull($state->search);
        self::assertSame([], $state->sorts);
        self::assertSame([], $state->filters);
        self::assertNull($state->recordId);
        self::assertNull($state->mode);
        self::assertSame([], $state->toQuery('tasks'));
    }

    public function testEverythingAVisitorCanChooseRoundTripsThroughTheUrl(): void
    {
        $query = 'tasks.page=3&tasks.q=write+report&tasks.sort=title%3Aasc%2Centry_date%3Adesc&tasks.f.state=open&tasks.record=17&tasks.mode=edit';
        $state = $this->parser->parse($query, $this->config());

        self::assertSame(3, $state->page);
        self::assertSame('write report', $state->search);
        self::assertEquals([new DataSort('title', DataSort::ASC), new DataSort('entry_date', DataSort::DESC)], $state->sorts);
        self::assertSame(['state' => 'open'], $state->filters);
        self::assertSame(17, $state->recordId);
        self::assertSame(FormListUrlState::MODE_EDIT, $state->mode);

        $written = $state->toQuery('tasks');
        self::assertSame(
            ['tasks.page' => '3', 'tasks.q' => 'write report', 'tasks.sort' => 'title:asc,entry_date:desc', 'tasks.f.state' => 'open', 'tasks.record' => '17', 'tasks.mode' => 'edit'],
            $written,
        );
        $again = $this->parser->parse(http_build_query($written, '', '&', PHP_QUERY_RFC3986), $this->config());
        self::assertEquals($state, $again, 'What the server echoes parses back to the same state.');
    }

    public function testParametersOfOtherListsAndOfThePageAreNotThisListsBusiness(): void
    {
        $state = $this->parser->parse('page_id=5&lang=de&other.page=9&other.f.x=1&tasks.page=2&utm_source=mail', $this->config());

        self::assertSame(2, $state->page);
    }

    public function testADotInTheNameMayBeEncoded(): void
    {
        self::assertSame(4, $this->parser->parse('tasks%2Epage=4', $this->config())->page);
    }

    public function testARecordWithoutAModeIsViewed(): void
    {
        self::assertSame(FormListUrlState::MODE_VIEW, $this->parser->parse('tasks.record=8', $this->config())->mode);
    }

    public function testAnEmptyFilterValueMeansNoFilterAndABlankSearchMeansNoSearch(): void
    {
        $state = $this->parser->parse('tasks.f.state=&tasks.q=%20%20', $this->config());

        self::assertSame([], $state->filters);
        self::assertNull($state->search);
    }

    public function testCreateIsAModeOfItsOwn(): void
    {
        $state = $this->parser->parse('tasks.mode=create', $this->config());

        self::assertSame(FormListUrlState::MODE_CREATE, $state->mode);
        self::assertNull($state->recordId);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'page given twice' => ['tasks.page=1&tasks.page=2', 'duplicate_list_parameter', 400];
        yield 'page zero' => ['tasks.page=0', 'invalid_list_parameter', 400];
        yield 'page negative' => ['tasks.page=-1', 'invalid_list_parameter', 400];
        yield 'page not a number' => ['tasks.page=two', 'invalid_list_parameter', 400];
        yield 'page with a leading zero' => ['tasks.page=02', 'invalid_list_parameter', 400];
        yield 'page beyond the limit' => ['tasks.page=1000001', 'invalid_list_parameter', 400];
        yield 'page written as an array' => ['tasks.page[]=1', 'unknown_list_parameter', 400];
        yield 'unknown parameter' => ['tasks.limit=500', 'unknown_list_parameter', 400];
        yield 'unknown filter' => ['tasks.f.secret=1', 'unknown_list_parameter', 400];
        yield 'search too short' => ['tasks.q=a', 'invalid_list_parameter', 400];
        yield 'search too long' => ['tasks.q=' . '', 'invalid_list_parameter', 400];
        yield 'sort of a column that is not sortable' => ['tasks.sort=status:asc', 'invalid_list_parameter', 400];
        yield 'sort without a direction' => ['tasks.sort=title', 'invalid_list_parameter', 400];
        yield 'sort with a bad direction' => ['tasks.sort=title:up', 'invalid_list_parameter', 400];
        yield 'sort twice on a column' => ['tasks.sort=title:asc,title:desc', 'invalid_list_parameter', 400];
        yield 'too many sort keys' => ['tasks.sort=title:asc,entry_date:desc,title:desc,x:asc', 'invalid_list_parameter', 400];
        yield 'record not a number' => ['tasks.record=abc', 'invalid_list_parameter', 400];
        yield 'unknown mode' => ['tasks.record=1&tasks.mode=delete', 'invalid_list_parameter', 400];
        yield 'create with a record' => ['tasks.record=1&tasks.mode=create', 'invalid_list_parameter', 400];
        yield 'edit without a record' => ['tasks.mode=edit', 'invalid_list_parameter', 400];
        yield 'view without a record' => ['tasks.mode=view', 'invalid_list_parameter', 400];
        yield 'value over the length limit' => ['tasks.f.state=' . '', 'invalid_list_parameter', 400];
    }

    #[DataProvider('refusedQueries')]
    public function testAMalformedOrUnknownParameterIsRefusedNotIgnored(string $query, string $reason, int $status): void
    {
        $query = match ($query) {
            'tasks.q=' => 'tasks.q=' . str_repeat('x', 101),
            'tasks.f.state=' => 'tasks.f.state=' . str_repeat('x', 1001),
            default => $query,
        };

        try {
            $this->parser->parse($query, $this->config());
            self::fail('The query "' . substr($query, 0, 60) . '" should have been refused.');
        } catch (FormListStateException $e) {
            self::assertSame($status, $e->getCode());
            self::assertSame($reason, $e->getData()['reason'] ?? null);
            self::assertArrayHasKey('parameter', $e->getData(), 'The error names the parameter so a client can drop it.');
        }
    }

    public function testAModeTheListDoesNotOfferIsForbiddenNotMalformed(): void
    {
        $readOnly = $this->config(allowCreate: false, allowUpdate: false);

        foreach (['tasks.mode=create', 'tasks.record=1&tasks.mode=edit'] as $query) {
            try {
                $this->parser->parse($query, $readOnly);
                self::fail('The mode should be refused.');
            } catch (FormListStateException $e) {
                self::assertSame(403, $e->getCode());
                self::assertSame('operation_disabled', $e->getData()['reason'] ?? null);
            }
        }

        self::assertSame(FormListUrlState::MODE_VIEW, $this->parser->parse('tasks.record=1&tasks.mode=view', $readOnly)->mode, 'Looking is always allowed.');
    }

    public function testHistoryModeOpensARecordOnTheHistoryTab(): void
    {
        $state = $this->parser->parse('tasks.record=8&tasks.mode=history', $this->config(showHistory: true));

        self::assertSame(FormListUrlState::MODE_HISTORY, $state->mode);
        self::assertSame(8, $state->recordId);
    }

    public function testHistoryModeIsForbiddenWhenTheListDoesNotOfferHistory(): void
    {
        try {
            $this->parser->parse('tasks.record=1&tasks.mode=history', $this->config(showHistory: false));
            self::fail('History should be refused when show_history is off.');
        } catch (FormListStateException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame('history_disabled', $e->getData()['reason'] ?? null);
        }
    }

    public function testAnotherPageKeepsEverythingElse(): void
    {
        $state = $this->parser->parse('tasks.q=anna&tasks.f.state=open', $this->config())->onPage(4);

        self::assertSame(4, $state->page);
        self::assertSame('anna', $state->search);
        self::assertSame(['state' => 'open'], $state->filters);
    }

    // -- helpers ----------------------------------------------------------------

    private function config(bool $allowCreate = true, bool $allowUpdate = true, bool $showHistory = false): FormListConfig
    {
        return new FormListConfig(
            sectionId: 1,
            style: StyleNames::STYLE_ENTRY_FORM_LIST,
            key: 'tasks',
            dataTableId: null,
            ownEntriesOnly: true,
            allowCreate: $allowCreate,
            allowUpdate: $allowUpdate,
            allowDelete: true,
            layout: FormListConfig::LAYOUT_TABLE,
            formPresentation: FormListConfig::PRESENTATION_MODAL,
            pageSize: 25,
            columns: [
                new FormListColumn('title', 'Title', true, true, FormListColumn::FORMAT_TEXT),
                new FormListColumn('status', 'Status', false, false, FormListColumn::FORMAT_BADGE),
                new FormListColumn('entry_date', 'Date', true, false, FormListColumn::FORMAT_DATETIME),
            ],
            defaultSorts: [],
            controls: [new FormListControl('state', FormListControl::TYPE_SELECT, 'status', 'State', null, true, 'contains')],
            fixedFilter: new DataFilterGroup(),
            quickActions: [],
            showHistory: $showHistory,
            detailUrl: null,
            labels: [],
        );
    }
}
