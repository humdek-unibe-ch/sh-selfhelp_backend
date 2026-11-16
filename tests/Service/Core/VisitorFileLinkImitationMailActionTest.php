<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Action;
use App\Entity\User;
use App\Repository\PageRepository;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConfigRuntimeService;
use App\Service\Action\ActionTemplateContextBuilder;
use App\Service\Auth\MailTemplateDefaults;
use App\Service\Auth\MailTemplateService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\GlobalVariableService;
use App\Service\Core\InterpolationService;
use App\Tests\Support\NarrowsJson;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F177 / fix-7: mail and action interpolation escape a visitor value that
 * imitates a server-built file URL or `__links` HTML. Web sanitises; mail and
 * action texts do not, so the server must escape.
 */
final class VisitorFileLinkImitationMailActionTest extends TestCase
{
    use NarrowsJson;

    private const XSS_HTML = '<a href="/lists/1/files/">x</a><img src=https://evil.example/t.png>'
        . '<a href="https://evil.example/login">Reset password</a>';

    private const XSS_URL = '/lists/1/files/" onerror="alert(1)';

    private function emptyGlobals(): GlobalVariableService
    {
        $globals = $this->createStub(GlobalVariableService::class);
        $globals->method('getGlobalVariableValues')->willReturn([]);

        return $globals;
    }

    private function mailService(): MailTemplateService
    {
        $pageRepository = $this->createStub(PageRepository::class);
        $pageRepository->method('findOneBy')->willReturn(null);

        return new MailTemplateService(
            $this->createStub(Connection::class),
            $pageRepository,
            $this->createStub(CmsPreferenceService::class),
            new InterpolationService(),
            $this->createStub(LoggerInterface::class),
            $this->emptyGlobals(),
        );
    }

    public function testMailTemplateEscapesVisitorImitationOfFileLinks(): void
    {
        $config = $this->mailService()->buildEmailConfig(
            MailTemplateDefaults::TYPE_CONFIRM,
            [
                'user_name' => self::XSS_HTML,
                'validation_url' => 'http://localhost:3000/validate/1/token',
            ],
            ['recipient_emails' => QaBaselineFixture::QA_USER_EMAIL],
            'en-GB',
        );

        $body = $this->coerceString($config['body']);
        $subject = $this->coerceString($config['subject']);

        self::assertStringContainsString('&lt;a href=', $body);
        self::assertStringNotContainsString('<img src=https://evil.example/t.png>', $body);
        self::assertStringNotContainsString('onerror="alert(1)"', $body);
        self::assertStringNotContainsString('<img src=https://evil.example/t.png>', $subject);
    }

    public function testActionTemplateEscapesVisitorImitationOfFileLinks(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn(QaBaselineFixture::QA_USER_EMAIL);
        $user->method('getName')->willReturn('QA User');
        $user->method('getUserName')->willReturn('qa_user');

        $builder = new ActionTemplateContextBuilder(new InterpolationService());
        $context = $builder->buildContext(
            $user,
            'CODE',
            [
                'evil_html' => self::XSS_HTML,
                'evil_url' => self::XSS_URL,
            ],
        );

        $note = $builder->render('Note: {{record.evil_html}}', $context);
        self::assertStringContainsString('&lt;a href=', $note);
        self::assertStringNotContainsString('<img src=https://evil.example/t.png>', $note);

        $img = $builder->render('<img src="{{record.evil_url}}">', $context);
        self::assertStringContainsString('&quot;', $img);
        self::assertStringNotContainsString('onerror="alert(1)"', $img);
    }

    public function testActionRuntimeConfigEscapesVisitorImitationInJobText(): void
    {
        $service = new ActionConfigRuntimeService(
            new InterpolationService(),
            $this->createStub(EntityManagerInterface::class),
        );

        $action = (new Action())->setConfig((string) json_encode([
            ActionConfig::OVERWRITE_VARIABLES => false,
            ActionConfig::BLOCKS => [[
                ActionConfig::JOBS => [[
                    'subject' => 'About {{title}}',
                    'body' => '<p>{{title}}</p><img src="{{payload}}">',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        $runtime = $service->buildRuntimeConfig($action, [
            'title' => self::XSS_HTML,
            'payload' => self::XSS_URL,
        ]);

        $subject = $this->coerceString($this->jsonGet(
            $runtime,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            'subject',
        ));
        $body = $this->coerceString($this->jsonGet(
            $runtime,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            'body',
        ));

        self::assertStringContainsString('&lt;a href=', $subject);
        self::assertStringNotContainsString('<img src=https://evil.example/t.png>', $subject);
        self::assertStringContainsString('&lt;a href=', $body);
        self::assertStringNotContainsString('onerror="alert(1)"', $body);
    }
}
