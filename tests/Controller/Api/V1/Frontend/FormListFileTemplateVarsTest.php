<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Page;
use App\Entity\PageRoute;
use App\Entity\Section;
use App\Service\CMS\Common\StyleNames;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-A4 / F5-P2-A1: entry-list, entry-record and data-view row templates expose
 * section-scoped `{{field__url}}` / `{{field__files}}` / `{{field__links}}`.
 */
#[PhpUnitGroup('form-list')]
final class FormListFileTemplateVarsTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const BODY_A = "template-var-bytes-a\n";
    private const BODY_B = "template-var-bytes-b\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testEntryListAndEntryRecordAndDataViewCarrySectionScopedUrlsAndLinks(): void
    {
        [$cmsPage, , $tableId, $form, $keys] = $this->declaredList(
            'qa_ftv_cms',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
                'status' => ['style' => 'select'],
            ],
            ['own_entries_only' => '0'],
            false,
        );
        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_ftv_cms_in_doc']);
        self::assertInstanceOf(Section::class, $docInput);
        $this->pages->setSectionField($docInput, 'files_public', '1');
        $this->pages->setSectionField($docInput, 'web_file_input_multiple', '1');
        $this->pages->invalidatePageScopedCaches();
        $this->uploadsOf((int) $form->getId());

        $token = $this->loginAsQaUser();
        $pathA = (string) tempnam(sys_get_temp_dir(), 'qa_ftv_a_');
        $pathB = (string) tempnam(sys_get_temp_dir(), 'qa_ftv_b_');
        file_put_contents($pathA, self::BODY_A);
        file_put_contents($pathB, self::BODY_B);
        // Two files via the FormFileDeliveryTest multi-file shape.
        $uploads = [
            new UploadedFile($pathA, 'alpha.txt', null, null, true),
            new UploadedFile($pathB, 'beta.txt', null, null, true),
        ];
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            [
                'page_id' => (string) $cmsPage->getId(),
                'section_id' => (string) $form->getId(),
                'title' => 'Photo',
                'status' => 'published',
            ],
            ['doc' => $uploads],
            ['HTTP_Authorization' => 'Bearer ' . $token],
        );
        $created = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $record = self::coerceInt($created['record_id'] ?? null);
        $docName = $this->inputName($keys['doc']);
        $stored = self::coerceString($this->em->getConnection()->fetchOne(
            "SELECT value FROM data_cells c
             JOIN data_cols col ON col.id = c.id_data_cols
             WHERE c.id_data_rows = :id AND col.field_key = :col LIMIT 1",
            ['id' => $record, 'col' => $keys['doc']],
        ));
        self::assertNotSame('', $stored, 'Uploads must land in the doc cell; response=' . json_encode($created));
        self::assertGreaterThanOrEqual(2, count(\App\Service\CMS\FormFileUploadService::keysIn($stored)), $stored);

        // --- data-view state cell + row-template tokens ---
        $viewPage = $this->pages->createPage('qa_ftv_view', true);
        $view = $this->pages->createSection('qa_ftv_dv', StyleNames::STYLE_DATA_VIEW);
        $this->pages->linkSectionToPage($viewPage, $view, 10);
        foreach ([
            'list_key' => 'pub',
            'data_table' => (string) $tableId,
            'own_entries_only' => '0',
            'list_layout' => 'custom',
            'list_columns' => json_encode([
                ['field' => $keys['title'], 'label' => 'Title'],
                ['field' => $keys['doc'], 'label' => 'Doc', 'format' => 'file'],
            ], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => json_encode([
                'mode' => 'and',
                'children' => [['column' => $keys['status'], 'operator' => 'eq', 'value' => 'published']],
            ], JSON_THROW_ON_ERROR),
        ] as $name => $content) {
            $this->pages->setSectionField($view, $name, $content);
        }
        $tpl = $this->pages->createSection('qa_ftv_dv_tpl', 'text');
        $this->pages->linkSectionToParent($view, $tpl, 1);
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField($tpl, 'text', '{{' . $docName . '__links}}', $languageId);
        }
        $this->pages->invalidatePageScopedCaches();

        $this->client->request('GET', '/cms-api/v1/lists/' . $view->getId() . '/state', [
            'page_id' => (string) $viewPage->getId(),
        ]);
        $state = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $rows = self::asList($state['rows'] ?? null);
        self::assertNotEmpty($rows);
        $values = self::asArray(self::asArray($rows[0])['values'] ?? null);
        $cell = self::asArray($values[$keys['doc']] ?? $values[$docName] ?? null);
        $files = self::asList($cell['files'] ?? null);
        self::assertCount(
            2,
            $files,
            'values=' . json_encode($values) . ' stored=' . $stored,
        );
        $url0 = self::asString(self::asArray($files[0])['url'] ?? null);
        self::assertStringContainsString('/lists/' . $view->getId() . '/records/' . $record . '/files/', $url0);
        self::assertStringContainsString('page_id=' . $viewPage->getId(), $url0);
        self::assertStringNotContainsString('uploads/form-files/', $url0);

        $rowSections = self::asList(self::asArray($rows[0])['sections'] ?? null);
        $rowText = '';
        foreach ($rowSections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $text = $section['text'] ?? null;
            if (is_array($text) && is_string($text['content'] ?? null)) {
                $rowText .= $text['content'];
            }
        }
        self::assertStringContainsString('alpha.txt', $rowText);
        self::assertStringContainsString('beta.txt', $rowText);
        self::assertMatchesRegularExpression(
            '#<a href="/lists/' . $view->getId() . '/records/' . $record . '/files/[^"]*index=0">alpha\.txt</a>#',
            $rowText,
        );
        self::assertMatchesRegularExpression(
            '#<a href="/lists/' . $view->getId() . '/records/' . $record . '/files/[^"]*index=1">beta\.txt</a>#',
            $rowText,
        );

        // --- entry-list page render ---
        $listPage = $this->pages->createPage('qa_ftv_el', true);
        $list = $this->pages->createSection('qa_ftv_el_sec', StyleNames::STYLE_ENTRY_LIST);
        $this->pages->linkSectionToPage($listPage, $list, 10);
        foreach ([
            'data_table' => (string) $tableId,
            'own_entries_only' => '0',
            'scope' => 'item',
            'filter' => $keys['status'] . " = 'published'",
        ] as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }
        $card = $this->pages->createSection('qa_ftv_el_card', 'text');
        $this->pages->linkSectionToParent($list, $card, 1);
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField($card, 'text', '{{item.' . $docName . '__links}}', $languageId);
        }
        $this->pages->invalidatePageScopedCaches();

        $this->client->request('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword(), [
            'platform' => 'web',
            'language_id' => '2',
        ]);
        $listPayload = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $listJson = json_encode($listPayload, JSON_UNESCAPED_SLASHES) ?: '';
        self::assertStringContainsString('alpha.txt', $listJson);
        self::assertStringContainsString('beta.txt', $listJson);
        self::assertStringContainsString('/lists/' . $list->getId() . '/records/' . $record . '/files/', $listJson);
        self::assertStringContainsString('page_id=' . $listPage->getId(), $listJson);
        self::assertStringContainsString('<a href=', $listJson);

        // --- entry-record page render ---
        $detailPage = $this->pages->createPage('qa_ftv_er', true);
        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-ftv-er/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();
        $holder = $this->pages->createSection('qa_ftv_er_sec', StyleNames::STYLE_ENTRY_RECORD);
        $this->pages->linkSectionToPage($detailPage, $holder, 10);
        $filter = json_encode([
            'mode' => 'and',
            'children' => [['column' => $keys['status'], 'operator' => 'eq', 'value' => 'published']],
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'data_table' => (string) $tableId,
            'own_entries_only' => '0',
            'load_record_from' => 'record_id',
            'scope' => 'item',
            'fixed_filter' => $filter,
        ] as $name => $content) {
            $this->pages->setSectionField($holder, $name, $content);
        }
        $dl = $this->pages->createSection('qa_ftv_er_dl', 'text');
        $this->pages->linkSectionToParent($holder, $dl, 1);
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField($dl, 'text', '{{item.' . $docName . '__links}}', $languageId);
        }
        $this->pages->invalidatePageScopedCaches();

        $this->client->request(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-ftv-er/' . $record),
        );
        $detailPayload = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $detailText = '';
        $stack = self::asList(self::asArray($detailPayload['page'] ?? null)['sections'] ?? null);
        while ($stack !== []) {
            $section = array_shift($stack);
            if (!is_array($section)) {
                continue;
            }
            foreach (self::asList($section['children'] ?? null) as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
            $text = $section['text'] ?? null;
            if (is_array($text) && is_string($text['content'] ?? null)) {
                $detailText .= $text['content'];
            }
        }
        self::assertStringContainsString('alpha.txt', $detailText);
        self::assertStringContainsString('beta.txt', $detailText);
        self::assertStringContainsString('/lists/' . $holder->getId() . '/records/' . $record . '/files/', $detailText);
        self::assertStringContainsString('page_id=' . $detailPage->getId(), $detailText);
        self::assertMatchesRegularExpression('#<a href="/lists/[^"]+">alpha\.txt</a>#', $detailText);
        self::assertMatchesRegularExpression('#<a href="/lists/[^"]+">beta\.txt</a>#', $detailText);

        // File bytes last: a BinaryFileResponse can leave the test client unable
        // to match later JSON routes in this process.
        $this->client->request('GET', '/cms-api/v1' . $url0);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::BODY_A, (string) $this->client->getInternalResponse()->getContent());

        // Guest on a closed page that reuses the same URL shape → 401
        $closed = $this->pages->createPage('qa_ftv_closed', false);
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/lists/%d/records/%d/files/%s', $view->getId(), $record, $keys['doc']),
            ['page_id' => (string) $closed->getId(), 'index' => '0'],
        );
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * F177 / F6-P1-A1: a visitor text that imitates `__links` or a list-file URL
     * is escaped on the public list and detail; server-built `__links` stay raw.
     */
    public function testVisitorImitationOfFileLinksIsEscapedOnPublicSurfaces(): void
    {
        [$cmsPage, , $tableId, $form, $keys] = $this->declaredList(
            'qa_ftv_xss',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
                'status' => ['style' => 'select'],
            ],
            ['own_entries_only' => '0'],
            false,
        );
        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_ftv_xss_in_doc']);
        self::assertInstanceOf(Section::class, $docInput);
        $this->pages->setSectionField($docInput, 'files_public', '1');
        $this->pages->invalidatePageScopedCaches();
        $this->uploadsOf((int) $form->getId());

        $xssHtml = '<a href="/lists/1/files/">x</a><img src=https://evil.example/t.png>'
            . '<a href="https://evil.example/login">Reset password</a>';
        $xssUrl = '/lists/1/files/" onerror="alert(1)';
        $token = $this->loginAsQaUser();
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_ftv_xss_');
        file_put_contents($path, "safe-bytes\n");
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            [
                'page_id' => (string) $cmsPage->getId(),
                'section_id' => (string) $form->getId(),
                'title' => $xssHtml . '|' . $xssUrl,
                'status' => 'published',
            ],
            ['doc' => [new UploadedFile($path, 'safe.txt', null, null, true)]],
            ['HTTP_Authorization' => 'Bearer ' . $token],
        );
        $created = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $record = self::coerceInt($created['record_id'] ?? null);
        $docName = $this->inputName($keys['doc']);
        $titleName = $this->inputName($keys['title']);

        $listPage = $this->pages->createPage('qa_ftv_xss_el', true);
        $list = $this->pages->createSection('qa_ftv_xss_el_sec', StyleNames::STYLE_ENTRY_LIST);
        $this->pages->linkSectionToPage($listPage, $list, 10);
        foreach ([
            'data_table' => (string) $tableId,
            'own_entries_only' => '0',
            'scope' => 'item',
            'filter' => $keys['status'] . " = 'published'",
        ] as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }
        $card = $this->pages->createSection('qa_ftv_xss_card', 'text');
        $this->pages->linkSectionToParent($list, $card, 1);
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField(
                $card,
                'text',
                '{{item.' . $titleName . '}} {{item.' . $docName . '__links}}',
                $languageId,
            );
        }
        $this->pages->invalidatePageScopedCaches();

        $this->client->request('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword(), [
            'platform' => 'web',
            'language_id' => '2',
        ]);
        $listPayload = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $listText = $this->collectTextContents($listPayload);
        self::assertStringContainsString('&lt;a href=', $listText);
        self::assertStringNotContainsString('<img src=https://evil.example/t.png>', $listText);
        self::assertStringNotContainsString('onerror="alert(1)"', $listText);
        self::assertMatchesRegularExpression('#<a href="/lists/[^"]+">safe\.txt</a>#', $listText);

        $detailPage = $this->pages->createPage('qa_ftv_xss_er', true);
        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-ftv-xss-er/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();
        $holder = $this->pages->createSection('qa_ftv_xss_er_sec', StyleNames::STYLE_ENTRY_RECORD);
        $this->pages->linkSectionToPage($detailPage, $holder, 10);
        $filter = json_encode([
            'mode' => 'and',
            'children' => [['column' => $keys['status'], 'operator' => 'eq', 'value' => 'published']],
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'data_table' => (string) $tableId,
            'own_entries_only' => '0',
            'load_record_from' => 'record_id',
            'scope' => 'item',
            'fixed_filter' => $filter,
        ] as $name => $content) {
            $this->pages->setSectionField($holder, $name, $content);
        }
        $body = $this->pages->createSection('qa_ftv_xss_er_body', 'text');
        $this->pages->linkSectionToParent($holder, $body, 1);
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField(
                $body,
                'text',
                '{{item.' . $titleName . '}} {{item.' . $docName . '__links}}',
                $languageId,
            );
        }
        $this->pages->invalidatePageScopedCaches();

        $this->client->request(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-ftv-xss-er/' . $record),
        );
        $detailPayload = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $detailText = $this->collectTextContents($detailPayload);
        self::assertStringContainsString('&lt;a href=', $detailText);
        self::assertStringNotContainsString('<img src=https://evil.example/t.png>', $detailText);
        self::assertStringNotContainsString('onerror="alert(1)"', $detailText);
        self::assertMatchesRegularExpression('#<a href="/lists/[^"]+">safe\.txt</a>#', $detailText);
    }

    /**
     * Concatenate every `text.content` in a page payload (list card or detail).
     *
     * @param array<string, mixed> $payload
     */
    private function collectTextContents(array $payload): string
    {
        $out = '';
        $stack = self::asList(self::asArray($payload['page'] ?? null)['sections'] ?? null);
        while ($stack !== []) {
            $section = array_shift($stack);
            if (!is_array($section)) {
                continue;
            }
            foreach (self::asList($section['children'] ?? null) as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
            $text = $section['text'] ?? null;
            if (is_array($text) && is_string($text['content'] ?? null)) {
                $out .= $text['content'];
            }
        }

        return $out;
    }

    /**
     * Remapped input name for a section_<id> field key (template tokens use names).
     */
    private function inputName(string $fieldKey): string
    {
        if (!str_starts_with($fieldKey, 'section_')) {
            return $fieldKey;
        }
        $sectionId = (int) substr($fieldKey, strlen('section_'));
        $name = $this->em->getConnection()->fetchOne(
            'SELECT content FROM sections_fields_translation sft
             JOIN fields f ON f.id = sft.id_fields
             WHERE sft.id_sections = :s AND f.name = :n LIMIT 1',
            ['s' => $sectionId, 'n' => 'name'],
        );

        return is_string($name) && $name !== '' ? $name : $fieldKey;
    }
}
