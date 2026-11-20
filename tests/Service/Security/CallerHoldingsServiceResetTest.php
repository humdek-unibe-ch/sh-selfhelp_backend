<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\CallerHoldingsService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F211 regression: {@see CallerHoldingsService} keeps what a caller holds in
 * properties for the life of the service. In a long-running process (Messenger
 * worker) that outlives a role change, so Symfony must reset it between
 * requests (`kernel.reset`, autoconfigured from `ResetInterface`). The test
 * changes a role between two reads in one kernel and resets through the
 * container's own resetter, the one the kernel runs after each request.
 */
#[Group('security')]
final class CallerHoldingsServiceResetTest extends QaKernelTestCase
{
    private const PERMISSION = 'admin.user.read';

    public function testResetBetweenRequestsShowsTheChangedRole(): void
    {
        $roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $role = $roles->createRole('qa_holdings_reset_role');
        $user = $users->createUser('qa.holdings.reset@selfhelp.test', 'QA Holdings Reset', roles: [$role]);
        $userId = (int) $user->getId();
        self::assertGreaterThan(0, $userId);

        $holdings = $this->service(CallerHoldingsService::class);
        self::assertFalse($holdings->holdsPermission($userId, self::PERMISSION), 'The role starts without the permission.');

        // The role gains the permission. Clear the user's cached permission list
        // the way a role write does, so only the in-memory holdings can be stale.
        $roles->grantPermission($role, self::PERMISSION);
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

        self::assertFalse(
            $holdings->holdsPermission($userId, self::PERMISSION),
            'Within one request the loaded holdings are kept (documents why the reset is needed).'
        );

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetter::class, $resetter);
        $resetter->reset();

        self::assertTrue(
            $holdings->holdsPermission($userId, self::PERMISSION),
            'After the kernel reset the service reads the changed role again (kernel.reset tag).'
        );
    }

    public function testResetEmptiesEveryLoadedMap(): void
    {
        $holdings = $this->service(CallerHoldingsService::class);
        $before = $holdings->allPermissionNameSet();
        self::assertNotSame([], $before, 'The permission catalogue is loaded.');

        $holdings->reset();

        $property = new \ReflectionProperty(CallerHoldingsService::class, 'permissionsById');
        self::assertNull($property->getValue($holdings), 'reset() forgets the loaded permissions.');
        self::assertSame($before, $holdings->allPermissionNameSet(), 'and they load again on the next read.');
    }
}
