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
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage proving the page-platform model is a SINGLE source of truth:
 * `pages.id_page_access_types` (web | mobile | mobile_and_web) is the only page
 * target, and the removed experimental `pages.id_platform` duplicate is gone
 * from the contract.
 *
 * Proves: a created page round-trips its page-access type through GET, the page
 * response exposes no `platform` field, and the create_page schema rejects a
 * stray `platform` property (additionalProperties:false). Each test runs in a
 * rolled-back transaction (QaWebTestCase), so qa- pages need no manual cleanup.
 */
#[Group('security')]
final class AdminPageAccessTargetTest extends QaWebTestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed> Created page payload (id, keyword, ...).
     */
    private function createPage(string $token, string $keyword, array $extra = []): array
    {
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', array_merge([
            'keyword' => $keyword,
            'pageAccessTypeCode' => 'mobile_and_web',
            'url' => '/' . $keyword,
        ], $extra), $token);

        return $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
    }

    public function testCreatedPageRoundTripsItsAccessType(): void
    {
        $admin = $this->loginAsQaAdmin();
        $page = $this->createPage($admin, 'qa-access-target-page');
        $pageId = self::coerceInt($page['id'] ?? null);

        $payload = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . $pageId, null, $admin)
        );

        self::assertArrayHasKey('page', $payload, 'Page-fields response must expose the page.');
        $accessType = self::asArray(self::jsonGet($payload, 'page', 'pageAccessType'), 'Page must expose a pageAccessType lookup.');
        self::assertSame('pageAccessTypes', $accessType['typeCode'] ?? null);
        self::assertSame('mobile_and_web', $accessType['lookupCode'] ?? null, 'Access type must round-trip as mobile_and_web.');
    }

    public function testPageResponseExposesNoDuplicatePlatformField(): void
    {
        $admin = $this->loginAsQaAdmin();
        $page = $this->createPage($admin, 'qa-access-no-platform-page');
        $pageId = self::coerceInt($page['id'] ?? null);

        $payload = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . $pageId, null, $admin)
        );

        $pageData = self::asArray(self::jsonGet($payload, 'page'), 'Page payload must be an object.');
        self::assertArrayNotHasKey(
            'platform',
            $pageData,
            'The page must not expose a duplicate render-target/platform field; access type is the only page target.'
        );
    }

    /**
     * `platform` was removed from create_page (additionalProperties:false), so a
     * stray `platform` property is now a contract violation. This asserts the
     * schema directly (the controller wraps validation in a broad catch).
     */
    public function testCreateSchemaRejectsAStrayPlatformProperty(): void
    {
        /** @var JsonSchemaValidationService $validator */
        $validator = self::getContainer()->get(JsonSchemaValidationService::class);

        $valid = (object) [
            'keyword' => 'qa-access-valid',
            'pageAccessTypeCode' => 'mobile_and_web',
        ];
        self::assertSame(
            [],
            $validator->validate($valid, 'requests/admin/create_page'),
            'A page with only a pageAccessTypeCode must pass create_page validation.'
        );

        $withPlatform = (object) [
            'keyword' => 'qa-access-stray-platform',
            'pageAccessTypeCode' => 'web',
            'platform' => 'mobile',
        ];
        self::assertNotEmpty(
            $validator->validate($withPlatform, 'requests/admin/create_page'),
            'A stray platform property must be rejected by the create_page schema.'
        );
    }
}
