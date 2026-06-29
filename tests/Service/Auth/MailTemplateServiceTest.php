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
use App\Service\CMS\GlobalVariableService;
use App\Service\Core\InterpolationService;
use App\Tests\Support\NarrowsJson;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MailTemplateServiceTest extends TestCase
{
    use NarrowsJson;

    /**
     * A GlobalVariableService stub that exposes no globals, so the mail context
     * is driven purely by the caller-provided vars (issue #56 v2).
     */
    private function emptyGlobals(): GlobalVariableService
    {
        $globals = $this->createStub(GlobalVariableService::class);
        $globals->method('getGlobalVariableValues')->willReturn([]);

        return $globals;
    }

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
            $logger,
            $this->emptyGlobals()
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
        // The confirm template now renders the one-time link via the namespaced
        // `{{system.special.activation_link}}` token; the caller still passes the
        // flat `validation_url`, which the service maps into that scope.
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

        // Two distinct lookups now happen: the unsupported-locale fallback
        // (locale by id) and the globals language resolution (id by locale).
        $connection->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params): string|int|null {
                if (str_contains($sql, 'SELECT locale FROM languages')) {
                    return 'de-CH';
                }
                if (str_contains($sql, 'SELECT id FROM languages')) {
                    return 2;
                }

                return null;
            }
        );

        $service = new MailTemplateService(
            $connection,
            $pageRepository,
            $cmsPreferenceService,
            new InterpolationService(),
            $logger,
            $this->emptyGlobals()
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
        // Welcome template renders the home link via `{{system.special.platform_link}}`.
        $this->assertStringContainsString('http://localhost:3000/', $this->coerceString($config['body']));
    }

    /**
     * Issue #56 v2 golden test: callers still pass flat auth vars
     * (`user_name`, `code`), and the service maps them onto the unified
     * `{{system.*}}` namespace the mail-config picker offers, so the seeded
     * templates (which now use `{{system.user_name}}` / `{{system.user_code}}`)
     * render correctly. This pins the flat→namespaced bridge that keeps the auth
     * mail flow working after the token rewrite.
     */
    public function testBuildEmailConfigMapsFlatAuthVarsToSystemNamespace(): void
    {
        $connection = $this->createStub(Connection::class);
        $pageRepository = $this->createMock(PageRepository::class);
        $cmsPreferenceService = $this->createMock(CmsPreferenceService::class);
        $logger = $this->createStub(LoggerInterface::class);

        $pageRepository->method('findOneBy')->willReturn(null);

        $service = new MailTemplateService(
            $connection,
            $pageRepository,
            $cmsPreferenceService,
            new InterpolationService(),
            $logger,
            $this->emptyGlobals()
        );

        $config = $service->buildEmailConfig(
            MailTemplateDefaults::TYPE_2FA,
            [
                'user_name' => 'Alice Example',
                'code' => '482913',
            ],
            [],
            'en-GB'
        );

        $body = $this->coerceString($config['body']);
        $this->assertStringContainsString('Alice Example', $body, '{{system.user_name}} must resolve from the flat user_name var.');
        $this->assertStringContainsString('482913', $body, '{{system.user_code}} must resolve from the flat code var.');
    }
}
