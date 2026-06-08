<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\CMS\Admin;

use App\Entity\Language;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\AdminActionTranslationService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #35: admin action-translation validation rejects deprecated `@user`-style
 * placeholders so action template content can only use the canonical
 * `{{recipient.*}}` / `{{record.*}}` / `{{system.*}}` scopes that
 * {@see \App\Service\Action\ActionTemplateContextBuilder} renders.
 */
final class AdminActionTranslationServiceTest extends QaKernelTestCase
{
    private AdminActionTranslationService $translations;
    private int $actionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translations = $this->service(AdminActionTranslationService::class);
        $action = (new ActionFactory($this->em))->createImmediateEmailAction('qa_action_legacy_ph_table');
        $this->actionId = (int) $action->getId();
    }

    public function testBulkCreateRejectsLegacyPlaceholderContent(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);

        $this->translations->bulkCreateTranslations($this->actionId, [[
            'id_languages' => $this->languageId('en-GB'),
            'translation_key' => 'qa_block.job.email.subject',
            'content' => 'Welcome @user_name',
        ]]);
    }

    public function testBulkCreateAcceptsCanonicalPlaceholderContent(): void
    {
        $result = $this->translations->bulkCreateTranslations($this->actionId, [[
            'id_languages' => $this->languageId('en-GB'),
            'translation_key' => 'qa_block.job.email.subject',
            'content' => 'Welcome {{recipient.user_name}}',
        ]]);

        self::assertNotEmpty($result['created'], 'Canonical {{...}} placeholder content must be accepted and persisted.');
    }

    private function languageId(string $locale): int
    {
        $language = $this->em->getRepository(Language::class)->findOneBy(['locale' => $locale]);
        self::assertInstanceOf(
            Language::class,
            $language,
            sprintf('Seeded language "%s" is required. Run: composer test:reset-db', $locale)
        );
        $id = $language->getId();
        self::assertIsInt($id);

        return $id;
    }
}
