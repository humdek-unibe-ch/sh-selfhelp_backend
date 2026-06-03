<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Entity\Page;
use App\Entity\Section;
use App\Exception\ServiceException;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\FormValidationService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration coverage for {@see FormValidationService::validatePublicPageAccess()}
 * — the open-access (guest) validation path that needs no security context, so it
 * can be exercised directly at the service layer. It drives the real
 * `fetchSectionsHierarchicalByPageId` stored procedure (section-belongs check)
 * and the form-style guard against QA pages/sections built by
 * {@see PageSectionFactory}.
 *
 * The authenticated submit/delete ACL paths are covered through the HTTP layer in
 * {@see \App\Tests\Controller\Api\V1\Frontend\FormControllerTest}; here we only add
 * the page-not-found branch of those methods (it runs before any ACL check).
 */
final class FormValidationServiceTest extends QaKernelTestCase
{
    private FormValidationService $service;
    private PageSectionFactory $pages;

    private const UNKNOWN_ID = 99_000_111;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->service(FormValidationService::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testPublicAccessAcceptsOpenPageWithLinkedFormSection(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_fvs_open', openAccess: true);

        $result = $this->service->validatePublicPageAccess((int) $page->getId(), (int) $section->getId());

        self::assertTrue($result['validated']);
        self::assertInstanceOf(Page::class, $result['page']);
        self::assertInstanceOf(Section::class, $result['section']);
        self::assertSame($page->getId(), $result['page']->getId());
        self::assertSame($section->getId(), $result['section']->getId());
    }

    public function testPublicAccessRejectsNonOpenPage(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_fvs_closed', openAccess: false);

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);
        $this->service->validatePublicPageAccess((int) $page->getId(), (int) $section->getId());
    }

    public function testPublicAccessRejectsUnknownPage(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);
        $this->service->validatePublicPageAccess(self::UNKNOWN_ID, self::UNKNOWN_ID);
    }

    public function testPublicAccessRejectsSectionNotBelongingToPage(): void
    {
        $page = $this->pages->createPage('qa_fvs_orphan_page', openAccess: true);
        $orphan = $this->pages->createSection('qa_fvs_orphan_section', 'form-record');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);
        $this->service->validatePublicPageAccess((int) $page->getId(), (int) $orphan->getId());
    }

    public function testPublicAccessRejectsNonFormSection(): void
    {
        $page = $this->pages->createPage('qa_fvs_nonform_page', openAccess: true);
        $section = $this->pages->createSection('qa_fvs_nonform_section', 'container');
        $this->pages->linkSectionToPage($page, $section, 10);

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);
        $this->service->validatePublicPageAccess((int) $page->getId(), (int) $section->getId());
    }

    public function testSubmissionValidationRejectsUnknownPageBeforeAcl(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);
        $this->service->validateFormSubmission(self::UNKNOWN_ID, self::UNKNOWN_ID, ['qa_field' => 'x']);
    }

    public function testDeletionValidationRejectsUnknownPageBeforeAcl(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);
        $this->service->validateFormDeletion(self::UNKNOWN_ID, self::UNKNOWN_ID);
    }
}
