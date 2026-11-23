<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Auth\UserContextService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * F265: {@see UserContextService} implements {@see \Symfony\Contracts\Service\ResetInterface}
 * and does not keep a memoized null after the token storage gains a user.
 */
#[Group('security')]
final class UserContextServiceResetTest extends QaKernelTestCase
{
    public function testGuestThenAuthenticatedThenResetShowsEachPrincipal(): void
    {
        $tokens = $this->service(TokenStorageInterface::class);
        $tokens->setToken(null);

        $context = $this->service(UserContextService::class);
        self::assertNull($context->getCurrentUser(), 'A guest resolves to null.');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $afterLogin = $context->getCurrentUser();
        self::assertInstanceOf(User::class, $afterLogin);
        self::assertSame(
            $userId,
            $afterLogin->getId(),
            'After a token is set, a previously memoized null must not stick (F265).',
        );

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetter::class, $resetter);
        $resetter->reset();

        $other = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_EDITOR_EMAIL]);
        self::assertInstanceOf(User::class, $other);
        $otherId = $other->getId();
        self::assertNotNull($otherId);
        $tokens->setToken(new UsernamePasswordToken($other, 'main', $other->getRoles()));

        $afterReset = $context->getCurrentUser();
        self::assertInstanceOf(User::class, $afterReset);
        self::assertSame(
            $otherId,
            $afterReset->getId(),
            'After reset() with a different token the new user is returned.',
        );
    }
}
