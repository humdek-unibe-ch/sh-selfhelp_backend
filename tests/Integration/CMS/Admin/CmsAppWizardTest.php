<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end coverage for first-class CMS app scaffold
 * (`POST /admin/cms-apps` + `POST /admin/cms-apps/{id}/scaffold`):
 *
 * - empty app shell, then scaffold with `create_form`
 * - strict roles: form / cms_list / cms_detail / public_list / public_detail
 * - shared form section on cms_detail, entry-table cms list, public cards
 */
#[Group('golden')]
final class CmsAppWizardTest extends QaWebTestCase
{
    private const BASE = 'qa-team-wizard';
    private const MULTI_BASE = 'qa-team-wizard-multi';

    public function testWizardScaffoldsModalFormAndAdminDataTableAndPublicCards(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, $created, $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => self::BASE,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'form_field_name' => 'title',
        ], 'qa-team-wizard-app');

        self::assertCount(5, $created, 'create_form adds a form page on top of the two list/detail pairs.');
        self::assertArrayHasKey('form', $byRole);
        self::assertArrayHasKey('cms_list', $byRole);
        self::assertArrayHasKey('cms_detail', $byRole);
        self::assertArrayHasKey('public_list', $byRole);
        self::assertArrayHasKey('public_detail', $byRole);

        try {
            $formBody = $this->sectionsBody($byRole['form'], $admin);
            self::assertStringContainsString('form-record', $formBody);
            self::assertStringContainsString('text-input', $formBody);
            // Field contents live on the section-detail endpoint (list is structural).
            self::assertSame('1', $this->sectionFieldValue($byRole['form'], 'form-record', 'close_modal_on_save', $admin));
            self::assertSame('record_id', $this->sectionFieldValue($byRole['form'], 'form-record', 'load_record_from', $admin));

            $adminDetailBody = $this->sectionsBody($byRole['cms_detail'], $admin);
            self::assertStringContainsString('form-record', $adminDetailBody);
            self::assertStringNotContainsString('entry-record', str_replace('form-record', '', $adminDetailBody));

            self::assertSame('1', $this->pageProperty($byRole['form'], 'open_in_modal', $admin));
            self::assertSame('1', $this->pageProperty($byRole['cms_detail'], 'open_in_modal', $admin));

            $adminBody = $this->sectionsBody($byRole['cms_list'], $admin);
            self::assertStringContainsString('entry-table', $adminBody);
            self::assertStringNotContainsString('entry-record-delete', $adminBody);
            self::assertSame('1', $this->sectionFieldValue($byRole['cms_list'], 'entry-table', 'delete_entry', $admin));
            self::assertSame(
                '/cms/' . self::BASE . '/form',
                $this->sectionFieldValue($byRole['cms_list'], 'entry-table', 'add_url', $admin)
            );
            self::assertSame(
                '/cms/' . self::BASE . '/{record_id}',
                $this->sectionFieldValue($byRole['cms_list'], 'entry-table', 'edit_url', $admin)
            );

            $publicBody = $this->sectionsBody($byRole['public_list'], $admin);
            self::assertStringContainsString('entry-list', $publicBody);
            self::assertNull($this->pageProperty($byRole['public_detail'], 'open_in_modal', $admin));
            self::assertSame(
                'record_id',
                $this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'load_record_from', $admin)
            );
            self::assertNull($this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'filter', $admin));
            self::assertNull($this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'url_param', $admin));
            self::assertNotNull(
                $this->sectionFieldValue($byRole['public_list'], 'link', 'url', $admin),
                'Public list cards must carry a detail link template.'
            );
            self::assertStringContainsString(
                '{{record_id}}',
                (string) $this->sectionFieldValue($byRole['public_list'], 'link', 'url', $admin)
            );

            // Hub sync: cms list is resolvable from the app detail.
            $appDetail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertSame($byRole['cms_list'], $appDetail['id_cms_list_page'] ?? null);
            $assignedIds = array_map(
                static fn(array $page): int => (int) ($page['page_id'] ?? 0),
                is_array($appDetail['pages'] ?? null) ? $appDetail['pages'] : []
            );
            self::assertContains($byRole['form'], $assignedIds);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testWizardMultiFieldBuilderScaffoldsEachInputAndDetailInterpolation(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, $created, $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => self::MULTI_BASE,
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'first_name', 'style' => 'text-input', 'label' => 'First name'],
                ['name' => 'bio', 'style' => 'textarea'],
                ['name' => 'age', 'style' => 'number-input', 'label' => 'Age'],
            ],
        ], 'qa-team-wizard-multi-app');

        self::assertIsArray($created);
        self::assertArrayHasKey('form', $byRole);
        self::assertArrayHasKey('cms_list', $byRole);
        self::assertArrayHasKey('cms_detail', $byRole);

        try {
            $formBody = $this->sectionsBody($byRole['form'], $admin);
            self::assertStringContainsString('textarea', $formBody);
            self::assertStringContainsString('number-input', $formBody);
            self::assertStringContainsString('first_name', $formBody);
            self::assertStringContainsString('bio', $formBody);
            self::assertStringContainsString('age', $formBody);

            $adminBody = $this->sectionsBody($byRole['cms_list'], $admin);
            self::assertStringContainsString('entry-table', $adminBody);
            self::assertNotNull($this->sectionFieldValue($byRole['cms_list'], 'entry-table', 'add_url', $admin));
            self::assertNotNull($this->sectionFieldValue($byRole['cms_list'], 'entry-table', 'edit_url', $admin));

            $detailBody = $this->sectionsBody($byRole['cms_detail'], $admin);
            self::assertStringContainsString('form-record', $detailBody);
            self::assertSame(
                'record_id',
                $this->sectionFieldValue($byRole['cms_detail'], 'form-record', 'load_record_from', $admin)
            );
            // Same shared form section is attached; inputs live under the form page tree.
            self::assertStringContainsString('first_name', $this->sectionsBody($byRole['form'], $admin));
            self::assertStringContainsString('age', $this->sectionsBody($byRole['form'], $admin));
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testScaffoldedAppCreateThenEditPrefillsAddressedRecord(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-editmode-app';

        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
            ],
        ], 'qa-editmode-shell');

        try {
            $formSectionId = $this->firstSectionIdByStyle($byRole['form'], 'form-record', $admin);
            self::assertGreaterThan(0, $formSectionId);

            $detailSectionId = $this->firstSectionIdByStyle($byRole['cms_detail'], 'form-record', $admin);
            self::assertSame($formSectionId, $detailSectionId);

            $submit = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $byRole['form'],
                'section_id' => $formSectionId,
                'form_data' => ['title' => 'qa Alice'],
            ], $admin);
            $submitData = $this->assertEnvelopeSuccess($submit);
            self::assertIsInt($submitData['record_id'] ?? null);
            $recordId = $submitData['record_id'];

            $createRender = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/by-keyword/cms-' . $base . '-form?preview=true',
                null,
                $admin
            );
            $createData = $this->assertEnvelopeSuccess($createRender);
            $createForm = $this->findSectionByStyleInPage($createData, 'form-record');
            self::assertNotNull($createForm);
            self::assertSame([], $createForm['section_data'] ?? null);

            $editRender = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/cms/' . $base . '/' . $recordId) . '&preview=true',
                null,
                $admin
            );
            $editData = $this->assertEnvelopeSuccess($editRender);
            $editForm = $this->findSectionByStyleInPage($editData, 'form-record');
            self::assertNotNull($editForm);
            $sectionData = $editForm['section_data'] ?? null;
            self::assertIsArray($sectionData);
            self::assertNotSame([], $sectionData);
            self::assertStringContainsString('qa Alice', (string) json_encode($sectionData));
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testPrimaryRoleUniquenessIsEnforced(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-uniq-app',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
        ], 'qa-uniq-shell');

        $extraPageId = null;
        try {
            self::assertArrayHasKey('cms_list', $byRole);

            $extra = $this->assertEnvelopeSuccess(
                $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                    'keyword' => 'qa-uniq-extra',
                    'url' => '/cms/qa-uniq-extra',
                    'pageAccessTypeCode' => 'mobile_and_web',
                    'headless' => false,
                    'openAccess' => false,
                    'surface' => 'cms',
                ], $admin),
                201
            );
            $extraPageId = (int) ($extra['id'] ?? 0);
            self::assertGreaterThan(0, $extraPageId);

            $conflict = $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/cms-apps/%d/pages', $appId),
                ['page_id' => $extraPageId, 'role' => 'cms_list'],
                $admin
            );
            self::assertSame(409, $conflict['status'] ?? 0);
        } finally {
            if ($extraPageId !== null && $extraPageId > 0) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $extraPageId), null, $admin);
            }
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testDeleteAppShellKeepsPages(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-keep-pages',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
        ], 'qa-keep-shell');

        try {
            self::assertArrayHasKey('cms_list', $byRole);
            $listId = $byRole['cms_list'];

            $delete = $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
            $this->assertEnvelopeSuccess($delete, 200);

            $gone = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
            self::assertSame(404, $gone['status'] ?? 0);

            $page = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $listId), null, $admin)
            );
            self::assertIsArray($page);
            self::assertArrayHasKey('fields', $page);

            $list = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $admin)
            );
            $pages = is_array($list['pages'] ?? null) ? $list['pages'] : (is_array($list) ? $list : []);
            $row = null;
            foreach ($pages as $candidate) {
                if (is_array($candidate) && (int) ($candidate['id_pages'] ?? 0) === $listId) {
                    $row = $candidate;
                    break;
                }
            }
            self::assertNotNull($row, 'CMS list page must still appear in the admin pages list after app delete.');
            self::assertNull($row['cms_app_id'] ?? null);
            self::assertNull($row['cms_app_role'] ?? null);
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    public function testHubSyncOnScaffoldAssignChangeRoleUnassignAndPageDelete(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-hub-sync',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
        ], 'qa-hub-sync-shell');

        try {
            $detail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertSame($byRole['cms_list'], (int) ($detail['id_cms_list_page'] ?? 0));
            self::assertNotNull($detail['id_form_section'] ?? null);

            $listPageId = $byRole['cms_list'];
            $demoted = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'PATCH',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $listPageId),
                    ['role' => 'other'],
                    $admin
                )
            );
            self::assertArrayHasKey('id_cms_list_page', $demoted);
            self::assertNull($demoted['id_cms_list_page']);

            $restored = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'PATCH',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $listPageId),
                    ['role' => 'cms_list'],
                    $admin
                )
            );
            self::assertSame($listPageId, (int) ($restored['id_cms_list_page'] ?? 0));

            $formPageId = $byRole['form'];
            $unassigned = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'DELETE',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $formPageId),
                    null,
                    $admin
                )
            );
            self::assertArrayHasKey('id_form_section', $unassigned);
            self::assertNull($unassigned['id_form_section']);
            self::assertSame($listPageId, (int) ($unassigned['id_cms_list_page'] ?? 0));

            $this->assertEnvelopeSuccess(
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $listPageId), null, $admin)
            );
            $pageIds = array_values(array_filter($pageIds, static fn(int $id): bool => $id !== $listPageId));

            $afterDelete = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertArrayHasKey('id_cms_list_page', $afterDelete);
            self::assertNull($afterDelete['id_cms_list_page']);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testLegacyCmsInCmsBundleWithoutCmsAppMetadataIsRejected(): void
    {
        $admin = $this->loginAsQaAdmin();
        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => [
                'format' => 'selfhelp/page-bundle',
                'version' => '1.0',
                'title' => 'Legacy CMS app',
                'tags' => ['cms-in-cms'],
                'pages' => [[
                    'keyword' => 'qa-legacy-cms-list',
                    'url' => '/cms/qa-legacy-cms-list',
                    'page_access_type' => 'mobile_and_web',
                    'surface' => 'cms',
                    'headless' => false,
                    'open_access' => false,
                    'sections' => [],
                ]],
            ],
        ], $admin);

        self::assertSame(400, $response['status'] ?? 0, json_encode($response));
        $haystack = strtolower(
            (string) ($response['message'] ?? '') . ' ' . (string) ($response['error'] ?? '') . ' ' . json_encode($response)
        );
        self::assertStringContainsString('cms_app', $haystack);
    }

    public function testCmsInCmsBundleImportSetsHubsAndRejectsInvalidRole(): void
    {
        $admin = $this->loginAsQaAdmin();
        $slug = 'qa-import-hubs';

        $ok = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => [
                'format' => 'selfhelp/page-bundle',
                'version' => '1.0',
                'title' => 'Import hubs',
                'tags' => ['cms-in-cms'],
                'cms_app' => [
                    'name' => 'Import hubs',
                    'slug' => $slug,
                    'description' => null,
                ],
                'pages' => [[
                    'keyword' => 'qa-import-hubs-list',
                    'url' => '/cms/qa-import-hubs-list',
                    'page_access_type' => 'mobile_and_web',
                    'surface' => 'cms',
                    'headless' => false,
                    'open_access' => false,
                    'cms_app_role' => 'cms_list',
                    'sections' => [],
                ]],
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($ok, 201);
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];
        $pageId = (int) ($created[0]['page_id'] ?? 0);
        self::assertGreaterThan(0, $pageId);

        try {
            $bySlug = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/by-slug/%s', $slug), null, $admin)
            );
            self::assertSame($pageId, (int) ($bySlug['id_cms_list_page'] ?? 0));
            $appId = (int) ($bySlug['id'] ?? 0);

            $bad = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
                'bundle' => [
                    'format' => 'selfhelp/page-bundle',
                    'version' => '1.0',
                    'title' => 'Bad role',
                    'tags' => ['cms-in-cms'],
                    'cms_app' => [
                        'name' => 'Bad role',
                        'slug' => 'qa-bad-role-app',
                        'description' => null,
                    ],
                    'pages' => [[
                        'keyword' => 'qa-bad-role-page',
                        'url' => '/cms/qa-bad-role-page',
                        'page_access_type' => 'mobile_and_web',
                        'surface' => 'cms',
                        'headless' => false,
                        'open_access' => false,
                        'cms_app_role' => 'not-a-role',
                        'sections' => [],
                    ]],
                ],
            ], $admin);
            self::assertSame(400, $bad['status'] ?? 0, json_encode($bad));
            $haystack = strtolower(
                (string) ($bad['message'] ?? '') . ' ' . (string) ($bad['error'] ?? '') . ' ' . json_encode($bad)
            );
            self::assertStringContainsString('cms_app_role', $haystack);

            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        } catch (\Throwable $e) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            throw $e;
        }
    }

    public function testCmsAppImportSanitisesKeywordPrefixUnderscoresInSlug(): void
    {
        $admin = $this->loginAsQaAdmin();
        $keywordPrefix = 'demo_team_members_';
        $expectedSlug = 'demo-team-members-team-members';

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => [
                'format' => 'selfhelp/page-bundle',
                'version' => '1.0',
                'title' => 'Prefixed team',
                'tags' => ['cms-in-cms'],
                'cms_app' => [
                    'name' => 'Team members',
                    'slug' => 'team-members',
                    'description' => null,
                ],
                'pages' => [[
                    'keyword' => 'cms-team-members',
                    'url' => '/cms/team-members',
                    'page_access_type' => 'mobile_and_web',
                    'surface' => 'cms',
                    'headless' => false,
                    'open_access' => false,
                    'cms_app_role' => 'cms_list',
                    'sections' => [],
                ]],
            ],
            'options' => [
                'keywordPrefix' => $keywordPrefix,
                'routePrefix' => '/demo-team-members',
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($response, 201);
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];
        $pageId = (int) ($created[0]['page_id'] ?? 0);
        self::assertGreaterThan(0, $pageId);

        try {
            $bySlug = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/by-slug/%s', $expectedSlug), null, $admin)
            );
            self::assertSame('Team members', $bySlug['name'] ?? null);
            self::assertSame($expectedSlug, $bySlug['slug'] ?? null);
            $appId = (int) ($bySlug['id'] ?? 0);
            self::assertGreaterThan(0, $appId);

            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        } catch (\Throwable $e) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $scaffold
     * @return array{0: int, 1: list<array<string, mixed>>, 2: array<string, int>, 3: list<int>}
     */
    private function createAppAndScaffold(string $token, array $scaffold, string $slug): array
    {
        $createdApp = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => $slug,
                'slug' => $slug,
            ], $token),
            201
        );
        $appId = (int) ($createdApp['id'] ?? 0);
        self::assertGreaterThan(0, $appId);

        $response = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId),
            $scaffold,
            $token
        );
        $data = $this->assertEnvelopeSuccess($response, 201);
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];

        $byRole = [];
        $pageIds = [];
        foreach ($created as $entry) {
            if (!is_array($entry) || !is_int($entry['page_id'] ?? null)) {
                continue;
            }
            $pageIds[] = $entry['page_id'];
            $role = is_string($entry['role'] ?? null) ? $entry['role'] : '';
            $byRole[$role] = $entry['page_id'];
        }

        return [$appId, $created, $byRole, $pageIds];
    }

    /** @param list<int> $pageIds */
    private function cleanupApp(string $token, int $appId, array $pageIds): void
    {
        foreach ($pageIds as $pageId) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $token);
        }
        $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $token);
    }

    private function firstSectionIdByStyle(int $pageId, string $styleName, string $token): int
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        $found = 0;
        $walk = function ($nodes) use (&$walk, &$found, $styleName): void {
            if (!is_array($nodes)) {
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
                if (isset($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk($data['sections'] ?? $data);

        return $found;
    }

    private function sectionsBody(int $pageId, string $token): string
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        return (string) json_encode($data);
    }

    private function sectionFieldValue(int $pageId, string $styleName, string $fieldName, string $token): ?string
    {
        $sectionId = $this->firstSectionIdByStyle($pageId, $styleName, $token);
        self::assertGreaterThan(0, $sectionId);

        $detail = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
            null,
            $token
        );
        $data = $this->assertEnvelopeSuccess($detail);

        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            if (!is_array($field) || ($field['name'] ?? null) !== $fieldName) {
                continue;
            }
            foreach (is_array($field['translations'] ?? null) ? $field['translations'] : [] as $translation) {
                if (is_array($translation) && is_string($translation['content'] ?? null) && $translation['content'] !== '') {
                    return $translation['content'];
                }
            }
        }

        return null;
    }

    private function pageProperty(int $pageId, string $fieldName, string $token): ?string
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['name'] ?? null) !== $fieldName) {
                continue;
            }
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            foreach ($translations as $translation) {
                if (is_array($translation) && array_key_exists('content', $translation)) {
                    return is_string($translation['content']) ? $translation['content'] : null;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $pagePayload
     * @return array<string, mixed>|null
     */
    private function findSectionByStyleInPage(array $pagePayload, string $styleName): ?array
    {
        $found = null;
        $walk = function ($nodes) use (&$walk, &$found, $styleName): void {
            if (!is_array($nodes) || $found !== null) {
                return;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $style = $node['style_name'] ?? null;
                if ($style === null && is_array($node['style'] ?? null)) {
                    $style = $node['style']['name'] ?? null;
                }
                if ($style === $styleName) {
                    $found = $node;

                    return;
                }
                if (isset($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $sections = $pagePayload['sections']
            ?? (is_array($pagePayload['page'] ?? null) ? ($pagePayload['page']['sections'] ?? []) : []);
        $walk($sections);

        return $found;
    }
}
