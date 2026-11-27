<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Page;
use App\Service\CMS\CmsPreferenceService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coverage for the admin CMS-preferences read API (Phase 9 — orphan wiring).
 *
 * `AdminCmsPreferenceController::getCmsPreferences()` (GET /admin/cms-preferences)
 * was implemented but never registered in `api_routes`, so the frontend
 * `useCmsPreferences()` hook resolved to a 404. `Version20260602134124` wires
 * the route and guards it with `admin.cms_preferences.read` (admin-only). These
 * tests assert the read contract the frontend `ICMSPreferences` type consumes
 * plus the negative-permission matrix.
 */
final class AdminCmsPreferenceControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const URI = '/cms-api/v1/admin/cms-preferences';

    public function testGetCmsPreferencesReturnsPreferenceContract(): void
    {
        $envelope = $this->jsonRequest('GET', self::URI, null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // The frontend ICMSPreferences shape: every key must be present.
        foreach (['id', 'default_language_id', 'default_language', 'anonymous_users', 'firebase_config', 'default_timezone'] as $key) {
            self::assertArrayHasKey($key, $data, "CMS preferences must expose '{$key}'");
        }

        // A 200 only happens when the seeded sh-cms-preferences page anchors a
        // non-null id (the service returns 404 otherwise).
        self::assertNotNull($data['id'], 'CMS preferences id must resolve from the seeded sh-cms-preferences page');
    }

    public function testSavingDefaultLanguageIndependentIdIsRejected(): void
    {
        $admin = $this->loginAsQaAdmin();
        $before = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $admin));
        $previous = $before['default_language_id'] ?? null;
        $pageId = self::coerceInt($before['id'] ?? null);

        $em = $this->service(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $field = $em->getRepository(Field::class)->findOneBy(['name' => 'default_language_id']);
        self::assertInstanceOf(Field::class, $field);
        $page = $em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame(CmsPreferenceService::SH_CMS_PREFERENCES_KEYWORD, $page->getKeyword());

        $envelope = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/pages/' . $pageId,
            [
                'pageData' => new \stdClass(),
                'fields' => [[
                    'fieldId' => (int) $field->getId(),
                    'languageId' => Language::INDEPENDENT_ID,
                    'content' => (string) Language::INDEPENDENT_ID,
                ]],
            ],
            $admin,
        );

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $this->client->getResponse()->getStatusCode(),
            'Expected 400, got envelope: ' . json_encode($envelope)
        );
        self::assertStringContainsString('language-independent', self::coerceString($envelope['error'] ?? ''));

        $after = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $admin));
        self::assertSame($previous, $after['default_language_id'] ?? null);
    }

    #[Group('security')]
    public function testCmsPreferencesReadEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::URI);
    }
}
