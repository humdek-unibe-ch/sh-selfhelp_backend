<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\User;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\FormList\FormListPageStateService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A list belongs to the page the visitor is served. A page that was published is served from its published
 * version: the columns, filters, switches, quick actions, the inputs of the form and the layout of a record all come
 * from it, on every road (the first paint inside the page, the list state, a write, the history), and what the author
 * is still editing never leaks. The draft is read only on an explicit preview by someone who may edit the page
 * (`admin.page.update` plus the update grant; the admin role passes), again on every road at once. The page's access
 * rights and the data stay live: they are not part of a version.
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListPublishedConfigTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const SECRET_NOTE = 'internal note';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testAGuestIsServedThePublishedViewAndOnlyAnEditorPreviewShowsTheDraft(): void
    {
        [$page, $view] = $this->dataView('qa_lpc_view');
        $this->publish($page);

        // The author keeps editing: a private column is added and the filter that hid the unpublished events is removed.
        $this->pages->setSectionField($view, 'list_columns', json_encode([
            ['field' => 'title', 'label' => 'Title'],
            ['field' => 'note', 'label' => 'Private column of the draft'],
        ], JSON_THROW_ON_ERROR));
        $this->pages->setSectionField($view, 'list_fixed_filter', '');
        $this->pages->invalidatePageScopedCaches();

        $public = $this->assertEnvelopeSuccess($this->state($page, $view));
        self::assertSame(15, $this->at($public, 'pagination')['total'], 'The published filter still applies: only the published events.');
        self::assertContains('City', array_column($this->listOf($public, 'columns'), 'label'), 'The published columns.');
        self::assertStringNotContainsString(self::SECRET_NOTE, json_encode($public, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Private column of the draft', json_encode($public, JSON_THROW_ON_ERROR));

        $participantPreview = $this->state($page, $view, 'preview=true', $this->loginAsQaUser());
        self::assertSame(403, $participantPreview['status'] ?? null, 'A participant without edit rights never sees the draft.');
        self::assertStringNotContainsString('Private column of the draft', json_encode($participantPreview, JSON_THROW_ON_ERROR));

        $draft = $this->assertEnvelopeSuccess($this->state($page, $view, 'preview=true', $this->editorWithGrant($page, 'qa_lpc_view')));
        self::assertSame(30, $this->at($draft, 'pagination')['total'], 'The draft has no filter.');
        self::assertStringContainsString('Private column of the draft', json_encode($draft, JSON_THROW_ON_ERROR));

        $adminDraft = $this->assertEnvelopeSuccess($this->state($page, $view, 'preview=true', $this->loginAsQaAdmin()));
        self::assertSame(30, $this->at($adminDraft, 'pagination')['total'], 'The admin role also gets the draft.');

        $guestPreview = $this->state($page, $view, 'preview=true');
        self::assertSame(401, $guestPreview['status'] ?? null, 'The draft is only for a signed-in editor.');
    }

    public function testThePageCarriesTheSameListStateAsTheEndpointAndNoInternalConfiguration(): void
    {
        [$page, $view] = $this->dataView('qa_lpc_page');
        $this->publish($page);
        $this->pages->setSectionField($view, 'list_fixed_filter', '');
        $this->pages->setSectionField($view, 'list_columns', '[{"field":"title"},{"field":"note"}]');
        $this->pages->invalidatePageScopedCaches();

        $endpoint = $this->assertEnvelopeSuccess($this->state($page, $view));
        $payload = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword(), null, null));
        $section = $this->sectionOf($payload, (int) $view->getId());

        self::assertSame($endpoint['pagination'] ?? null, $this->at($this->at($section, 'list_state'), 'pagination'), 'The first paint of the page and the list endpoint agree.');
        self::assertSame(15, $this->at($this->at($section, 'list_state'), 'pagination')['total']);
        $this->assertNoInternalConfiguration($payload, [self::SECRET_NOTE]);

        self::assertSame(403, $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?preview=true', null, $this->loginAsQaUser())['status'] ?? null);

        $draftPayload = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?preview=true', null, $this->loginAsQaAdmin()));
        $draftSection = $this->sectionOf($draftPayload, (int) $view->getId());
        self::assertSame(30, $this->at($this->at($draftSection, 'list_state'), 'pagination')['total'], 'A preview of the page is a preview of its lists.');
        $this->assertNoInternalConfiguration($draftPayload, []);
    }

    public function testTheRecordTemplateIsThePublishedOne(): void
    {
        [$page, $view] = $this->customDataView('qa_lpc_template');
        $this->recordTemplate($view, 'qa_lpc_template');
        $this->publish($page);

        // The author changes the line of the card to show a column the list does not show, and adds a card.
        $text = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lpc_template_text']);
        self::assertInstanceOf(Section::class, $text);
        foreach ([2, 3] as $languageId) {
            $this->pages->setSectionField($text, 'text', 'DRAFT {{city}} {{note}}', $languageId);
        }
        $extra = $this->pages->createSection('qa_lpc_template_extra', 'text');
        $this->pages->linkSectionToParent($view, $extra, 20);
        $this->pages->setSectionField($extra, 'text', 'A section of the draft', 3);
        $this->pages->invalidatePageScopedCaches();

        $public = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3'));
        $encoded = json_encode($public, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('DRAFT', $encoded);
        self::assertStringNotContainsString('A section of the draft', $encoded);
        self::assertStringNotContainsString(self::SECRET_NOTE, $encoded);
        self::assertCount(1, $this->listOf($this->listOf($public, 'rows')[0], 'sections'), 'One card per record, as published.');

        $draft = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=3&preview=true', $this->loginAsQaAdmin()));
        $encoded = json_encode($draft, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('DRAFT', $encoded);
        self::assertStringContainsString('A section of the draft', $encoded);
        self::assertStringNotContainsString(self::SECRET_NOTE, $encoded, 'Even a draft only reaches the columns the list shows.');
    }

    public function testTheConditionsAndTheRouteOfALayoutAreThePublishedOnesAndAPreviewFollowsTheDraft(): void
    {
        [$page, $view] = $this->customDataView('qa_lpc_logic', ['page_size' => '1']);
        $this->routeFor($page, '/qa-lpc-logic/{record_id}', ['record_id' => '\d+']);
        $card = $this->recordTemplate($view, 'qa_lpc_logic');
        $this->conditionalText($card, 'qa_lpc_logic_line', 'Published line', '{"==":["{{route.record_id}}","3"]}', 40);
        $this->publish($page);

        // The author keeps editing: the condition moves to another route value and a section is added.
        $line = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lpc_logic_line']);
        $card = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lpc_logic_card']);
        self::assertInstanceOf(Section::class, $line);
        self::assertInstanceOf(Section::class, $card);
        $line->setCondition('{"==":["{{route.record_id}}","4"]}');
        $this->em->flush();
        $this->conditionalText($card, 'qa_lpc_logic_draft', 'Draft line', '{"==":["{{route.record_id}}","4"]}', 50);
        $this->pages->invalidatePageScopedCaches();

        $texts = function (string $route, bool $preview) use ($page, $view): array {
            $query = 'language_id=3&route%5Brecord_id%5D=' . $route . ($preview ? '&preview=true' : '');
            $data = $this->assertEnvelopeSuccess($this->state($page, $view, $query, $preview ? $this->loginAsQaAdmin() : null));
            $card = $this->listOf($this->listOf($data, 'rows')[0], 'sections')[0] ?? [];
            $lines = [];
            foreach ($this->listOf($card, 'children') as $child) {
                $text = $child['text'] ?? null;
                $content = is_array($text) ? ($text['content'] ?? null) : $text;
                if (($child['style_name'] ?? null) === 'text' && is_string($content) && str_contains($content, 'line')) {
                    $lines[] = $content;
                }
            }

            return $lines;
        };

        self::assertSame(['Published line'], $texts('3', false), 'A visitor gets the condition that was published.');
        self::assertSame([], $texts('4', false), 'Neither the moved condition nor the new section of the draft reach a visitor.');
        self::assertSame([], $texts('3', true), 'A preview follows the draft: the condition now asks for 4.');
        self::assertSame(['Published line', 'Draft line'], $texts('4', true));
    }

    public function testWritesFollowThePublishedSwitchesAndInputsAndAPreviewFollowsTheDraft(): void
    {
        [$page, $list, $tableId, $form, $keys] = $this->declaredList('qa_lpc_write', [
            'title' => ['style' => 'text-input', 'fields' => ['is_required' => '1']],
            'note' => ['style' => 'text-input'],
        ], ['allow_update' => '0', 'allow_delete' => '1', 'show_history' => '1']);
        $this->publish($page);

        // The draft turns editing on and deleting off, drops the history, removes `note` and adds an input `extra`.
        $this->pages->setSectionField($list, 'allow_update', '1');
        $this->pages->setSectionField($list, 'allow_delete', '0');
        $this->pages->setSectionField($list, 'show_history', '0');
        $note = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lpc_write_in_note']);
        self::assertInstanceOf(Section::class, $note);
        $this->pages->setSectionField($note, 'name', '');
        $this->formInput($form, 'qa_lpc_write_in_extra', 'text-input', 'extra', 30);
        $this->pages->invalidatePageScopedCaches();

        $participant = $this->loginAsQaUser();
        $editor = $this->loginAsQaAdmin();
        $owner = $this->userId('qa.user@selfhelp.test');
        $adminOwner = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);
        $editable = $this->grid->row($tableId, $owner, [$keys['title'] => 'Editable']);
        $deletable = $this->grid->row($tableId, $owner, [$keys['title'] => 'Deletable']);
        $editableInPreview = $this->grid->row($tableId, $adminOwner, [$keys['title'] => 'Editable in the preview']);
        $deletableInPreview = $this->grid->row($tableId, $adminOwner, [$keys['title'] => 'Deletable in the preview']);

        // An edit: refused as published, accepted in the preview by an editor.
        $published = $this->update($participant, $page, $form, $editable, ['title' => 'Edited'], 1);
        self::assertSame(403, $published['status'] ?? null);
        self::assertSame('operation_disabled', $this->at($published, 'data')['reason'] ?? null);
        self::assertSame('Editable', $this->cell($editable, $keys['title']));
        self::assertSame(403, $this->update($participant, $page, $form, $editable, ['title' => 'Edited'], 1, preview: true)['status'] ?? null);
        $draft = $this->update($editor, $page, $form, $editableInPreview, ['title' => 'Edited'], 1, preview: true);
        self::assertSame(2, $this->assertEnvelopeSuccess($draft)['version'] ?? null);

        // A delete: accepted as published, refused in the preview.
        $draftDelete = $this->delete($editor, $page, $list, $deletableInPreview, 1, preview: true);
        self::assertSame(403, $draftDelete['status'] ?? null);
        self::assertSame('operation_disabled', $this->at($draftDelete, 'data')['reason'] ?? null);
        $this->assertEnvelopeSuccess($this->delete($participant, $page, $list, $deletable, 1));

        // The inputs of the form: `note` exists as published, `extra` only in the draft.
        $withNote = $this->submit($participant, $page, $form, ['title' => 'With note', 'note' => 'a note']);
        self::assertSame(200, $withNote['status'] ?? null, 'An input that was removed from the draft is still an input of the published form.');
        $withExtra = $this->submit($participant, $page, $form, ['title' => 'With extra', 'extra' => 'new']);
        self::assertSame(400, $withExtra['status'] ?? null);
        self::assertSame('unknown_field', $this->at($withExtra, 'data')['reason'] ?? null, 'An input that only the draft has is not writable for a visitor.');
        self::assertSame(403, $this->submit($participant, $page, $form, ['title' => 'With extra', 'extra' => 'new'], preview: true)['status'] ?? null);
        $previewExtra = $this->submit($editor, $page, $form, ['title' => 'With extra', 'extra' => 'new'], preview: true);
        self::assertSame(200, $previewExtra['status'] ?? null);
        $previewNote = $this->submit($editor, $page, $form, ['title' => 'With note', 'note' => 'a note'], preview: true);
        self::assertSame(400, $previewNote['status'] ?? null);
        self::assertSame('unknown_field', $this->at($previewNote, 'data')['reason'] ?? null);
        // The history: offered as published, not in the draft.
        $url = '/cms-api/v1/lists/' . $list->getId() . '/records/' . $editable . '/history?page_id=' . $page->getId();
        self::assertSame(200, $this->jsonRequest('GET', $url, null, $participant)['status'] ?? null);
        self::assertSame(403, $this->jsonRequest('GET', $url . '&preview=true', null, $participant)['status'] ?? null);
        $draftHistory = $this->jsonRequest('GET', '/cms-api/v1/lists/' . $list->getId() . '/records/' . $editableInPreview . '/history?page_id=' . $page->getId() . '&preview=true', null, $editor);
        self::assertSame(403, $draftHistory['status'] ?? null);
        self::assertSame('history_disabled', $this->at($draftHistory, 'data')['reason'] ?? null);
    }

    public function testAQuickActionIsThePublishedOne(): void
    {
        [$page, $list, $tableId, , $keys] = $this->declaredList('qa_lpc_action', [
            'title' => ['style' => 'text-input'],
            'status' => ['style' => 'text-input'],
        ]);
        $this->pages->setSectionField($list, 'quick_actions', json_encode([
            ['id' => 'done', 'label' => 'Done', 'set' => [$keys['status'] => 'done']],
        ], JSON_THROW_ON_ERROR));
        $this->publish($page);

        // The draft replaces the action by another one.
        $this->pages->setSectionField($list, 'quick_actions', json_encode([
            ['id' => 'archive', 'label' => 'Archive', 'set' => [$keys['status'] => 'archived']],
        ], JSON_THROW_ON_ERROR));
        $this->pages->invalidatePageScopedCaches();
        $participant = $this->loginAsQaUser();
        $editor = $this->loginAsQaAdmin();
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), [$keys['title'] => 'Mine', $keys['status'] => 'open']);
        $editors = $this->grid->row($tableId, $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL), [$keys['title'] => 'Editors', $keys['status'] => 'open']);
        $url = '/cms-api/v1/lists/' . $list->getId() . '/records/';

        $asPublished = $this->jsonRequest('POST', $url . $mine . '/actions/archive', ['page_id' => (int) $page->getId(), 'expected_version' => 1], $participant);
        self::assertSame(404, $asPublished['status'] ?? null, 'An action that only the draft has does not exist for a visitor.');
        self::assertSame('open', $this->cell($mine, $keys['status']));

        self::assertSame(403, $this->jsonRequest('POST', $url . $mine . '/actions/archive', ['page_id' => (int) $page->getId(), 'expected_version' => 1, 'preview' => true], $participant)['status'] ?? null);

        $inPreview = $this->jsonRequest('POST', $url . $editors . '/actions/archive', ['page_id' => (int) $page->getId(), 'expected_version' => 1, 'preview' => true], $editor);
        self::assertSame(200, $inPreview['status'] ?? null);
        self::assertSame('archived', $this->cell($editors, $keys['status']));

        $published = $this->jsonRequest('POST', $url . $mine . '/actions/done', ['page_id' => (int) $page->getId(), 'expected_version' => 1], $participant);
        self::assertSame(200, $published['status'] ?? null, 'The published action works.');
        self::assertSame('done', $this->cell($mine, $keys['status']));
    }

    public function testAParticipantPreviewIsRefusedOnEveryRouteAndChangesNothing(): void
    {
        [$page, $list, $tableId, $form, $keys] = $this->declaredList('qa_lpc_gate', [
            'title' => ['style' => 'text-input', 'fields' => ['is_required' => '1']],
        ], ['allow_update' => '1', 'allow_delete' => '1', 'show_history' => '1', 'allow_create' => '1']);
        $this->routeFor($page, (string) $page->getUrl());
        $this->pages->setSectionField($list, 'quick_actions', json_encode([
            ['id' => 'done', 'label' => 'Done', 'set' => [$keys['title'] => 'done']],
        ], JSON_THROW_ON_ERROR));
        $this->publish($page);
        $this->pages->setSectionField($list, 'list_columns', json_encode([
            ['field' => $keys['title'], 'label' => 'Draft only label'],
        ], JSON_THROW_ON_ERROR));
        $this->pages->invalidatePageScopedCaches();

        $participant = $this->loginAsQaUser();
        $owner = $this->userId('qa.user@selfhelp.test');
        $record = $this->grid->row($tableId, $owner, [$keys['title'] => 'Stay']);
        $before = [
            'rows' => $this->db->fetchAllAssociative('SELECT id, id_users, version, id_action_trigger_types FROM data_rows WHERE id_data_tables = :t ORDER BY id', ['t' => $tableId]),
            'cells' => $this->db->fetchAllAssociative('SELECT c.id_data_rows, col.field_key, c.id_languages, c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols JOIN data_rows r ON r.id = c.id_data_rows WHERE r.id_data_tables = :t ORDER BY c.id_data_rows, col.field_key, c.id_languages', ['t' => $tableId]),
            'revisions' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revisions rv JOIN data_rows r ON r.id = rv.id_data_rows WHERE r.id_data_tables = :t', ['t' => $tableId])),
            'jobs' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM scheduled_jobs')),
        ];
        $pageId = (int) $page->getId();
        $listId = (int) $list->getId();
        $formId = (int) $form->getId();
        $titleKey = $keys['title'];

        $paths = [
            ['GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?preview=true', null],
            ['GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode((string) $page->getUrl()) . '&preview=true', null],
            ['GET', '/cms-api/v1/lists/' . $listId . '/state?page_id=' . $pageId . '&preview=true', null],
            ['POST', '/cms-api/v1/forms/submit', ['page_id' => $pageId, 'section_id' => $formId, 'form_data' => ['title' => 'Nope'], 'preview' => true]],
            ['PUT', '/cms-api/v1/forms/update', ['page_id' => $pageId, 'section_id' => $formId, 'form_data' => ['title' => 'Nope'], 'update_based_on' => ['record_id' => $record], 'expected_version' => 1, 'preview' => true]],
            ['DELETE', '/cms-api/v1/forms/delete', ['page_id' => $pageId, 'section_id' => $listId, 'record_id' => $record, 'expected_version' => 1, 'preview' => true]],
            ['POST', '/cms-api/v1/lists/' . $listId . '/records/' . $record . '/actions/done', ['page_id' => $pageId, 'expected_version' => 1, 'preview' => true]],
            ['GET', '/cms-api/v1/lists/' . $listId . '/records/' . $record . '/history?page_id=' . $pageId . '&preview=true', null],
            ['GET', '/cms-api/v1/lists/' . $listId . '/records/' . $record . '/versions/1?page_id=' . $pageId . '&preview=true', null],
            ['GET', '/cms-api/v1/lists/' . $listId . '/records/' . $record . '/versions/1/files/' . $titleKey . '?page_id=' . $pageId . '&preview=true', null],
            ['POST', '/cms-api/v1/lists/' . $listId . '/records/' . $record . '/restore', ['page_id' => $pageId, 'version' => 1, 'expected_version' => 1, 'preview' => true]],
        ];

        foreach ($paths as [$method, $uri, $body]) {
            $response = $this->jsonRequest($method, $uri, $body, $participant);
            self::assertSame(403, $response['status'] ?? null, $method . ' ' . $uri);
            self::assertStringNotContainsString('Draft only label', json_encode($response, JSON_THROW_ON_ERROR));
        }

        $afterRefuse = [
            'rows' => $this->db->fetchAllAssociative('SELECT id, id_users, version, id_action_trigger_types FROM data_rows WHERE id_data_tables = :t ORDER BY id', ['t' => $tableId]),
            'cells' => $this->db->fetchAllAssociative('SELECT c.id_data_rows, col.field_key, c.id_languages, c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols JOIN data_rows r ON r.id = c.id_data_rows WHERE r.id_data_tables = :t ORDER BY c.id_data_rows, col.field_key, c.id_languages', ['t' => $tableId]),
            'revisions' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revisions rv JOIN data_rows r ON r.id = rv.id_data_rows WHERE r.id_data_tables = :t', ['t' => $tableId])),
            'jobs' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM scheduled_jobs')),
        ];
        self::assertSame($before, $afterRefuse);
        self::assertSame('Stay', $this->cell($record, $keys['title']));

        $permissionOnly = $this->editorWithoutGrant($page, 'qa_lpc_gate');
        self::assertSame(403, $this->state($page, $list, 'preview=true', $permissionOnly)['status'] ?? null, 'Permission without the page grant is not enough.');

        $this->assertEnvelopeSuccess($this->state($page, $list, 'preview=true', $this->editorWithGrant($page, 'qa_lpc_gate_ok')));
        $this->assertEnvelopeSuccess($this->state($page, $list, 'preview=true', $this->loginAsQaAdmin()));
    }

    public function testAccessRightsAndDataStayLiveWhileTheConfigurationIsPublished(): void
    {
        [$page, $list, , $form, $keys] = $this->declaredList('qa_lpc_live', ['title' => ['style' => 'text-input']], ['own_entries_only' => '1']);
        $this->publish($page);
        $token = $this->loginAsQaUser();
        self::assertSame([], $this->listOf($this->assertEnvelopeSuccess($this->state($page, $list, '', $token)), 'rows'));

        // A record that exists only after the publication is on the list at once: data are not versioned.
        $this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'Written after the publication']));
        self::assertSame(
            ['Written after the publication'],
            array_map(
                fn (array $row): string => self::coerceString($this->at($row, 'values')[$keys['title']] ?? ''),
                $this->listOf($this->assertEnvelopeSuccess($this->state($page, $list, '', $token)), 'rows'),
            ),
        );

        // The group loses the page: the published list answers like any page the visitor may not read.
        $this->db->executeStatement('DELETE FROM page_acl_groups WHERE id_pages = :page', ['page' => $page->getId()]);
        $this->pages->invalidatePageScopedCaches();
        self::assertSame(404, $this->state($page, $list, '', $token)['status'] ?? null);
        self::assertSame(404, $this->state($page, $list, 'preview=true', $token)['status'] ?? null, 'A preview does not widen the access either.');
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * Publishing needs the update grant (`PageVersionService::publishVersion`), so the service call
     * runs as the signed-in QA admin; the token is dropped again so the requests that follow
     * carry only their own bearer token.
     */
    private function publish(Page $page): void
    {
        $tokens = $this->service(TokenStorageInterface::class);
        $admin = $this->service(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $tokens->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        try {
            $versions = $this->service(PageVersionService::class);
            $version = $versions->createVersion((int) $page->getId());
            $versions->publishVersion((int) $page->getId(), (int) $version->getId());
        } finally {
            $tokens->setToken(null);
        }
        $this->pages->invalidatePageScopedCaches();
    }

    /**
     * An editor with `admin.page.update` and the update grant on this page.
     */
    private function editorWithGrant(Page $page, string $suffix): string
    {
        return $this->makeEditor($page, $suffix, withGrant: true);
    }

    /**
     * An editor with `admin.page.update` but no data-access grant on this page.
     */
    private function editorWithoutGrant(Page $page, string $suffix): string
    {
        return $this->makeEditor($page, $suffix, withGrant: false);
    }

    private function makeEditor(Page $page, string $suffix, bool $withGrant): string
    {
        // Reuse the seeded qa.editor persona (subject group, no admin role) and
        // hang a dedicated role on it so we can prove permission-only vs grant.
        //
        // F160: RoleDataAccessFactory::grantPageAccess fails after publish in
        // this test — LookupService hands back a detached Lookup, and Doctrine
        // refuses to flush RoleDataAccess#resourceType ("A new entity was found
        // through the relationship ... Lookup"). Keep the SQL grant until that
        // is fixed; do not treat this as the normal factory path.
        $roles = new RoleFactory($this->em);
        $security = $this->service(DataAccessSecurityService::class);
        $editor = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_EDITOR_EMAIL]);
        self::assertInstanceOf(User::class, $editor);

        $role = $roles->createRole('qa_preview_role_' . $suffix);
        $roles->grantPermission($role, 'admin.page.update');
        $editor->addRole($role);
        $this->em->flush();
        $security->invalidateUserPermissions((int) $editor->getId());

        if ($withGrant) {
            $resourceTypeId = self::coerceInt($this->db->fetchOne(
                'SELECT id FROM lookups WHERE type_code = :type AND lookup_code = :code',
                ['type' => LookupService::RESOURCE_TYPES, 'code' => LookupService::RESOURCE_TYPES_PAGES],
            ));
            $this->db->executeStatement(
                'INSERT INTO role_data_access (id_roles, id_resource_types, resource_id, crud_permissions)
                 VALUES (:role, :type, :page, :crud)',
                [
                    'role' => (int) $role->getId(),
                    'type' => $resourceTypeId,
                    'page' => (int) $page->getId(),
                    'crud' => DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE,
                ],
            );
            $security->invalidateRolePermissions((int) $role->getId());
        }

        return $this->loginAs(QaBaselineFixture::QA_EDITOR_EMAIL);
    }

    /**
     * @param array<string, string> $formData
     * @return array<string, mixed>
     */
    private function submit(string $token, Page $page, Section $form, array $formData, bool $preview = false): array
    {
        return $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $formData,
        ] + ($preview ? ['preview' => true] : []), $token);
    }

    /**
     * @param array<string, string> $formData
     * @return array<string, mixed>
     */
    private function update(string $token, Page $page, Section $form, int $recordId, array $formData, int $version, bool $preview = false): array
    {
        return $this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $formData,
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $version,
        ] + ($preview ? ['preview' => true] : []), $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $token, Page $page, Section $list, int $recordId, int $version, bool $preview = false): array
    {
        return $this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
            'expected_version' => $version,
        ] + ($preview ? ['preview' => true] : []), $token);
    }

    /**
     * The section of a page payload, wherever it sits in the tree.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sectionOf(array $payload, int $sectionId): array
    {
        $page = $this->at($payload, 'page');
        $found = $this->search($page['sections'] ?? [], $sectionId);
        self::assertNotNull($found, 'The section ' . $sectionId . ' is on the page.');

        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function search(mixed $sections, int $sectionId): ?array
    {
        if (!is_array($sections)) {
            return null;
        }
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (is_numeric($section['id'] ?? null) && (int) $section['id'] === $sectionId) {
                $keyed = [];
                foreach ($section as $key => $value) {
                    $keyed[(string) $key] = $value;
                }

                return $keyed;
            }
            $inside = $this->search($section['children'] ?? null, $sectionId);
            if ($inside !== null) {
                return $inside;
            }
        }

        return null;
    }

    /**
     * What a visitor's page must not carry about how a list reads its table: no field of the server-only set as a key
     * of a list section or of its stored translations, and none of the texts that only that configuration holds. The
     * state of the list (`list_state`) is what the client draws from and stays: it has its own `page_size`.
     *
     * @param array<string, mixed> $payload the whole `data` of the page endpoint
     * @param list<string> $texts values that must not appear anywhere in the response
     */
    private function assertNoInternalConfiguration(array $payload, array $texts): void
    {
        $lists = 0;
        $walk = function (mixed $sections) use (&$walk, &$lists): void {
            if (!is_array($sections)) {
                return;
            }
            foreach ($sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                if (in_array($section['style_name'] ?? null, StyleNames::LIST_STYLE_NAMES, true)) {
                    ++$lists;
                    foreach (FormListPageStateService::SERVER_ONLY_FIELDS as $field) {
                        self::assertArrayNotHasKey($field, $section, 'The server-only field "' . $field . '" is on a list section of the page.');
                        foreach (is_array($section['translations'] ?? null) ? $section['translations'] : [] as $byField) {
                            self::assertTrue(!is_array($byField) || !array_key_exists($field, $byField), 'The server-only field "' . $field . '" is in the translations of a list section.');
                        }
                    }
                    self::assertArrayHasKey('list_state', $section, 'The list brings what it draws.');
                }
                $walk($section['children'] ?? null);
            }
        };
        $walk($this->at($payload, 'page')['sections'] ?? null);
        self::assertGreaterThan(0, $lists, 'The page holds a list.');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ($texts as $text) {
            self::assertStringNotContainsString($text, $encoded);
        }
    }

    private function cell(int $recordId, string $key): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => $key],
        );

        return $value === false ? null : self::coerceString($value);
    }
}
