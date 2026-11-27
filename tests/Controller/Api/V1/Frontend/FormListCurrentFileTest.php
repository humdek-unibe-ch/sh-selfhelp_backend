<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageRoute;
use App\Entity\Section;
use App\Entity\User;
use App\Service\CMS\Common\StyleNames;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Section-scoped current file delivery (F165 / contract 1): page viewers of a
 * list that shows the record may open its files_public fields; anyone else
 * gets 404/401. /forms/files stays owner / table-READ only.
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListCurrentFileTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const BODY = "current file bytes\n";
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
    }

    public function testGuestOnOpenPublishedViewGetsTheImage(): void
    {
        $w = $this->world('qa_lcf_guest_ok', openAccess: true, filesPublic: true, publish: true);

        $state = $this->state($w['viewPage'], $w['view']);
        self::assertSame(200, $state['status'] ?? null, json_encode($state) ?: '');
        $stateData = self::asArray($state['data'] ?? null);
        self::assertNotEmpty(self::asList($stateData['rows'] ?? null), 'Published record must appear on the public view: ' . json_encode($state));

        [$response, $body] = $this->currentFile(null, $w['viewPage'], $w['view'], $w['record'], $w['doc']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $body);
        self::assertSame(self::BODY, $body);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testParticipantWithPageAccessGets200(): void
    {
        $w = $this->world('qa_lcf_member_ok', openAccess: false, filesPublic: true, publish: true);
        $page = $this->em->find(Page::class, $w['viewPage']->getId());
        self::assertInstanceOf(Page::class, $page);
        $this->pages->grantGroupAcl($page, $this->subjectGroup());
        $token = $this->loginAsQaUser();

        [$response, $body] = $this->currentFile($token, $page, $w['view'], $w['record'], $w['doc']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $body);
        self::assertSame(self::BODY, $body);
    }

    public function testParticipantWithoutPageAccessGets404(): void
    {
        $w = $this->world('qa_lcf_no_page', openAccess: false, filesPublic: true, publish: true);
        $outsider = $this->outsider('qa.lcf.out@selfhelp.test');

        [$response, $body] = $this->currentFile($outsider, $w['viewPage'], $w['view'], $w['record'], $w['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $body);
    }

    public function testDraftRecordOnPublicViewIs404(): void
    {
        $w = $this->world('qa_lcf_draft', openAccess: true, filesPublic: true, publish: false);

        [$response, $body] = $this->currentFile(null, $w['viewPage'], $w['view'], $w['record'], $w['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $body);
    }

    public function testOwnerScopedListSomeoneElsesRecordIs404(): void
    {
        [$cmsPage, $list, $tableId, $form, $keys] = $this->declaredList(
            'qa_lcf_own_cms',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
            ],
            ['own_entries_only' => '1', 'show_history' => '1'],
            false,
        );
        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lcf_own_cms_in_doc']);
        self::assertInstanceOf(Section::class, $docInput);
        $this->pages->setSectionField($docInput, 'files_public', '1');
        $this->uploadsOf((int) $form->getId());

        $ownerToken = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->save(
            $ownerToken,
            'POST',
            $cmsPage,
            $form,
            ['title' => 'Mine'],
            'shot.txt',
            self::BODY,
        ));
        $record = self::coerceInt($created['record_id'] ?? null);

        // Another signed-in subject who can see the CMS page still cannot open
        // someone else's file through an own-entries list.
        $other = $this->users->createUser('qa.lcf.other@selfhelp.test', 'Other', [$this->subjectGroup()]);
        $otherToken = $this->loginAs((string) $other->getEmail());

        [$response, $body] = $this->currentFile($otherToken, $cmsPage, $list, $record, $keys['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $body);
        self::assertStringNotContainsString(trim(self::BODY), $body);
        unset($tableId);
    }

    public function testFieldNotShownBySectionIs404(): void
    {
        $w = $this->world('qa_lcf_hidden_col', openAccess: true, filesPublic: true, publish: true);
        $this->pages->setSectionField($w['view'], 'list_columns', json_encode([
            ['field' => $w['title'], 'label' => 'Title'],
        ], JSON_THROW_ON_ERROR));
        $this->pages->invalidatePageScopedCaches();

        [$response, $body] = $this->currentFile(null, $w['viewPage'], $w['view'], $w['record'], $w['doc']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $body);
    }

    public function testGuestOnNonOpenPageGets401(): void
    {
        $w = $this->world('qa_lcf_guest_closed', openAccess: false, filesPublic: true, publish: true);

        [$response, $body] = $this->currentFile(null, $w['viewPage'], $w['view'], $w['record'], $w['doc']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $body);
    }

    public function testEntryRecordPublished200Draft404(): void
    {
        $pub = $this->entryRecordWorld('qa_lcf_er_pub', publish: true);
        [$ok, $okBody] = $this->currentFile(null, $pub['page'], $pub['recordSection'], $pub['record'], $pub['doc'], [
            'route' => ['record_id' => (string) $pub['record']],
        ]);
        self::assertSame(Response::HTTP_OK, $ok->getStatusCode(), $okBody);

        $draft = $this->entryRecordWorld('qa_lcf_er_draft', publish: false);
        [$denied, $deniedBody] = $this->currentFile(null, $draft['page'], $draft['recordSection'], $draft['record'], $draft['doc'], [
            'route' => ['record_id' => (string) $draft['record']],
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $denied->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $deniedBody);
    }

    public function testEntryListPublished200Draft404AndGuestClosed401(): void
    {
        $pub = $this->entryListWorld('qa_lcf_el_pub', openAccess: true, publish: true, filterPublished: true);
        [$ok, $okBody] = $this->currentFile(null, $pub['page'], $pub['list'], $pub['record'], $pub['doc']);
        self::assertSame(Response::HTTP_OK, $ok->getStatusCode(), $okBody);

        $draft = $this->entryListWorld('qa_lcf_el_draft', openAccess: true, publish: false, filterPublished: true);
        [$denied, $deniedBody] = $this->currentFile(null, $draft['page'], $draft['list'], $draft['record'], $draft['doc']);
        self::assertSame(Response::HTTP_NOT_FOUND, $denied->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $deniedBody);

        $closed = $this->entryListWorld('qa_lcf_el_closed', openAccess: false, publish: true, filterPublished: true);
        [$auth, $authBody] = $this->currentFile(null, $closed['page'], $closed['list'], $closed['record'], $closed['doc']);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $auth->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $authBody);
    }

    public function testSvgIsServedAsAttachment(): void
    {
        $w = $this->world('qa_lcf_svg', openAccess: true, filesPublic: true, publish: true);
        // Overwrite the stored bytes with SVG content so dispositionOf sees
        // image/svg+xml regardless of how the file was originally uploaded.
        $absolute = $this->writeSvgBesideRecord($w['record'], $w['doc']);
        self::assertNotNull($absolute);

        [$response] = $this->currentFile(null, $w['viewPage'], $w['view'], $w['record'], $w['doc']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $disposition);
    }

    public function testTemplateFieldNotReferencedIs404(): void
    {
        $w = $this->entryListWorld('qa_lcf_tpl', openAccess: true, publish: true, filterPublished: false, referenceDoc: false);
        [$response, $body] = $this->currentFile(null, $w['page'], $w['list'], $w['record'], $w['doc']);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringNotContainsString(trim(self::BODY), $body);
    }

    public function testSvgUploadIntoRasterAcceptIsRefused(): void
    {
        [$cmsPage, , $tableId, $form, $keys] = $this->declaredList(
            'qa_lcf_svg_refuse',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
            ],
            ['own_entries_only' => '0'],
            false,
        );
        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => 'qa_lcf_svg_refuse_in_doc']);
        self::assertInstanceOf(Section::class, $docInput);
        $this->pages->setSectionField($docInput, 'web_file_input_accept', 'image/jpeg,image/png,image/webp,image/gif');
        $this->pages->invalidatePageScopedCaches();
        $this->uploadsOf((int) $form->getId());

        $path = (string) tempnam(sys_get_temp_dir(), 'qa_lcf_bad_');
        file_put_contents($path, self::SVG);
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            [
                'page_id' => (string) $cmsPage->getId(),
                'section_id' => (string) $form->getId(),
                'title' => 'Nope',
            ],
            ['doc' => new UploadedFile($path, 'evil.svg', 'image/svg+xml', null, true)],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $this->loginAsQaUser()],
        );
        $envelope = $this->decode($this->client->getResponse());
        self::assertSame(422, $envelope['status'] ?? null, json_encode($envelope) ?: '');
        $errors = self::asList(self::asArray($envelope['data'] ?? null)['errors'] ?? null);
        $rules = array_map(static function (mixed $e): string {
            if (!is_array($e)) {
                return '';
            }
            $rule = $e['rule'] ?? null;

            return is_string($rule) ? $rule : '';
        }, $errors);
        self::assertContains('file_type', $rules, json_encode($envelope) ?: '');
        unset($tableId, $keys);
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group);

        return $group;
    }

    public function testFormsFilesNoLongerServesGuestEvenWhenFilesPublic(): void
    {
        $w = $this->world('qa_lcf_forms_files', openAccess: true, filesPublic: true, publish: true);

        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/forms/files/%d/%s', $w['record'], $w['doc']),
            ['index' => '0'],
        );
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/forms/files/%d/%s', $w['record'], $w['doc']),
            ['index' => '0'],
            [],
            ['HTTP_Authorization' => 'Bearer ' . $w['token']],
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testListStateFileCellCarriesServerUrlNeverStorageKey(): void
    {
        $w = $this->world('qa_lcf_state', openAccess: true, filesPublic: true, publish: true);
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/lists/%d/state', $w['view']->getId()),
            ['page_id' => (string) $w['viewPage']->getId()],
        );
        $data = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $rows = self::asList($data['rows'] ?? null);
        self::assertNotEmpty($rows);
        $firstRow = self::asArray($rows[0] ?? null);
        $values = self::asArray($firstRow['values'] ?? null);
        $cell = self::asArray($values[$w['doc']] ?? null);
        self::assertArrayHasKey('files', $cell);
        $files = self::asList($cell['files'] ?? null);
        self::assertNotEmpty($files);
        $firstFile = self::asArray($files[0] ?? null);
        $url = self::asString($firstFile['url'] ?? null);
        self::assertStringContainsString('/lists/', $url);
        self::assertStringContainsString('/files/', $url);
        self::assertStringNotContainsString('uploads/form-files/', $url);
        self::assertStringNotContainsString('uploads/form-files/', json_encode($rows) ?: '');
    }

    /**
     * @return array{
     *   cmsPage: Page,
     *   list: Section,
     *   form: Section,
     *   viewPage: Page,
     *   view: Section,
     *   doc: string,
     *   title: string,
     *   record: int,
     *   token: string
     * }
     */
    private function world(string $prefix, bool $openAccess, bool $filesPublic, bool $publish): array
    {
        [$cmsPage, $list, $tableId, $form, $keys] = $this->declaredList(
            $prefix . '_cms',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
                'status' => ['style' => 'select'],
            ],
            ['own_entries_only' => '0', 'show_history' => '1'],
            false,
        );
        $this->uploadsOf((int) $form->getId());

        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => $prefix . '_cms_in_doc']);
        self::assertInstanceOf(Section::class, $docInput, 'Expected the file-input section of the fixture form.');
        $this->pages->setSectionField($docInput, 'files_public', $filesPublic ? '1' : '0');

        [$viewPage, $view] = $this->publicView($prefix . '_pub', $tableId, $keys, $openAccess);

        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->save(
            $token,
            'POST',
            $cmsPage,
            $form,
            ['title' => 'Photo', 'status' => $publish ? 'published' : 'draft'],
            'shot.txt',
            self::BODY,
        ));
        $record = self::coerceInt($created['record_id'] ?? null);

        return [
            'cmsPage' => $cmsPage,
            'list' => $list,
            'form' => $form,
            'viewPage' => $viewPage,
            'view' => $view,
            'doc' => $keys['doc'],
            'title' => $keys['title'],
            'record' => $record,
            'token' => $token,
        ];
    }

    /**
     * @return array{page: Page, recordSection: Section, doc: string, record: int}
     */
    private function entryRecordWorld(string $prefix, bool $publish): array
    {
        [$cmsPage, , $tableId, $form, $keys] = $this->declaredList(
            $prefix . '_cms',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
                'status' => ['style' => 'select'],
            ],
            ['own_entries_only' => '0'],
            false,
        );
        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => $prefix . '_cms_in_doc']);
        self::assertInstanceOf(Section::class, $docInput);
        $this->pages->setSectionField($docInput, 'files_public', '1');
        $this->uploadsOf((int) $form->getId());

        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->save(
            $token,
            'POST',
            $cmsPage,
            $form,
            ['title' => 'Photo', 'status' => $publish ? 'published' : 'draft'],
            'shot.txt',
            self::BODY,
        ));
        $record = self::coerceInt($created['record_id'] ?? null);

        $page = $this->pages->createPage($prefix . '_detail', true);
        $route = new PageRoute();
        $route->setPage($page);
        $route->setPathPattern('/' . $prefix . '/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $holder = $this->pages->createSection($prefix . '_er', StyleNames::STYLE_ENTRY_RECORD);
        $this->pages->linkSectionToPage($page, $holder, 10);
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
        // Sanity: the fixed_filter field must exist after the F166 migration.
        self::assertNotFalse(
            $this->em->getConnection()->fetchOne(
                'SELECT 1 FROM rel_fields_styles rfs
                 JOIN styles s ON s.id = rfs.id_styles JOIN fields f ON f.id = rfs.id_fields
                 WHERE s.name = ? AND f.name = ?',
                ['entry-record', 'fixed_filter'],
            ),
            'entry-record.fixed_filter must be seeded',
        );
        $img = $this->pages->createSection($prefix . '_img', 'image');
        $this->pages->linkSectionToParent($holder, $img, 1);
        $urlToken = '{{item.' . $this->inputName($keys['doc']) . '__url}}';
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField($img, 'img_src', $urlToken, $languageId);
        }
        $this->pages->invalidatePageScopedCaches();

        return ['page' => $page, 'recordSection' => $holder, 'doc' => $keys['doc'], 'record' => $record];
    }

    /**
     * @return array{page: Page, list: Section, doc: string, record: int}
     */
    private function entryListWorld(string $prefix, bool $openAccess, bool $publish, bool $filterPublished, bool $referenceDoc = true): array
    {
        [$cmsPage, , $tableId, $form, $keys] = $this->declaredList(
            $prefix . '_cms',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
                'status' => ['style' => 'select'],
            ],
            ['own_entries_only' => '0'],
            false,
        );
        $docInput = $this->em->getRepository(Section::class)->findOneBy(['name' => $prefix . '_cms_in_doc']);
        self::assertInstanceOf(Section::class, $docInput);
        $this->pages->setSectionField($docInput, 'files_public', '1');
        $this->uploadsOf((int) $form->getId());

        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->save(
            $token,
            'POST',
            $cmsPage,
            $form,
            ['title' => 'Photo', 'status' => $publish ? 'published' : 'draft'],
            'shot.txt',
            self::BODY,
        ));
        $record = self::coerceInt($created['record_id'] ?? null);

        $page = $this->pages->createPage($prefix . '_list', $openAccess);
        $list = $this->pages->createSection($prefix . '_el', StyleNames::STYLE_ENTRY_LIST);
        $this->pages->linkSectionToPage($page, $list, 10);
        $fields = [
            'data_table' => (string) $tableId,
            'own_entries_only' => '0',
            'scope' => 'item',
            // Engine columns are field keys; authors often write input names —
            // both must work. Prefer the field key here so the page SQL path
            // and the file-route structured filter agree.
            'filter' => $filterPublished ? $keys['status'] . " = 'published'" : '',
        ];
        foreach ($fields as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }
        $card = $this->pages->createSection($prefix . '_card', 'text');
        $this->pages->linkSectionToParent($list, $card, 1);
        $text = $referenceDoc
            ? '{{item.title}} {{item.' . $this->inputName($keys['doc']) . '__url}}'
            : '{{item.title}}';
        foreach ([1, 2, 3] as $languageId) {
            $this->pages->setSectionField($card, 'text', $text, $languageId);
        }
        $this->pages->invalidatePageScopedCaches();

        return ['page' => $page, 'list' => $list, 'doc' => $keys['doc'], 'record' => $record];
    }

    /**
     * @param array<string, string> $keys
     * @return array{0: Page, 1: Section}
     */
    private function publicView(string $prefix, int $tableId, array $keys, bool $openAccess): array
    {
        $page = $this->pages->createPage($prefix, $openAccess);
        $view = $this->pages->createSection($prefix . '_view', StyleNames::STYLE_DATA_VIEW);
        $this->pages->linkSectionToPage($page, $view, 10);
        foreach ([
            'list_key' => 'pub',
            'data_table' => (string) $tableId,
            'list_layout' => 'cards',
            'page_size' => '12',
            'own_entries_only' => '0',
            'list_columns' => json_encode([
                ['field' => $keys['title'], 'label' => 'Title'],
                ['field' => $keys['doc'], 'label' => 'Doc', 'format' => 'file'],
                ['field' => $keys['status'], 'label' => 'Status', 'format' => 'badge'],
            ], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => json_encode([
                'mode' => 'and',
                'children' => [['column' => $keys['status'], 'operator' => 'eq', 'value' => 'published']],
            ], JSON_THROW_ON_ERROR),
        ] as $name => $content) {
            $this->pages->setSectionField($view, $name, $content);
        }

        return [$page, $view];
    }

    private function outsider(string $email): string
    {
        $this->users->createUser($email, $email, []);

        return $this->loginAs($email);
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

    /**
     * Replace the stored file of a record with an SVG so disposition can be asserted.
     */
    private function writeSvgBesideRecord(int $recordId, string $fieldKey): ?string
    {
        $uploads = $this->service(\App\Service\CMS\FormFileUploadService::class);
        $raw = $uploads->cellValue($recordId, $fieldKey);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $keys = \App\Service\CMS\FormFileUploadService::keysIn($raw);
        $key = $keys[0] ?? null;
        if ($key === null) {
            return null;
        }
        $absolute = $uploads->resolveKey($key);
        if ($absolute === null) {
            return null;
        }
        file_put_contents($absolute, self::SVG);

        return $absolute;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function save(string $token, string $method, Page $page, Section $form, array $fields, string $name, string $contents): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_lcf_');
        file_put_contents($path, $contents);
        $this->client->request(
            $method,
            $method === 'POST' ? '/cms-api/v1/forms/submit' : '/cms-api/v1/forms/update',
            ['page_id' => (string) $page->getId(), 'section_id' => (string) $form->getId()] + $fields,
            ['doc' => new UploadedFile($path, $name, null, null, true)],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token],
        );

        return $this->decode($this->client->getResponse());
    }

    /**
     * @param array<string, mixed> $extraQuery
     * @return array{0: Response, 1: string}
     */
    private function currentFile(?string $token, Page $page, Section $list, int $record, string $field, array $extraQuery = []): array
    {
        $query = ['page_id' => (string) $page->getId(), 'index' => '0'] + $extraQuery;
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/lists/%d/records/%d/files/%s', $list->getId(), $record, $field),
            $query,
            [],
            $token === null ? [] : ['HTTP_Authorization' => 'Bearer ' . $token],
        );

        return [$this->client->getResponse(), (string) $this->client->getInternalResponse()->getContent()];
    }
}
