<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageRoute;
use App\Entity\Section;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The classic record styles (a record form, an entry table, an entry record with a delete button) are
 * built from the page itself, not from an entry list's state. The server refuses an update or a delete
 * without the version of the record it is based on, so the page has to say which version it shows.
 * These tests read that version from the page payload, as a renderer does, and send it back.
 */
#[PhpUnitGroup('form-list')]
final class LegacyRecordVersionTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testTheRecordFormOffersTheVersionOfItsRecordAndAStaleSaveIsRefused(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_lrv_form', openAccess: false);
        $this->grantSubject($page);
        $token = $this->loginAsQaUser();
        $recordId = $this->submit($token, $page, $form, 'first');

        $offered = $this->prefillRows($page, $form, $token);
        self::assertNotSame([], $offered, 'The page prefills the record of the signed-in person.');
        foreach ($offered as $row) {
            self::assertSame($recordId, self::coerceInt($row['record_id'] ?? null));
            self::assertSame(1, $row['_version'] ?? null, 'Every prefilled row carries the version the record has now.');
        }

        $saved = $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, 'second', 1));
        self::assertSame(2, $saved['version'] ?? null);

        // The cached page goes with the record it shows: the next page load shows the saved values and names their version,
        // so a person who reloads is never offered new values with an old version, or the other way round.
        foreach ($this->prefillRows($page, $form, $token) as $row) {
            self::assertSame('second', $row['qa_answer'] ?? null, 'The reloaded page prefills the saved value.');
            self::assertSame(2, $row['_version'] ?? null, 'The reloaded page offers the version of the saved record.');
        }

        $stale = $this->update($token, $page, $form, $recordId, 'lost edit', 1);
        self::assertSame(409, $stale['status'] ?? null, 'The tab that still shows version 1 is refused.');
        self::assertSame('second', $this->cellValue($recordId));
    }

    public function testAnEntryTableOffersTheVersionNextToTheDeleteFlag(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_lrv_table', openAccess: false);
        $this->grantSubject($page);
        $token = $this->loginAsQaUser();
        $recordId = $this->submit($token, $page, $form, 'to be removed');
        $tableId = $this->tableIdOf($recordId);

        $table = $this->pages->createSection('qa_lrv_table_view', 'entry-table');
        $this->pages->linkSectionToPage($this->managed($page), $table, 20);
        $this->pages->setSectionField($table, 'data_table', (string) $tableId);
        $this->pages->setSectionField($table, 'own_entries_only', '1');
        $this->pages->setSectionField($table, 'delete_entry', '1');
        $this->pages->invalidatePageScopedCaches();

        $entries = $this->entriesOf($page, $table, $token);
        self::assertCount(1, $entries);
        self::assertTrue($entries[0]['_can_delete'] ?? null, 'The person owns the record.');
        self::assertSame(1, $entries[0]['_version'] ?? null, 'The row carries the version a delete is based on.');

        $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, 'edited elsewhere', 1));

        $stale = $this->delete($token, $page, $table, $recordId, 1);
        self::assertSame(409, $stale['status'] ?? null, 'A delete based on the old version is refused.');
        self::assertFalse($this->isDeleted($recordId));

        $current = $this->entriesOf($page, $table, $token);
        self::assertSame(2, $current[0]['_version'] ?? null);
        $this->assertEnvelopeSuccess($this->delete($token, $page, $table, $recordId, 2));
        self::assertTrue($this->isDeleted($recordId));
    }

    public function testADeleteButtonBoundToARecordOffersTheVersionOfThatRecord(): void
    {
        [$formPage, $form] = $this->pages->createFormPage('qa_lrv_detail_rows', openAccess: false);
        $this->grantSubject($formPage);
        $token = $this->loginAsQaUser();
        $recordId = $this->submit($token, $formPage, $form, 'detail record');
        $tableId = $this->tableIdOf($recordId);
        $this->assertEnvelopeSuccess($this->update($token, $formPage, $form, $recordId, 'detail record, edited', 1));

        $detail = $this->pages->createPage('qa_lrv_detail', openAccess: false);
        $this->grantSubject($detail);
        $route = new PageRoute();
        $route->setPage($detail);
        $route->setPathPattern('/qa-lrv-detail/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $holder = $this->pages->createSection('qa_lrv_detail_holder', 'entry-record');
        $this->pages->linkSectionToPage($detail, $holder, 10);
        $this->pages->setSectionField($holder, 'data_table', (string) $tableId);
        $this->pages->setSectionField($holder, 'own_entries_only', '1');
        $this->pages->setSectionField($holder, 'load_record_from', 'record_id');
        $button = $this->pages->createSection('qa_lrv_detail_delete', 'entry-record-delete');
        $this->pages->linkSectionToParent($holder, $button, 10);
        $this->pages->setSectionField($button, 'data_table', (string) $tableId);
        $this->pages->invalidatePageScopedCaches();

        $payload = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-lrv-detail/' . $recordId),
            null,
            $token,
        ));
        $buttons = $this->sectionsByStyle($this->at($payload, 'page')['sections'] ?? [], 'entry-record-delete');
        self::assertCount(1, $buttons, 'One delete button for the bound record.');
        $content = static fn (mixed $field): mixed => is_array($field) ? ($field['content'] ?? null) : null;
        self::assertSame((string) $recordId, $content($buttons[0]['record_id'] ?? null));
        self::assertSame('2', $content($buttons[0]['record_version'] ?? null), 'The button names the version the record has now.');

        $stale = $this->delete($token, $detail, $button, $recordId, 1);
        self::assertSame(409, $stale['status'] ?? null);
        self::assertFalse($this->isDeleted($recordId));
        $this->assertEnvelopeSuccess($this->delete($token, $detail, $button, $recordId, 2));
        self::assertTrue($this->isDeleted($recordId));
    }

    public function testAnEntryRecordFormOffersTheVersionOfTheRecordTheRouteNames(): void
    {
        [$formPage, $form] = $this->pages->createFormPage('qa_lrv_edit_rows', openAccess: false);
        $this->grantSubject($formPage);
        $token = $this->loginAsQaUser();
        $recordId = $this->submit($token, $formPage, $form, 'to edit');
        $tableId = $this->tableIdOf($recordId);
        $this->assertEnvelopeSuccess($this->update($token, $formPage, $form, $recordId, 'edited once', 1));
        $this->assertEnvelopeSuccess($this->update($token, $formPage, $form, $recordId, 'edited twice', 2));

        $editPage = $this->pages->createPage('qa_lrv_edit', openAccess: false);
        $this->grantSubject($editPage);
        $route = new PageRoute();
        $route->setPage($editPage);
        $route->setPathPattern('/qa-lrv-edit/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $editForm = $this->pages->createSection('qa_lrv_edit_form', 'entry-record-form');
        $this->pages->linkSectionToPage($editPage, $editForm, 10);
        $this->pages->setSectionField($editForm, 'data_table', (string) $tableId);
        $this->pages->setSectionField($editForm, 'own_entries_only', '1');
        $this->pages->setSectionField($editForm, 'load_record_from', 'record_id');
        $this->pages->invalidatePageScopedCaches();

        $payload = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-lrv-edit/' . $recordId),
            null,
            $token,
        ));
        $forms = $this->sectionsByStyle($this->at($payload, 'page')['sections'] ?? [], 'entry-record-form');
        self::assertCount(1, $forms);
        $rows = $this->rows($forms[0]['section_data'] ?? []);
        self::assertNotSame([], $rows, 'The route names a record of the person: it is prefilled.');
        foreach ($rows as $row) {
            self::assertSame($recordId, self::coerceInt($row['record_id'] ?? null));
            self::assertSame(3, $row['_version'] ?? null, 'Two saves after the first: the record is at version 3.');
        }
    }

    public function testNoVersionIsOfferedForARecordThatIsGone(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_lrv_gone', openAccess: false);
        $this->grantSubject($page);
        $token = $this->loginAsQaUser();
        $recordId = $this->submit($token, $page, $form, 'only');
        $tableId = $this->tableIdOf($recordId);
        $table = $this->pages->createSection('qa_lrv_gone_table', 'entry-table');
        $this->pages->linkSectionToPage($this->managed($page), $table, 20);
        $this->pages->setSectionField($table, 'data_table', (string) $tableId);
        $this->pages->setSectionField($table, 'own_entries_only', '1');
        $this->pages->setSectionField($table, 'delete_entry', '1');
        $this->pages->invalidatePageScopedCaches();

        $current = $this->entriesOf($page, $table, $token);
        self::assertSame(1, $current[0]['_version'] ?? null);
        $this->assertEnvelopeSuccess($this->delete($token, $page, $table, $recordId, 1));

        self::assertSame([], $this->entriesOf($page, $table, $token), 'A deleted record is not listed, so no version is offered for it.');
    }

    // -- helpers --------------------------------------------------------------

    /** The page as the entity manager holds it now: the requests of a test detach what it created before them. */
    private function managed(Page $page): Page
    {
        $found = $this->em->getRepository(Page::class)->find($page->getId());
        self::assertInstanceOf(Page::class, $found);

        return $found;
    }

    private function grantSubject(Page $page): void
    {
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($page, $subject);
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(Page $page, string $token): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword(), null, $token));
    }

    /**
     * The rows a record form prefills from, as the page carries them.
     *
     * @return list<array<string, mixed>>
     */
    private function prefillRows(Page $page, Section $form, string $token): array
    {
        $sections = $this->sectionsByStyle($this->at($this->pagePayload($page, $token), 'page')['sections'] ?? [], 'form-record');
        foreach ($sections as $section) {
            if (self::coerceInt($section['id'] ?? null) === (int) $form->getId()) {
                return $this->rows($section['section_data'] ?? []);
            }
        }
        self::fail('The record form is on the page.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesOf(Page $page, Section $table, string $token): array
    {
        $sections = $this->sectionsByStyle($this->at($this->pagePayload($page, $token), 'page')['sections'] ?? [], 'entry-table');
        foreach ($sections as $section) {
            if (self::coerceInt($section['id'] ?? null) === (int) $table->getId()) {
                return $this->rows($section['entries'] ?? []);
            }
        }
        self::fail('The entry table is on the page.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $keyed = [];
                foreach ($row as $key => $cell) {
                    $keyed[(string) $key] = $cell;
                }
                $rows[] = $keyed;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function at(mixed $value, string $key): array
    {
        self::assertIsArray($value);
        $found = $value[$key] ?? null;
        self::assertIsArray($found, 'Missing "' . $key . '" in the payload.');
        $keyed = [];
        foreach ($found as $name => $item) {
            $keyed[(string) $name] = $item;
        }

        return $keyed;
    }

    /**
     * Every section of the style anywhere in the tree.
     *
     * @return list<array<string, mixed>>
     */
    private function sectionsByStyle(mixed $sections, string $styleName): array
    {
        $found = [];
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $keyed = [];
            foreach ($section as $key => $value) {
                $keyed[(string) $key] = $value;
            }
            if (($keyed['style_name'] ?? null) === $styleName) {
                $found[] = $keyed;
            }
            array_push($found, ...$this->sectionsByStyle($keyed['children'] ?? [], $styleName));
        }

        return $found;
    }

    private function submit(string $token, Page $page, Section $form, string $value): int
    {
        $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['qa_answer' => $value],
        ], $token));

        return self::coerceInt($created['record_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $token, Page $page, Section $form, int $recordId, string $value, int $expectedVersion): array
    {
        return $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['qa_answer' => $value],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $expectedVersion,
        ], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $token, Page $page, Section $section, int $recordId, int $expectedVersion): array
    {
        return $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'expected_version' => $expectedVersion,
        ], $token);
    }

    private function tableIdOf(int $recordId): int
    {
        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $table = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $table);

        return (int) $table->getId();
    }

    private function cellValue(int $recordId): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => 'qa_answer'],
        );

        return $value === false ? null : self::coerceString($value);
    }

    private function isDeleted(int $recordId): bool
    {
        $deletedId = $this->service(LookupService::class)->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
        $current = $this->em->getConnection()->fetchOne('SELECT id_action_trigger_types FROM data_rows WHERE id = :id', ['id' => $recordId]);

        return self::coerceInt($current) === $deletedId;
    }
}
