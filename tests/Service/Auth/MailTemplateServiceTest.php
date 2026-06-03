<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Repository\PageRepository;
use App\Service\Auth\MailTemplateDefaults;
use App\Service\Auth\MailTemplateService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\InterpolationService;
use App\Tests\Support\NarrowsJson;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailTemplateServiceTest extends TestCase
{
    use NarrowsJson;

    public function testBuildEmailConfigPrefersSupportedRecipientLocale(): void
    {
        $connection = $this->createStub(Connection::class);
        $pageRepository = $this->createMock(PageRepository::class);
        $cmsPreferenceService = $this->createMock(CmsPreferenceService::class);
        $logger = $this->createStub(LoggerInterface::class);

        $pageRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['keyword' => MailTemplateDefaults::PAGE_KEYWORD])
            ->willReturn(null);

        $cmsPreferenceService->expects($this->never())
            ->method('getDefaultLanguageId');

        $service = new MailTemplateService(
            $connection,
            $pageRepository,
            $cmsPreferenceService,
            new InterpolationService(),
            $logger
        );

        $config = $service->buildEmailConfig(
            MailTemplateDefaults::TYPE_CONFIRM,
            [
                'user_name' => 'Stefan',
                'validation_url' => 'http://localhost:3000/validate/1/token',
            ],
            ['recipient_emails' => QaBaselineFixture::QA_USER_EMAIL],
            'de-CH'
        );

        $this->assertSame(
            MailTemplateDefaults::getSubject(MailTemplateDefaults::TYPE_CONFIRM, 'de-CH'),
            $config['subject']
        );
        $this->assertStringContainsString('http://localhost:3000/validate/1/token', $this->coerceString($config['body']));
        $this->assertSame(QaBaselineFixture::QA_USER_EMAIL, $config['recipient_emails']);
    }

    public function testBuildEmailConfigFallsBackToCmsDefaultWhenRecipientLocaleIsUnsupported(): void
    {
        $connection = $this->createMock(Connection::class);
        $pageRepository = $this->createMock(PageRepository::class);
        $cmsPreferenceService = $this->createMock(CmsPreferenceService::class);
        $logger = $this->createStub(LoggerInterface::class);

        $pageRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['keyword' => MailTemplateDefaults::PAGE_KEYWORD])
            ->willReturn(null);

        $cmsPreferenceService->expects($this->once())
            ->method('getDefaultLanguageId')
            ->willReturn(2);

        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT locale FROM languages WHERE id = :id LIMIT 1',
                ['id' => 2]
            )
            ->willReturn('de-CH');

        $service = new MailTemplateService(
            $connection,
            $pageRepository,
            $cmsPreferenceService,
            new InterpolationService(),
            $logger
        );

        $config = $service->buildEmailConfig(
            MailTemplateDefaults::TYPE_WELCOME,
            [
                'user_name' => 'Stefan',
                'platform_url' => 'http://localhost:3000/',
            ],
            ['recipient_emails' => QaBaselineFixture::QA_USER_EMAIL],
            'fr-FR'
        );

        $this->assertSame(
            MailTemplateDefaults::getSubject(MailTemplateDefaults::TYPE_WELCOME, 'de-CH'),
            $config['subject']
        );
        $this->assertStringContainsString('http://localhost:3000/', $this->coerceString($config['body']));
    }
}
