<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Golden CMS Apps lifecycle (first-class product unit):
 *
 *   create empty shell -> scaffold pages -> delete shell (pages retained) ->
 *   verify app is gone and pages are unassigned.
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
