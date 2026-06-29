<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Admin;

use App\Entity\Page;
use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Service\CMS\Admin\AdminSectionService;
use App\Tests\Support\QaWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coverage for {@see AdminSectionService::getSection()}.
 *
 * `getSection()` runs an admin ACL check that needs a real authenticated
 * request context, so the success path is exercised read-only through the
 * admin section API against the seeded `home` page (reading system baseline
 * rows is allowed). The not-found path is asserted directly on the service
 * because it short-circuits before the permission check.
 *
 * Mutating section relationships (add child / idempotent re-link / move) is
 * covered against freshly created qa_ pages in
 * {@see \App\Tests\Controller\Api\V1\Admin\SectionWorkflowTest} so no seeded
 * business row is ever modified (QA test-data policy).
 */
class AdminSectionServiceTest extends QaWebTestCase
{
    private function homePageId(): int
    {
        $repository = self::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $repository);
        $page = $repository->findOneBy(['keyword' => 'home']);
        self::assertInstanceOf(Page::class, $page, 'Seeded "home" page must exist.');

        return (int) $page->getId();
    }

    /**
     * The section lookup throws before the ACL check, so this needs no
     * authenticated context. page_id is nullable (auto-resolved from the
     * section) and must be an int, not a keyword string.
     */
    public function testGetSectionNotFoundThrows(): void
    {
        $service = self::getContainer()->get(AdminSectionService::class);
        self::assertInstanceOf(AdminSectionService::class, $service);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Section not found');

        $service->getSection(null, 999999);
    }

    public function testGetSectionSuccessReturnsSectionAndFields(): void
    {
        $token = $this->loginAsQaAdmin();
        $pageId = $this->homePageId();

        $list = $this->jsonRequest('GET', "/cms-api/v1/admin/pages/{$pageId}/sections", null, $token);
        $data = $this->assertEnvelopeSuccess($list);
        self::assertArrayHasKey('sections', $data);

        if (empty($data['sections'])) {
            $this->markTestSkipped('Seeded home page has no sections to read.');
        }

        $sections = $this->asList($data['sections']);
        $firstSection = $this->asArray($sections[0] ?? null);
        $sectionId = $this->asInt($firstSection['id'] ?? null);

        $envelope = $this->jsonRequest('GET', "/cms-api/v1/admin/pages/{$pageId}/sections/{$sectionId}", null, $token);
        $section = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('section', $section);
        self::assertArrayHasKey('fields', $section);
        $sectionData = $this->asArray($section['section']);
        self::assertSame($sectionId, $sectionData['id'] ?? null);
        self::assertIsString($sectionData['name'] ?? null);
        self::assertArrayHasKey('style', $sectionData);
    }

    public function testGetSectionForNonexistentPageReturnsNotFound(): void
    {
        $token = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/pages/999999/sections/999999', null, $token);
        $this->assertEnvelopeError($envelope, Response::HTTP_NOT_FOUND);
    }

    /**
     * The interpolation variable picker is served by its own endpoint (issue
     * #56) so a data column added by a later submission shows up immediately,
     * instead of riding along in the cached section payload. It always includes
     * the hardcoded system variables, so even a section with no data_config
     * returns a non-empty token => label map containing `system.user_name`.
     */
    public function testGetSectionDataVariablesReturnsTokenLabelMap(): void
    {
        $token = $this->loginAsQaAdmin();
        $pageId = $this->homePageId();

        $list = $this->jsonRequest('GET', "/cms-api/v1/admin/pages/{$pageId}/sections", null, $token);
        $data = $this->assertEnvelopeSuccess($list);

        if (empty($data['sections'])) {
            $this->markTestSkipped('Seeded home page has no sections to read.');
        }

        $sections = $this->asList($data['sections']);
        $firstSection = $this->asArray($sections[0] ?? null);
        $sectionId = $this->asInt($firstSection['id'] ?? null);

        $envelope = $this->jsonRequest('GET', "/cms-api/v1/admin/sections/{$sectionId}/data-variables", null, $token);
        $payload = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('data_variables', $payload);
        $variables = $this->asArray($payload['data_variables']);
        self::assertArrayHasKey('system.user_name', $variables, 'System variables must always be offered.');
        self::assertSame('system.user_name', $variables['system.user_name']);
    }
}
