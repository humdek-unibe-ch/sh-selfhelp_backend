<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Exception\ServiceException;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\FormList\FormListGuard;
use App\Service\CMS\FormValidationService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-layer coverage for {@see FormValidationService::authorize()} on the
 * open-access (guest) path. Guest vs signed-in validation parity is proven at
 * the HTTP layer in FormControllerTest (F02).
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

    public function testAuthorizeAcceptsOpenPageWithLinkedFormSection(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_fvs_open', openAccess: true);

        $scope = $this->service->authorize(FormListGuard::OP_CREATE, (int) $page->getId(), (int) $section->getId());

        self::assertSame($page->getId(), $scope->page->getId());
        self::assertSame($section->getId(), $scope->section->getId());
    }

    public function testAuthorizeRejectsNonOpenPageForGuest(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_fvs_closed', openAccess: false);

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);
        $this->service->authorize(FormListGuard::OP_CREATE, (int) $page->getId(), (int) $section->getId());
    }

    public function testAuthorizeRejectsUnknownPage(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);
        $this->service->authorize(FormListGuard::OP_CREATE, self::UNKNOWN_ID, self::UNKNOWN_ID);
    }

    public function testAuthorizeRejectsSectionNotBelongingToPage(): void
    {
        $page = $this->pages->createPage('qa_fvs_orphan_page', openAccess: true);
        $orphan = $this->pages->createSection('qa_fvs_orphan_section', 'form-record');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);
        $this->service->authorize(FormListGuard::OP_CREATE, (int) $page->getId(), (int) $orphan->getId());
    }

    public function testAuthorizeRejectsNonFormSection(): void
    {
        $page = $this->pages->createPage('qa_fvs_nonform_page', openAccess: true);
        $section = $this->pages->createSection('qa_fvs_nonform_section', 'container');
        $this->pages->linkSectionToPage($page, $section, 10);

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);
        $this->service->authorize(FormListGuard::OP_CREATE, (int) $page->getId(), (int) $section->getId());
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
