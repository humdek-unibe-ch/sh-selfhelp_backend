<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Section;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The server-rendered state of a list at `GET /lists/{section_id}/state`:
 * what a guest, a participant and an editor see, how the URL state (page, search,
 * sort, filters, open record) is served, and what is refused. The list is drawn by
 * the clients; every rule below is the server's.
 */
#[PhpUnitGroup('form-list')]
final class FormListStateTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testAPublicDataViewShowsOnlyTheConfiguredColumnsToGuests(): void
    {
        [$page, $view] = $this->dataView('qa_lv_public');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view));

        self::assertSame('data-view', $this->at($data, 'list')['style'] ?? null);
        self::assertSame(['title', 'city', 'seats', 'starts'], array_column($this->listOf($data, 'columns'), 'field'));
        self::assertSame(['Title', 'City', 'Seats', 'Starts'], array_column($this->listOf($data, 'columns'), 'label'));

        $pagination = $this->at($data, 'pagination');
        self::assertSame(15, $pagination['total'], 'The fixed filter keeps the 15 published events.');
        self::assertSame(25, $pagination['page_size']);
        self::assertSame(1, $pagination['total_pages']);

        $rows = $this->listOf($data, 'rows');
        self::assertCount(15, $rows);
        foreach ($rows as $row) {
            self::assertSame(['title', 'city', 'seats', 'starts'], array_keys($this->at($row, 'values')), 'Only the configured columns are sent, never the other cells.');
            self::assertFalse($row['can_update']);
            self::assertFalse($row['can_delete']);
            self::assertFalse($row['can_view_history']);
            self::assertFalse($row['is_own']);
            self::assertSame([], $row['quick_actions']);
            self::assertArrayNotHasKey('id_users', $row, 'The owner of a record is not part of the payload.');
        }
        self::assertFalse($this->at($data, 'capabilities')['create']);
        self::assertNull($data['selected']);

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('internal note', $encoded, 'A column that is not configured never leaves the server.');
    }

    public function testPagingSortingSearchAndFiltersAreServedByTheServer(): void
    {
        [$page, $view] = $this->dataView('qa_lv_query', ['page_size' => '5']);

        $first = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.sort=seats:desc'));
        self::assertSame(['Event 29', 'Event 27', 'Event 25', 'Event 23', 'Event 21'], $this->titles($first), 'Highest seats first.');
        self::assertSame(3, $this->at($first, 'pagination')['total_pages']);

        $third = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.sort=seats:desc&list.page=3'));
        self::assertSame(['Event 09', 'Event 07', 'Event 05', 'Event 03', 'Event 01'], $this->titles($third));

        $beyond = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.sort=seats:desc&list.page=99'));
        self::assertSame(3, $this->at($beyond, 'state')['page'], 'A page beyond the end shows the last page.');

        $search = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.q=bas&list.sort=seats:asc'));
        self::assertSame(['Basel'], $this->distinct($search, 'city'), 'The search is a substring over the searchable columns.');
        self::assertSame('bas', $this->at($search, 'state')['q']);

        $city = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.f.city=Zurich'));
        self::assertSame(['Zurich'], $this->distinct($city, 'city'));

        $range = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.f.seats=50..100&list.sort=seats:asc'));
        self::assertSame(['Event 11', 'Event 13', 'Event 15', 'Event 17', 'Event 19'], $this->titles($range));
        self::assertSame(5, $this->at($range, 'pagination')['total']);

        $dates = $this->assertEnvelopeSuccess($this->state($page, $view, 'list.f.starts=2026-01-03..2026-01-07&list.sort=starts:asc'));
        self::assertSame(['Event 03', 'Event 05', 'Event 07'], $this->titles($dates));
    }

    public function testControlsCarryTheirChoicesFromTheVisitorsDataset(): void
    {
        [$page, $view] = $this->dataView('qa_lv_controls');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view));
        $controls = [];
        foreach ($this->listOf($data, 'controls') as $control) {
            $controls[self::coerceString($control['id'])] = $control;
        }
        self::assertSame(['city', 'seats', 'starts'], array_keys($controls));
        $options = $this->listOf($controls['city'], 'options');
        $counts = [];
        foreach ($options as $option) {
            $counts[self::coerceString($option['value'])] = $option['count'];
        }
        ksort($counts);
        self::assertSame(['Basel' => 5, 'Bern' => 5, 'Zurich' => 5], $counts, 'Choices and counts are those of the published events only.');
    }

    public function testTheUrlStateIsStrictAndAnythingUnknownIsRefused(): void
    {
        [$page, $view] = $this->dataView('qa_lv_strict');

        foreach ([
            'list.page=0' => 400,
            'list.page=abc' => 400,
            'list.page=1&list.page=2' => 400,
            'list.sort=notacolumn:asc' => 400,
            'list.sort=seats:sideways' => 400,
            'list.sort=seats:asc,title:asc,city:asc,starts:asc' => 400,
            'list.q=a' => 400,
            'list.f.unknown=1' => 400,
            'list.bogus=1' => 400,
            'list.f.seats=abc' => 400,
            'list.f.seats=10' => 400,
            'list.mode=create' => 403,
            'list.mode=edit' => 400,
            'list.mode=view' => 400,
            'list.record=0' => 400,
            'list.page[]=1' => 400,
        ] as $query => $status) {
            $envelope = $this->state($page, $view, $query);
            self::assertSame($status, $envelope['status'] ?? null, 'Query "' . $query . '" must answer ' . $status . ': ' . json_encode($envelope['data'] ?? null));
        }

        // Parameters of other lists, and of the page itself, are not this list's business.
        $this->assertEnvelopeSuccess($this->state($page, $view, 'other.page=9&utm=x'));
    }

    public function testASectionThatIsNotAListOrNotOnThePageIsRefused(): void
    {
        [$page, $view] = $this->dataView('qa_lv_refuse');
        $other = $this->pages->createSection('qa_lv_refuse_text', 'text');
        $this->pages->linkSectionToPage($page, $other, 20);
        $elsewhere = $this->pages->createSection('qa_lv_refuse_elsewhere', 'data-view');

        self::assertSame(400, $this->state($page, $other)['status'] ?? null, 'A section that is not a list is not served.');
        self::assertSame(403, $this->state($page, $elsewhere)['status'] ?? null, 'A naked section id is no proof of membership.');
        self::assertSame(404, $this->jsonRequest('GET', '/cms-api/v1/lists/' . $view->getId() . '/state?page_id=99999999')['status'] ?? null);
        self::assertSame(400, $this->jsonRequest('GET', '/cms-api/v1/lists/' . $view->getId() . '/state')['status'] ?? null, 'page_id is required.');
    }

    public function testAPageTheVisitorMayNotReadAnswersLikeThePage(): void
    {
        [$page, $view] = $this->dataView('qa_lv_acl', openAccess: false);

        self::assertContains($this->state($page, $view)['status'] ?? null, [401, 403], 'A guest has no access to a private page.');
        $token = $this->loginAsQaUser();
        // Signed-in without a page grant: same as a missing page (404), not a different leak.
        self::assertSame(404, $this->state($page, $view, '', $token)['status'] ?? null, 'The subject group has no grant on the page.');

        // The kernel was rebooted by the requests: grant through the entities of the live container.
        $em = $this->service(EntityManagerInterface::class);
        $pages = new PageSectionFactory($em, $this->service(ACLService::class), $this->service(LookupService::class), $this->service(CacheService::class));
        $livePage = $em->find(Page::class, $page->getId());
        $subject = $em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Page::class, $livePage);
        self::assertInstanceOf(Group::class, $subject);
        $pages->grantGroupAcl($livePage, $subject, select: true, insert: false, update: false, delete: false);
        $this->assertEnvelopeSuccess($this->state($page, $view, '', $token));
    }

    public function testAnOwnEntriesListNeedsASignedInVisitorAndShowsOnlyTheirRecords(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lv_own');
        $user = $this->userId('qa.user@selfhelp.test');
        $admin = $this->userId('qa.admin@selfhelp.test');
        $mine = $this->grid->row($tableId, $user, ['title' => 'Mine', 'status' => 'open']);
        $this->grid->row($tableId, $admin, ['title' => 'Theirs', 'status' => 'open']);
        $this->grid->row($tableId, $user, ['title' => 'Mine too', 'status' => 'done']);

        [$openPage, $openList] = $this->formList('qa_lv_own_open', openAccess: true);
        self::assertSame(401, $this->state($openPage, $openList)['status'] ?? null, 'Guests have no entries of their own, even where the page is open.');

        $data = $this->assertEnvelopeSuccess($this->state($page, $list, '', $this->loginAsQaUser()));
        self::assertSame(2, $this->at($data, 'pagination')['total']);
        self::assertEqualsCanonicalizing(['Mine', 'Mine too'], array_column(array_map(fn (array $r): array => $this->at($r, 'values'), $this->listOf($data, 'rows')), 'title'));
        foreach ($this->listOf($data, 'rows') as $row) {
            self::assertTrue($row['is_own']);
            self::assertTrue($row['can_update']);
            self::assertTrue($row['can_delete']);
        }
        self::assertTrue($this->at($data, 'capabilities')['create']);
        self::assertSame((int) $form->getId(), $this->at($data, 'list')['form_section_id']);

        // Another visitor's record does not exist for this one: not as a row, not by its number.
        $theirs = self::coerceInt($this->db->fetchOne('SELECT id FROM data_rows WHERE id_data_tables = :t AND id_users = :u', ['t' => $tableId, 'u' => $admin]));
        $probe = $this->state($page, $list, 'list.record=' . $theirs, $this->loginAsQaUser());
        self::assertSame(404, $probe['status'] ?? null);
        $own = $this->assertEnvelopeSuccess($this->state($page, $list, 'list.record=' . $mine, $this->loginAsQaUser()));
        self::assertSame('view', $this->at($own, 'state')['mode']);
        self::assertSame($mine, $this->at($own, 'selected')['record_id']);
        self::assertSame(1, $this->at($own, 'selected')['version']);
    }

    public function testAListNamesTheInputsOfItsFormBeforeAnyRecordHoldsAValueForThem(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lv_unwritten');
        $title = $this->formInput($form, 'qa_lv_unwritten_title', 'text-input', 'Title', 10);
        $status = $this->formInput($form, 'qa_lv_unwritten_status', 'select', 'Status', 20);
        $titleKey = 'section_' . $title->getId();
        $statusKey = 'section_' . $status->getId();
        $this->pages->setSectionField($list, 'list_columns', json_encode([
            ['field' => $titleKey, 'label' => 'Title', 'sortable' => true, 'searchable' => true],
            ['field' => $statusKey, 'label' => 'Status', 'sortable' => true, 'searchable' => true],
        ], JSON_THROW_ON_ERROR));
        $this->pages->setSectionField($list, 'list_controls', json_encode([
            ['id' => 'status', 'type' => 'select', 'field' => $statusKey, 'label' => 'Status', 'options_from' => 'data'],
        ], JSON_THROW_ON_ERROR));

        // Nobody has submitted a status yet, so the table has a column for the title only.
        $this->grid->column($tableId, $titleKey);
        $user = $this->userId('qa.user@selfhelp.test');
        $this->grid->row($tableId, $user, [$titleKey => 'Alpha']);
        $this->grid->row($tableId, $user, [$titleKey => 'Beta']);
        $token = $this->loginAsQaUser();

        $data = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame(2, $this->at($data, 'pagination')['total']);
        foreach ($this->listOf($data, 'rows') as $row) {
            $values = $this->at($row, 'values');
            self::assertArrayHasKey($statusKey, $values, 'The column is part of every row.');
            self::assertNull($values[$statusKey], 'It is empty.');
        }
        $controls = $this->listOf($data, 'controls');
        self::assertSame([], $this->listOf($controls[0], 'options'), 'There is nothing to choose from yet.');

        $sorted = $this->assertEnvelopeSuccess($this->state($page, $list, 'list.sort=' . $statusKey . ':asc', $token));
        self::assertSame(2, $this->at($sorted, 'pagination')['total']);
        $searched = $this->assertEnvelopeSuccess($this->state($page, $list, 'list.q=alp', $token));
        self::assertSame(1, $this->at($searched, 'pagination')['total'], 'The search finds the title and is not confused by the column nobody wrote.');
        $filtered = $this->assertEnvelopeSuccess($this->state($page, $list, 'list.f.status=open', $token));
        self::assertSame(0, $this->at($filtered, 'pagination')['total']);

        // A column the form does not have is still refused.
        self::assertSame(400, $this->state($page, $list, 'list.sort=section_999999:asc', $token)['status'] ?? null);
    }

    public function testModesAndTheOpenRecordAreServedAndGatedByWhatTheListAllows(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lv_modes', ['allow_update' => '0']);
        $user = $this->userId('qa.user@selfhelp.test');
        $row = $this->grid->row($tableId, $user, ['title' => 'One', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $create = $this->assertEnvelopeSuccess($this->state($page, $list, 'list.mode=create', $token));
        self::assertSame('create', $this->at($create, 'state')['mode']);
        self::assertNull($create['selected']);

        $envelope = $this->state($page, $list, 'list.record=' . $row . '&list.mode=edit', $token);
        self::assertSame(403, $envelope['status'] ?? null);
        self::assertSame('operation_disabled', $this->at($envelope, 'data')['reason'] ?? null, 'A list that does not allow editing refuses edit mode.');

        $view = $this->assertEnvelopeSuccess($this->state($page, $list, 'list.record=' . $row, $token));
        self::assertSame('view', $this->at($view, 'state')['mode']);
        self::assertFalse($this->at($view, 'selected')['can_update'], 'The list does not allow editing, so no record can be edited.');
        self::assertTrue($this->at($view, 'selected')['can_delete']);
    }

    public function testASharedListNeedsExplicitColumnsAndNeverLeaksAboveTheFixedFilter(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lv_shared', [
            'own_entries_only' => '0',
            'list_columns' => '[{"field":"title","label":"Title"}]',
            'list_fixed_filter' => '{"mode":"and","children":[{"column":"status","operator":"eq","value":"open"}]}',
        ]);
        $admin = $this->userId('qa.admin@selfhelp.test');
        $this->grid->row($tableId, $admin, ['title' => 'Visible', 'status' => 'open']);
        $hidden = $this->grid->row($tableId, $admin, ['title' => 'Hidden', 'status' => 'closed']);
        $token = $this->loginAsQaUser();

        $data = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame(['Visible'], array_column(array_map(fn (array $r): array => $this->at($r, 'values'), $this->listOf($data, 'rows')), 'title'));
        foreach ($this->listOf($data, 'rows') as $row) {
            self::assertFalse($row['is_own']);
            self::assertFalse($row['can_update'], "Another person's record is not editable without a grant on the table.");
            self::assertFalse($row['can_delete']);
        }

        self::assertSame(404, $this->state($page, $list, 'list.record=' . $hidden, $token)['status'] ?? null, 'The fixed filter bounds the dataset for a record number as well.');

        // A shared list that names no columns would publish every column of the table: refused as a broken configuration.
        $this->pages->setSectionField($list, 'list_columns', '[]');
        self::assertSame(500, $this->state($page, $list, '', $token)['status'] ?? null);
    }

    public function testAChangeOfTheDataShowsInTheNextState(): void
    {
        [$page, $view] = $this->dataView('qa_lv_fresh');
        $before = $this->assertEnvelopeSuccess($this->state($page, $view));
        self::assertSame(15, $this->at($before, 'pagination')['total']);

        $tableId = self::coerceInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => 'qa_lv_fresh_table']));
        $this->grid->row($tableId, null, ['title' => 'Event 99', 'city' => 'Bern', 'published' => '1', 'seats' => '1', 'starts' => '2026-02-01']);
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId);

        $after = $this->assertEnvelopeSuccess($this->state($page, $view));
        self::assertSame(16, $this->at($after, 'pagination')['total']);
    }
}
