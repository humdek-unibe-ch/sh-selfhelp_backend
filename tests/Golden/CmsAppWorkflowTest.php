<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Page;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Golden CMS Apps lifecycle (first-class product unit):
 *
 *   create empty shell -> scaffold pages -> delete shell (pages retained) ->
 *   verify app is gone and pages are unassigned.
 *
 * Also: draft/publish on a media-capable scaffold (P1-A6 / P1-A7).
 */
#[Group('golden')]
final class CmsAppWorkflowTest extends QaWebTestCase
{
    private const SLUG = 'qa-cms-app-workflow';
    private const BASE = 'qa-cms-app-workflow-base';

    public function testCreateScaffoldDeleteShellLifecycle(): void
    {
        $admin = $this->loginAsQaAdmin();

        $createdApp = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => 'QA CMS App Workflow',
                'slug' => self::SLUG,
            ], $admin),
            201
        );

        self::assertIsInt($createdApp['id'] ?? null, 'Created CMS app must expose an integer id');
        $appId = $createdApp['id'];

        $scaffold = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId),
            [
                'base_name' => self::BASE,
                'create_form' => true,
                'create_public' => false,
                'create_admin' => true,
                'form_field_name' => 'title',
            ],
            $admin
        );
        $scaffoldData = $this->assertEnvelopeSuccess($scaffold, 201);
        $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/scaffold');

        $created = is_array($scaffoldData['created'] ?? null) ? $scaffoldData['created'] : [];
        self::assertNotEmpty($created, 'Scaffold must create at least the CMS list page.');
        $pageIds = [];
        foreach ($created as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $pageIds[] = $entry['page_id'];
            }
        }

        try {
            $detail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/detail');
            self::assertNotNull($detail['id_cms_list_page'] ?? null, 'Hub sync must set cms_list after scaffold.');

            $deleteShell = $this->jsonRequest(
                'DELETE',
                sprintf('/cms-api/v1/admin/cms-apps/%d', $appId),
                null,
                $admin
            );
            $this->assertEnvelopeSuccess($deleteShell);
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/delete');

            $gone = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
            self::assertSame(404, $gone['status'] ?? 0, 'App shell row must be deleted.');

            foreach ($pageIds as $pageId) {
                $list = $this->assertEnvelopeSuccess(
                    $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $admin)
                );
                $pages = $list['pages'] ?? $list;
                if (!is_array($pages)) {
                    self::fail('Admin pages list must be an array.');
                }
                $row = null;
                foreach ($pages as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    $candidatePageId = $candidate['id_pages'] ?? null;
                    if (is_int($candidatePageId) && $candidatePageId === $pageId) {
                        $row = $candidate;
                        break;
                    }
                }
                self::assertNotNull($row, sprintf('Page %d must remain in the admin pages list.', $pageId));
                self::assertNull($row['cms_app_id'] ?? null, 'Delete shell must unassign pages.');
                self::assertNull($row['cms_app_role'] ?? null, 'Delete shell must clear page roles.');
            }
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    /**
     * Draft is absent from the public list; publish brings it; unpublish hides it.
     * Full media file cycle: CmsAppWizardTest::testPublishUnpublishShowsAndHidesPublicListDetailAndFile.
     */
    public function testDraftPublishUnpublishOnPublicList(): void
    {
        $admin = $this->loginAsQaAdmin();
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_STYLES)
            ->invalidateAllListsInCategory();

        $createdApp = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => 'QA Publish Cycle',
                'slug' => 'qa-cms-app-publish-cycle',
            ], $admin),
            201
        );
        $appId = self::coerceInt($createdApp['id'] ?? null);
        $base = 'qa-cms-wf-pub';
        $scaffold = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId), [
                'base_name' => $base,
                'create_form' => true,
                'create_public' => true,
                'create_admin' => true,
                'with_status' => true,
                // Title only: an image column with no upload yet has no data_col
                // and would 400 the public list. Media cycle is in CmsAppWizardTest.
                'form_fields' => [
                    ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
                ],
            ], $admin),
            201
        );
        $byRole = [];
        $pageIds = [];
        foreach (self::asList($scaffold['created'] ?? null) as $entry) {
            $row = self::asArray($entry);
            $pageId = self::coerceInt($row['page_id'] ?? null);
            $pageIds[] = $pageId;
            $role = self::coerceString($row['role'] ?? null);
            if ($role !== '') {
                $byRole[$role] = $pageId;
            }
        }

        try {
            $em = $this->service(EntityManagerInterface::class);
            foreach (['public_list', 'public_detail'] as $role) {
                $page = $em->find(Page::class, $byRole[$role]);
                self::assertInstanceOf(Page::class, $page);
                $page->setIsOpenAccess(true);
            }
            $em->flush();

            $listId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $viewId = $this->firstSectionIdByStyle($byRole['public_list'], 'data-view', $admin);

            $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $byRole['cms_list'],
                'section_id' => $formId,
                'form_data' => ['title' => 'Hidden draft', 'status' => 'draft'],
            ], $admin));
            $recordId = self::coerceInt($created['record_id'] ?? null);
            $version = self::coerceInt($created['version'] ?? null);

            $publicState = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                sprintf('/cms-api/v1/lists/%d/state?page_id=%d', $viewId, $byRole['public_list']),
            ));
            self::assertSame([], $this->recordIds($publicState));

            $detail = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            self::assertStringNotContainsString('Hidden draft', json_encode($detail) ?: '');

            $published = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/lists/%d/records/%d/actions/publish', $listId, $recordId),
                ['page_id' => $byRole['cms_list'], 'expected_version' => $version],
                $admin,
            ));
            $version = self::coerceInt($published['version'] ?? null);

            $publicState = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                sprintf('/cms-api/v1/lists/%d/state?page_id=%d', $viewId, $byRole['public_list']),
            ));
            self::assertSame([$recordId], $this->recordIds($publicState));
            $live = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            self::assertStringContainsString('Hidden draft', json_encode($live) ?: '');

            $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/lists/%d/records/%d/actions/unpublish', $listId, $recordId),
                ['page_id' => $byRole['cms_list'], 'expected_version' => $version],
                $admin,
            ));
            $publicState = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                sprintf('/cms-api/v1/lists/%d/state?page_id=%d', $viewId, $byRole['public_list']),
            ));
            self::assertSame([], $this->recordIds($publicState));
            $detailGone = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            self::assertStringNotContainsString('Hidden draft', json_encode($detailGone) ?: '');
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    private function recordIds(array $state): array
    {
        $ids = [];
        foreach (self::asList($state['rows'] ?? null) as $row) {
            $ids[] = self::coerceInt(self::asArray($row)['record_id'] ?? null);
        }

        return $ids;
    }

    private function firstSectionIdByStyle(int $pageId, string $styleName, string $token): int
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $found = 0;
        $walk = function ($nodes) use (&$walk, &$found, $styleName): void {
            if (!is_array($nodes) || $found > 0) {
                return;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $style = is_array($node['style'] ?? null) ? ($node['style']['name'] ?? null) : ($node['style_name'] ?? null);
                if ($style === $styleName && is_numeric($node['id'] ?? null)) {
                    $found = (int) $node['id'];

                    return;
                }
                $walk($node['children'] ?? null);
            }
        };
        $walk($data['sections'] ?? null);
        self::assertGreaterThan(0, $found, sprintf('No %s on page %d', $styleName, $pageId));

        return $found;
    }

    /**
     * Validate the most recent client response against a JSON schema.
     */
    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, false);
        self::assertIsObject($decoded, 'Response body must be a JSON object.');

        $errors = $this->service(\App\Service\JSON\JsonSchemaValidationService::class)->validate($decoded, $schemaName);
        self::assertSame([], $errors, sprintf("Response does not match %s:\n%s", $schemaName, implode("\n", $errors)));
    }
}
