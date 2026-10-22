<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;

/**
 * Regression: deleting a page that is still published used to delete it but
 * answer 500 ("Entity of type 'App\Entity\PageVersion' ... was not found"),
 * because the response serialized the deleted page's cascade-deleted version.
 */
final class AdminPageDeletePublishedTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_delete_published_page';
    private const URL = '/qa-delete-published-page';

    public function testDeletingAPublishedPageSucceedsAndRemovesIt(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => 'web',
            'url' => self::URL,
        ], $admin);
        $pageId = self::coerceInt($this->assertEnvelopeSuccess($created, 201)['id'] ?? null);

        $published = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/versions/publish', $pageId),
            ['version_name' => 'qa_v1'],
            $admin
        );
        $this->assertEnvelopeSuccess($published, 201);

        $deleted = $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        $deletedData = $this->assertEnvelopeSuccess($deleted);
        self::assertSame(self::KEYWORD, $deletedData['keyword'] ?? null);

        $afterDelete = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        $this->assertEnvelope404($afterDelete);
    }
}
