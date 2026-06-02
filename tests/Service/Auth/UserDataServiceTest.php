<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Language;
use App\Entity\Lookup;
use App\Entity\User;
use App\Service\Auth\UserDataService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Service-level coverage for {@see UserDataService} language/timezone resolution
 * and language update — the parts not exercised by
 * {@see \App\Tests\Controller\Api\V1\Auth\UserDataControllerTest} (which covers
 * the full getUserData permission contract). Mutations target qa.user and are
 * rolled back by DAMA.
 */
final class UserDataServiceTest extends QaKernelTestCase
{
    private UserDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(UserDataService::class);
    }

    public function testLanguageInfoReturnsExplicitUserLanguage(): void
    {
        $language = $this->anyLanguage();
        $user = $this->qaUser();
        $user->setLanguage($language);
        $this->em->flush();

        $info = $this->service->getUserLanguageInfo($user);

        self::assertSame($language->getId(), $info['id']);
        self::assertSame($language->getLocale(), $info['locale']);
        self::assertSame($language->getLanguage(), $info['name']);
    }

    public function testLanguageInfoFallsBackWhenUserHasNone(): void
    {
        $user = $this->qaUser();
        $user->setLanguage(null);
        $this->em->flush();

        $info = $this->service->getUserLanguageInfo($user);

        // Fallback resolves to the CMS default (or the id=2 safety net); either
        // way the resolver must always yield a concrete language.
        self::assertNotNull($info['id'], 'Language must always resolve to a fallback.');
        self::assertNotNull($info['locale']);
    }

    public function testTimezoneInfoReturnsExplicitUserTimezone(): void
    {
        $timezone = $this->anyTimezone();
        $user = $this->qaUser();
        $user->setTimezone($timezone);
        $this->em->flush();

        $info = $this->service->getUserTimezoneInfo($user);

        self::assertIsArray($info);
        self::assertSame($timezone->getId(), $info['id']);
        self::assertSame($timezone->getLookupCode(), $info['code']);
    }

    public function testSetUserLanguageUpdatesPreferenceAndReturnsInfo(): void
    {
        $language = $this->anyLanguage();
        $user = $this->qaUser();
        $userId = (int) $user->getId();

        $result = $this->service->setUserLanguage($user, (int) $language->getId());

        self::assertSame((int) $language->getId(), $result['language_id']);
        self::assertSame($language->getLocale(), $result['language_locale']);

        // DB side effect: the preference is persisted.
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($language->getId(), $reloaded->getLanguage()?->getId());
    }

    public function testSetUserLanguageRejectsInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setUserLanguage($this->qaUser(), 99000222);
    }

    // -- helpers ------------------------------------------------------------

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function anyLanguage(): Language
    {
        // Skip the "all languages" sentinel (id 1) used internally; pick a real one.
        $language = $this->em->getRepository(Language::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Language::class, $language, 'No seeded Language. Run: composer test:reset-db');

        return $language;
    }

    private function anyTimezone(): Lookup
    {
        $tz = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => 'timezones',
            'lookupCode' => 'Europe/Zurich',
        ]);
        self::assertInstanceOf(Lookup::class, $tz, 'No seeded Europe/Zurich timezone. Run: composer test:reset-db');

        return $tz;
    }
}
