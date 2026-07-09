<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * JSON-schema contract coverage for every `/admin/cms-apps*` response shape
 * (canonical Testing Rule 25).
 */
#[Group('contract')]
final class CmsAppEndpointsSchemaContractTest extends QaWebTestCase
{
    private const SLUG = 'qa-cms-app-schema-contract';
    private const BASE = 'qa-cms-app-schema-base';

    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testCmsAppAdminResponsesMatchPublishedSchemas(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageIds = [];
        $appId = null;
        $assignPageId = null;

        try {
            $list = $this->jsonRequest('GET', '/cms-api/v1/admin/cms-apps', null, $admin);
            $this->assertEnvelopeSuccess($list);
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/list');

            $created = $this->assertEnvelopeSuccess(
                $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                    'name' => 'QA CMS App Schema Contract',
                    'slug' => self::SLUG,
                ], $admin),
                201
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/create');
            self::assertIsInt($created['id'] ?? null);
            $appId = $created['id'];

            $detail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/detail');

            $bySlug = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/by-slug/%s', self::SLUG), null, $admin)
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/detail');
            self::assertSame($appId, $bySlug['id'] ?? null);

            $updated = $this->assertEnvelopeSuccess(
                $this->jsonRequest('PATCH', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), [
                    'description' => 'Schema contract probe',
                ], $admin)
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/update');

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

            foreach (is_array($scaffoldData['created'] ?? null) ? $scaffoldData['created'] : [] as $entry) {
                if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                    $pageIds[] = $entry['page_id'];
                }
            }

            $pagesList = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $admin)
            );
            $rows = is_array($pagesList['pages'] ?? null) ? $pagesList['pages'] : $pagesList;
            self::assertIsArray($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['cms_app_id'] ?? null) === null && is_int($row['id_pages'] ?? null)) {
                    $assignPageId = $row['id_pages'];
                    break;
                }
            }
            self::assertNotNull($assignPageId, 'Need an unassigned page row for assign/unassign schema coverage.');

            $assigned = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'POST',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages', $appId),
                    ['page_id' => $assignPageId, 'role' => 'other'],
                    $admin
                )
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/assign_page');
            self::assertContains(
                $assignPageId,
                array_map(
                    static fn(array $page): int => (int) ($page['page_id'] ?? 0),
                    is_array($assigned['pages'] ?? null) ? $assigned['pages'] : []
                ),
                'Assigned page must appear on the app detail payload.'
            );

            $roleChanged = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'PATCH',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $assignPageId),
                    ['role' => 'other'],
                    $admin
                )
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/change_page_role');

            $unassigned = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'DELETE',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $assignPageId),
                    null,
                    $admin
                )
            );
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/unassign_page');
            $remainingIds = array_map(
                static fn(array $page): int => (int) ($page['page_id'] ?? 0),
                is_array($unassigned['pages'] ?? null) ? $unassigned['pages'] : []
            );
            self::assertNotContains($assignPageId, $remainingIds, 'Unassigned page must drop off the app detail payload.');

            $deleted = $this->jsonRequest(
                'DELETE',
                sprintf('/cms-api/v1/admin/cms-apps/%d', $appId),
                null,
                $admin
            );
            $this->assertEnvelopeSuccess($deleted);
            $this->assertLastResponseMatchesSchema('responses/admin/cms_apps/delete');
            $appId = null;
        } finally {
            if (is_int($appId)) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
            }
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, sprintf("Response failed schema %s:\n%s", $schemaName, implode("\n", $errors)));
    }
}
