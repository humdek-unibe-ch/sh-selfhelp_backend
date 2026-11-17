<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\Style;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The list is the form: inputs sit directly under `entry-form-list`, the first
 * submit creates the table named by the list section id, and a foreign
 * `data_table` is a read-only override (delete + quick actions only).
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListAsFormTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testListWithDirectInputsCreatesTableOnFirstSubmitAndServesRecords(): void
    {
        $token = $this->loginAsQaUser();
        [$page, $list, $titleKey] = $this->freshListWithInputs('qa_laf_own');

        $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'form_data' => ['title' => 'Hello', 'note' => 'World'],
        ], $token));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        self::assertGreaterThan(0, $recordId);

        $tableName = self::coerceString($this->db->fetchOne(
            'SELECT name FROM data_tables WHERE name = :n',
            ['n' => (string) $list->getId()],
        ));
        self::assertSame((string) $list->getId(), $tableName);

        $state = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame((int) $list->getId(), $this->at($state, 'list')['form_section_id'] ?? null);
        self::assertSame(['Hello'], $this->columnValues($state, $titleKey));

        $version = self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'form_data' => ['title' => 'Updated', 'note' => 'World'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $version,
        ], $token));

        $updated = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame(['Updated'], $this->columnValues($updated, $titleKey));

        $version = self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'record_id' => $recordId,
            'expected_version' => $version,
        ], $token));
        $afterDelete = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame([], $this->columnValues($afterDelete, $titleKey));

        // Own entries only: another participant cannot see or touch the first user's leftover.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'form_data' => ['title' => 'Mine', 'note' => 'x'],
        ], $token));
        $other = $this->loginAsQaEditor();
        $otherState = $this->assertEnvelopeSuccess($this->state($page, $list, '', $other));
        self::assertSame([], $this->columnValues($otherState, $titleKey));
        $foreignId = self::coerceInt($this->db->fetchOne(
            'SELECT id FROM data_rows WHERE id_data_tables = (SELECT id FROM data_tables WHERE name = :n) ORDER BY id DESC LIMIT 1',
            ['n' => (string) $list->getId()],
        ));
        $envelope = $this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'record_id' => $foreignId,
            'expected_version' => 1,
        ], $other);
        // Another owner looks like not found.
        self::assertSame(404, $envelope['status'] ?? null);
    }

    public function testRenamingTheListNameRenamesTheTableDisplayName(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$page, $list] = $this->freshListWithInputs('qa_laf_rename');
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'form_data' => ['title' => 'A', 'note' => 'B'],
        ], $this->loginAsQaUser()));

        $nameField = $this->em->getRepository(Field::class)->findOneBy(['name' => 'name']);
        self::assertInstanceOf(Field::class, $nameField);
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $list->getId()),
            ['contentFields' => [], 'propertyFields' => [['fieldId' => (int) $nameField->getId(), 'value' => 'Renamed list']]],
            $admin,
        ));

        $display = self::coerceString($this->db->fetchOne(
            'SELECT display_name FROM data_tables WHERE name = :n',
            ['n' => (string) $list->getId()],
        ));
        self::assertSame('Renamed list', $display);
    }

    public function testFormStylesBelowAListAreRefused(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$page, $list] = $this->freshListWithInputs('qa_laf_nest');
        $container = $this->pages->createSection('qa_laf_nest_box', 'container');
        $this->pages->linkSectionToParent($list, $container, 90);

        foreach (['form-record', 'form-log', 'entry-record-form', 'entry-form-list'] as $styleName) {
            $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $styleName]);
            self::assertInstanceOf(Style::class, $style);
            foreach ([(int) $list->getId(), (int) $container->getId()] as $parentId) {
                $envelope = $this->jsonRequest(
                    'POST',
                    sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections/create', $page->getId(), $parentId),
                    ['styleId' => (int) $style->getId(), 'position' => 50, 'name' => 'qa_laf_nest_' . $styleName],
                    $admin,
                );
                self::assertSame(400, $envelope['status'] ?? null, $styleName . ' under ' . $parentId);
                $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
                self::assertSame('form_inside_list', $data['reason'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
            }
        }

        $before = self::coerceInt($this->db->fetchOne(
            'SELECT COUNT(*) FROM rel_sections_hierarchy WHERE id_parent_section IN (:a, :b)',
            ['a' => $list->getId(), 'b' => $container->getId()],
        ));
        // Only the container (and the two inputs of freshListWithInputs) sit under the list.
        self::assertSame(3, $before);
    }

    public function testForeignDataTableListDeletesAndRunsQuickActionsOnly(): void
    {
        $user = $this->loginAsQaUser();
        [$pageA, $listA, $titleKey, $statusKey] = $this->freshListWithInputs('qa_laf_a', withStatus: true);
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => (int) $pageA->getId(),
                'section_id' => (int) $listA->getId(),
                'form_data' => ['title' => $title, 'status' => 'open'],
            ], $user));
        }
        $tableId = $this->tableIdByName((string) $listA->getId());

        $pageB = $this->pages->createPage('qa_laf_b', false);
        $listB = $this->pages->createSection('qa_laf_b_list', 'entry-form-list');
        $this->pages->linkSectionToPage($pageB, $listB, 10);
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($pageB, $subject, select: true, insert: true, update: true, delete: true);
        $this->pages->setSectionField($listB, 'data_table', (string) $tableId);
        $this->pages->setSectionField($listB, 'own_entries_only', '0');
        $this->pages->setSectionField($listB, 'allow_create', '0');
        $this->pages->setSectionField($listB, 'allow_update', '0');
        $this->pages->setSectionField($listB, 'allow_delete', '1');
        $this->pages->setSectionField($listB, 'list_columns', json_encode([
            ['field' => $titleKey, 'label' => 'Title', 'sortable' => true],
            ['field' => $statusKey, 'label' => 'Status'],
        ], JSON_THROW_ON_ERROR));
        $this->pages->setSectionField($listB, 'quick_actions', json_encode([
            [
                'id' => 'approve',
                'label' => 'Approve',
                'set' => [$statusKey => 'approved'],
            ],
        ], JSON_THROW_ON_ERROR));

        $admin = $this->loginAsQaAdmin();

        $state = $this->assertEnvelopeSuccess($this->state($pageB, $listB, '', $admin));
        self::assertNull($this->at($state, 'list')['form_section_id'] ?? null);
        self::assertCount(3, $this->listOf($state, 'rows'));

        $recordId = self::coerceInt($this->listOf($state, 'rows')[0]['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/lists/%d/records/%d/actions/approve', $listB->getId(), $recordId),
            ['page_id' => (int) $pageB->getId(), 'expected_version' => 1],
            $admin,
        ));
        $status = self::coerceString($this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols'
            . ' WHERE c.id_data_rows = :r AND col.field_key = :k AND c.id_languages = 1',
            ['r' => $recordId, 'k' => $statusKey],
        ));
        self::assertSame('approved', $status);

        $version = self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
            'page_id' => (int) $pageB->getId(),
            'section_id' => (int) $listB->getId(),
            'record_id' => $recordId,
            'expected_version' => $version,
        ], $admin));
        self::assertCount(2, $this->listOf($this->assertEnvelopeSuccess($this->state($pageB, $listB, '', $admin)), 'rows'));

        $createField = $this->em->getRepository(Field::class)->findOneBy(['name' => 'allow_create']);
        self::assertInstanceOf(Field::class, $createField);
        $refused = $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageB->getId(), $listB->getId()),
            ['contentFields' => [], 'propertyFields' => [['fieldId' => (int) $createField->getId(), 'value' => '1']]],
            $admin,
        );
        self::assertSame(400, $refused['status'] ?? null);
        $refusedData = is_array($refused['data'] ?? null) ? $refused['data'] : [];
        $errors = is_array($refusedData['errors'] ?? null) ? $refusedData['errors'] : [];
        $firstError = is_array($errors[0] ?? null) ? $errors[0] : [];
        self::assertSame('allow_create', $firstError['field'] ?? null);

        $textStyle = $this->em->getRepository(Style::class)->findOneBy(['name' => 'text-input']);
        self::assertInstanceOf(Style::class, $textStyle);
        $inputRefused = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections/create', $pageB->getId(), $listB->getId()),
            ['styleId' => (int) $textStyle->getId(), 'position' => 10, 'name' => 'qa_laf_b_input'],
            $admin,
        );
        self::assertSame(400, $inputRefused['status'] ?? null);

        // allow_create is off → 403 operation_disabled (P1-A5).
        $submitRefused = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $pageB->getId(),
            'section_id' => (int) $listB->getId(),
            'form_data' => ['title' => 'Nope'],
        ], $admin);
        self::assertSame(403, $submitRefused['status'] ?? null);
        $submitData = is_array($submitRefused['data'] ?? null) ? $submitRefused['data'] : [];
        self::assertSame('operation_disabled', $submitData['reason'] ?? null);
    }

    public function testHistoryNeedsAllowUpdateOnForeignRecordsWhileQuickActionsDoNot(): void
    {
        $user = $this->loginAsQaUser();
        [$pageA, $listA, $titleKey, $statusKey] = $this->freshListWithInputs('qa_laf_hist_a', withStatus: true);
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $pageA->getId(),
            'section_id' => (int) $listA->getId(),
            'form_data' => ['title' => 'Needs review', 'status' => 'open'],
        ], $user));
        $tableId = $this->tableIdByName((string) $listA->getId());

        $pageB = $this->pages->createPage('qa_laf_hist_b', false);
        $listB = $this->pages->createSection('qa_laf_hist_b_list', 'entry-form-list');
        $this->pages->linkSectionToPage($pageB, $listB, 10);
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($pageB, $subject, select: true, insert: true, update: true, delete: true);
        $this->pages->setSectionField($listB, 'data_table', (string) $tableId);
        $this->pages->setSectionField($listB, 'own_entries_only', '0');
        $this->pages->setSectionField($listB, 'allow_create', '0');
        $this->pages->setSectionField($listB, 'allow_update', '0');
        $this->pages->setSectionField($listB, 'allow_delete', '0');
        $this->pages->setSectionField($listB, 'show_history', '1');
        $this->pages->setSectionField($listB, 'list_columns', json_encode([
            ['field' => $titleKey, 'label' => 'Title', 'sortable' => true],
            ['field' => $statusKey, 'label' => 'Status'],
        ], JSON_THROW_ON_ERROR));
        $this->pages->setSectionField($listB, 'quick_actions', json_encode([
            [
                'id' => 'approve',
                'label' => 'Approve',
                'set' => [$statusKey => 'approved'],
            ],
        ], JSON_THROW_ON_ERROR));

        $admin = $this->loginAsQaAdmin();
        $state = $this->assertEnvelopeSuccess($this->state($pageB, $listB, '', $admin));
        $row = $this->listOf($state, 'rows')[0] ?? null;
        self::assertIsArray($row);
        self::assertFalse($row['can_update'] ?? true);
        self::assertFalse($row['can_view_history'] ?? true, 'Foreign history still needs Allow edit.');
        self::assertContains('approve', is_array($row['quick_actions'] ?? null) ? $row['quick_actions'] : []);

        $recordId = self::coerceInt($row['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/lists/%d/records/%d/actions/approve', $listB->getId(), $recordId),
            ['page_id' => (int) $pageB->getId(), 'expected_version' => 1],
            $admin,
        ));

        $history = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/lists/%d/records/%d/history?page_id=%d', $listB->getId(), $recordId, $pageB->getId()),
            null,
            $admin,
        );
        self::assertSame(403, $history['status'] ?? null);
    }

    public function testALeftoverNestedFormIsServedAsListError(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$page, $list] = $this->freshListWithInputs('qa_laf_leftover');
        // Bypass the nesting guard: write the child directly into the hierarchy.
        $nested = $this->pages->createSection('qa_laf_leftover_form', 'form-record');
        $this->pages->linkSectionToParent($list, $nested, 99);
        $this->pages->invalidatePageScopedCaches();

        $pageEnvelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $page->getKeyword(),
            null,
            $admin,
        );
        $pageData = $this->assertEnvelopeSuccess($pageEnvelope);
        $section = $this->findSectionInPage($pageData, (int) $list->getId());
        self::assertNotNull($section);
        self::assertArrayNotHasKey('list_state', $section);
        $error = is_array($section['list_error'] ?? null) ? $section['list_error'] : [];
        self::assertSame(500, $error['status'] ?? null);
        self::assertSame('invalid_list_config', $error['reason'] ?? null);
        self::assertSame('This list is not configured correctly.', $error['message'] ?? null);

        $stateEnvelope = $this->state($page, $list, '', $admin);
        self::assertSame(500, $stateEnvelope['status'] ?? null);
        $stateData = is_array($stateEnvelope['data'] ?? null) ? $stateEnvelope['data'] : [];
        self::assertSame('invalid_list_config', $stateData['reason'] ?? null);
    }

    /**
     * @param array<string, mixed> $pageData
     * @return array<string, mixed>|null
     */
    private function findSectionInPage(array $pageData, int $sectionId): ?array
    {
        $page = is_array($pageData['page'] ?? null) ? $pageData['page'] : [];
        $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];

        return $this->searchSections($sections, $sectionId);
    }

    /**
     * @param array<mixed> $sections
     * @return array<string, mixed>|null
     */
    private function searchSections(array $sections, int $sectionId): ?array
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
                $found = $this->searchSections($children, $sectionId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: Page, 1: Section, 2: string, 3: string}
     */
    private function freshListWithInputs(string $prefix, bool $withStatus = false): array
    {
        $page = $this->pages->createPage($prefix, false);
        $list = $this->pages->createSection($prefix . '_list', 'entry-form-list');
        $this->pages->linkSectionToPage($page, $list, 10);
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($page, $subject, select: true, insert: true, update: true, delete: true);

        $title = $this->formInput($list, $prefix . '_title', 'text-input', 'title', 10);
        $secondName = $withStatus ? 'status' : 'note';
        $second = $this->formInput($list, $prefix . '_' . $secondName, 'text-input', $secondName, 20);
        $this->pages->setSectionField($list, 'list_columns', json_encode([
            ['field' => 'section_' . $title->getId(), 'label' => 'Title', 'sortable' => true, 'searchable' => true],
            ['field' => 'section_' . $second->getId(), 'label' => $secondName, 'sortable' => true],
        ], JSON_THROW_ON_ERROR));

        return [$page, $list, 'section_' . $title->getId(), 'section_' . $second->getId()];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function columnValues(array $data, string $field): array
    {
        return array_map(
            static fn (array $row): string => is_array($row['values'] ?? null) && is_string($row['values'][$field] ?? null)
                ? $row['values'][$field]
                : '',
            $this->listOf($data, 'rows'),
        );
    }
}
