<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\User;
use App\Service\Auth\ProfileService;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Regression for the timezone update crash: changing the timezone must accept a
 * *cached* timezone {@see Lookup}. The lookup is read through {@see CacheService}
 * and on a cross-request cache hit it is a detached instance. Before the fix it
 * was assigned straight to the managed {@see User}, so {@see flush()} raised
 * "A new entity was found through the relationship App\Entity\User#timezone ...
 * not configured to cascade persist". The fix associates a managed reference.
 */
final class ProfileServiceTimezoneTest extends QaKernelTestCase
{
    public function testUpdateTimezoneAcceptsCachedDetachedLookup(): void
    {
        $profileService = $this->service(ProfileService::class);
        $cache = $this->service(CacheService::class);

        $timezone = $this->em->getRepository(Lookup::class)->findOneBy(['typeCode' => 'timezones']);
        self::assertInstanceOf(Lookup::class, $timezone, 'A timezones lookup must be seeded.');
        $timezoneId = (int) $timezone->getId();

        // Reproduce a cross-request cache hit: prime the exact key updateTimezone
        // reads with a detached lookup (as if deserialized from Redis), so it is
        // not managed by the current EntityManager.
        $this->em->detach($timezone);
        $cache
            ->withCategory(CacheService::CATEGORY_LOOKUPS)
            ->setItem("lookup_by_id_{$timezoneId}", $timezone);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        // Must not throw ORMInvalidArgumentException ("new entity was found").
        $profileService->updateTimezone($user, $timezoneId);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find((int) $user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(
            $timezoneId,
            $reloaded->getTimezone()?->getId(),
            'The user timezone must be persisted to the cached lookup id.'
        );
    }
}
