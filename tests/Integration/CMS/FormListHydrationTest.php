<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Page;
use App\Entity\Section;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The first paint of a list is part of its page: the page endpoint adds the
 * server-rendered state of every list after the page cache, for the visitor and
 * the list parameters of the request. Clients draw rows without a second request
 * and a bookmarked URL is already in the state; a list that cannot be shown
 * leaves the page intact and carries its reason.
 */
#[PhpUnitGroup('form-list')]
final class FormListHydrationTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testThePageHoldsTheFirstPageOfAPublicListForAGuest(): void
    {
        [$page, $view] = $this->dataView('qa_lh_public');

        $section = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page)), $view);

        self::assertArrayNotHasKey('list_error', $section);
        $state = $this->at($section, 'list_state');
        self::assertCount(15, $this->listOf($state, 'rows'), 'Only the published events, all on the first page.');
        self::assertSame(15, $this->at($state, 'pagination')['total']);
        self::assertStringNotContainsString('internal note', json_encode($section, JSON_THROW_ON_ERROR), 'A column the author did not choose is never in the page.');
    }

    public function testThePageCarriesTheRenderedRecordsOfACustomLayoutAndNotItsTemplate(): void
    {
        [$page, $view] = $this->customDataView('qa_lh_template');
        $this->recordTemplate($view, 'qa_lh_template');

        $section = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page, 'language_id=3')), $view);

        self::assertSame([], $section['children'], 'The sections inside a data view are the template of one record: the page does not carry them.');
        $rows = $this->listOf($this->at($section, 'list_state'), 'rows');
        self::assertCount(3, $rows);
        self::assertSame(['container'], array_column($this->listOf($rows[0], 'sections'), 'style_name'), 'The first paint already holds the rendered records.');
        self::assertStringNotContainsString('{{title}}', json_encode($section, JSON_THROW_ON_ERROR), 'No token is left for the client to fill.');
    }

    public function testTheListParametersOfThePageUrlAreAlreadyInTheState(): void
    {
        [$page, $view] = $this->dataView('qa_lh_deeplink', ['page_size' => '5']);

        $section = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page, 'list.page=2&list.q=Event&list.sort=seats%3Adesc')), $view);

        $state = $this->at($section, 'list_state');
        self::assertSame(2, $this->at($state, 'pagination')['page']);
        self::assertSame(['Event 19', 'Event 17', 'Event 15', 'Event 13', 'Event 11'], $this->titles($state), 'Page two of the published events, largest seats first (29..21 are page one).');
        self::assertSame('Event', $this->at($state, 'state')['q']);
        self::assertSame([['field' => 'seats', 'direction' => 'desc']], $this->at($state, 'state')['sort']);
    }

    public function testAMalformedListParameterSpoilsTheListNotThePage(): void
    {
        [$page, $view] = $this->dataView('qa_lh_malformed');

        $section = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page, 'list.page=0')), $view);

        self::assertArrayNotHasKey('list_state', $section);
        $error = $this->at($section, 'list_error');
        self::assertSame(400, $error['status']);
        self::assertSame('invalid_list_parameter', $error['reason']);
        self::assertSame('list.page', $error['parameter']);
    }

    public function testAListOfOwnEntriesAsksAGuestToSignIn(): void
    {
        [$page, $list] = $this->formList('qa_lh_guest', [], openAccess: true);

        $section = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page)), $list);

        self::assertArrayNotHasKey('list_state', $section);
        $error = $this->at($section, 'list_error');
        self::assertSame(401, $error['status']);
        self::assertSame('login_required', $error['reason']);
    }

    public function testASignedInVisitorSeesOnlyTheirOwnRowsAndTheResponseIsPrivate(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lh_own');
        $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $this->grid->row($tableId, $this->userId('qa.admin@selfhelp.test'), ['title' => 'Theirs', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $envelope = $this->pagePayload($page, '', $token);
        $section = $this->listSection($this->assertEnvelopeSuccess($envelope), $list);

        self::assertSame(['Mine'], $this->titles($this->at($section, 'list_state')));
        $cacheControl = $this->lastResponseHeader('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl, 'A page that carries rows is never kept by a shared cache.');
    }

    public function testAPageWithoutAListIsLeftAlone(): void
    {
        $page = $this->pages->createPage('qa_lh_plain', openAccess: true);
        $text = $this->pages->createSection('qa_lh_plain_text', 'text');
        $this->pages->linkSectionToPage($page, $text, 10);

        $envelope = $this->pagePayload($page);

        $this->assertEnvelopeSuccess($envelope);
        self::assertStringNotContainsString('no-store', $this->lastResponseHeader('Cache-Control'));
        self::assertStringNotContainsString('list_state', json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    public function testTheRowsAreNeverFrozenIntoTheCachedPage(): void
    {
        [$page, $list, , $form] = $this->formList('qa_lh_fresh');
        $token = $this->loginAsQaUser();

        $first = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page, '', $token)), $list);
        self::assertSame([], $this->titles($this->at($first, 'list_state')));

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Fresh', 'status' => 'open'],
        ], $token));

        $second = $this->listSection($this->assertEnvelopeSuccess($this->pagePayload($page, '', $token)), $list);
        self::assertSame(['Fresh'], $this->titles($this->at($second, 'list_state')), 'The page payload is cached; the list state is not part of that cache.');
    }

    public function testAListThatTheConditionsHideIsNotRenderedAndLeaksNothing(): void
    {
        [$page, $view] = $this->dataView('qa_lh_hidden');
        $this->db->executeStatement('UPDATE sections SET `condition` = :c WHERE id = :id', ['c' => '{"==":[1,2]}', 'id' => $view->getId()]);
        $this->pages->invalidatePageScopedCaches();

        $envelope = $this->pagePayload($page);

        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertNull($this->findList($data, (int) $view->getId()), 'A section whose condition fails is not in the page.');
        self::assertStringNotContainsString('Event 01', json_encode($data, JSON_THROW_ON_ERROR));
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(Page $page, string $listQuery = '', ?string $token = null): array
    {
        return $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . ($listQuery === '' ? '' : '?' . $listQuery), null, $token);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function listSection(array $data, Section $section): array
    {
        $found = $this->findList($data, (int) $section->getId());
        self::assertNotNull($found, 'The list section is in the page payload.');

        return $found;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function findList(array $data, int $sectionId): ?array
    {
        $page = $data['page'] ?? null;
        self::assertIsArray($page);
        $sections = $page['sections'] ?? [];

        return $this->search(is_array($sections) ? $sections : [], $sectionId);
    }

    /**
     * @param array<array-key, mixed> $sections
     * @return array<string, mixed>|null
     */
    private function search(array $sections, int $sectionId): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['id'] ?? null) === $sectionId) {
                $result = [];
                foreach ($section as $key => $value) {
                    $result[(string) $key] = $value;
                }

                return $result;
            }
            $children = $section['children'] ?? null;
            if (is_array($children)) {
                $nested = $this->search($children, $sectionId);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function lastResponseHeader(string $name): string
    {
        return (string) $this->client->getResponse()->headers->get($name);
    }
}
