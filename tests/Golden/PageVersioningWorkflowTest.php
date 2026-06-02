<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Golden CMS workflow (Slice 3 / plan §"golden workflow"):
 *
 *   create page -> publish (creates version) -> list versions ->
 *   compare draft against the published version -> unpublish ->
 *   delete page -> verify it is gone.
 *
 * This proves the page versioning/publishing chain end to end through the
 * public admin API (plan §13: assert domain-visible effects, not internals)
 * and doubles as the cleanup proof for the slice (the page it creates is
 * deleted through the API and DAMA rolls back the transaction afterwards).
 *
 * All data is qa_-prefixed and deterministic (anti-flakiness §9): DAMA wraps
 * each test in a transaction and rolls it back, so the deterministic keyword
 * never collides across runs.
 */
#[Group('golden')]
final class PageVersioningWorkflowTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_page_versioning_workflow';
    private const URL = '/qa-page-versioning-workflow';

    public function testCreatePublishCompareUnpublishDeletePageLifecycle(): void
    {
        $admin = $this->loginAsQaAdmin();

        // 1. Create the page (web access type, default "experiment" page type).
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => 'web',
            'url' => self::URL,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, 201);
        self::assertSame(self::KEYWORD, $pageData['keyword'] ?? null, 'Created page keyword mismatch');
        self::assertIsInt($pageData['id'] ?? null, 'Created page must expose an integer id');
        $pageId = (int) $pageData['id'];

        // 2. Publish the current draft -> creates and publishes version 1.
        $published = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/versions/publish', $pageId),
            ['version_name' => 'qa_v1'],
            $admin
        );
        $publishData = $this->assertEnvelopeSuccess($published, 201);
        self::assertIsInt($publishData['version_id'] ?? null, 'Publish must return the new version id');
        $versionId = (int) $publishData['version_id'];
        // Contract: the publish response matches its (now-existing) schema.
        $this->assertLastResponseMatchesSchema('responses/admin/page_version_published');

        // 2b. Fetch the version details (covers the page_version_details schema).
        $versionDetails = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/versions/%d', $pageId, $versionId),
            null,
            $admin
        );
        $detailsData = $this->assertEnvelopeSuccess($versionDetails);
        self::assertSame($versionId, $detailsData['id'] ?? null, 'Version details must return the requested version');
        $this->assertLastResponseMatchesSchema('responses/admin/page_version_details');

        // 3. The published version is now visible through the versions list.
        $versions = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/versions', $pageId),
            null,
            $admin
        );
        $versionsData = $this->assertEnvelopeSuccess($versions);
        self::assertTrue(
            $this->versionListContainsId($versionsData, $versionId),
            sprintf('Published version %d must appear in the versions list', $versionId)
        );

        // 4. Compare the live draft against the published version.
        $comparison = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/versions/compare-draft/%d', $pageId, $versionId),
            null,
            $admin
        );
        $comparisonData = $this->assertEnvelopeSuccess($comparison);
        self::assertSame('side_by_side', $comparisonData['format'] ?? null, 'Default comparison format must be side_by_side');
        self::assertArrayHasKey('draft', $comparisonData);
        self::assertArrayHasKey('published_version', $comparisonData);
        self::assertArrayHasKey('diff', $comparisonData);
        self::assertSame($versionId, $this->asArray($comparisonData['published_version'])['id'] ?? null, 'Comparison must reference the published version');

        // 5. Unpublish -> revert to draft mode.
        $unpublished = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/versions/unpublish', $pageId),
            null,
            $admin
        );
        $this->assertEnvelopeSuccess($unpublished);
        $this->assertLastResponseMatchesSchema('responses/admin/page_unpublished');

        // 5b. Deleting the version exercises the page_version_deleted schema.
        $versionDeleted = $this->jsonRequest(
            'DELETE',
            sprintf('/cms-api/v1/admin/pages/%d/versions/%d', $pageId, $versionId),
            null,
            $admin
        );
        $this->assertEnvelopeSuccess($versionDeleted);
        $this->assertLastResponseMatchesSchema('responses/admin/page_version_deleted');

        // 6. Delete the page (cleanup through the API) and prove it is gone.
        $deleted = $this->jsonRequest(
            'DELETE',
            sprintf('/cms-api/v1/admin/pages/%d', $pageId),
            null,
            $admin
        );
        $this->assertEnvelopeSuccess($deleted);

        $afterDelete = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d', $pageId),
            null,
            $admin
        );
        $this->assertEnvelope404($afterDelete);
    }

    /**
     * Validate the most recent client response against a JSON schema. Decodes
     * the raw response body as objects (the validator expects stdClass for
     * "object" schemas) and asserts zero validation errors — proving the
     * controller's live response matches its declared response schema.
     */
    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, false);
        self::assertIsObject($decoded, 'Response body must be a JSON object.');

        $errors = $this->service(JsonSchemaValidationService::class)->validate($decoded, $schemaName);
        self::assertSame([], $errors, sprintf("Response does not match %s:\n%s", $schemaName, implode("\n", $errors)));
    }

    /**
     * The versions list payload shape varies (list vs {versions:[...]}); scan
     * defensively for the published version id rather than coupling to one shape.
     *
     * @param array<string, mixed> $versionsData
     */
    private function versionListContainsId(array $versionsData, int $versionId): bool
    {
        $candidates = $versionsData['versions'] ?? $versionsData;
        if (!is_array($candidates)) {
            return false;
        }

        foreach ($candidates as $version) {
            if (is_array($version) && $this->coerceInt($version['id'] ?? $version['version_id'] ?? 0) === $versionId) {
                return true;
            }
        }

        return false;
    }
}
