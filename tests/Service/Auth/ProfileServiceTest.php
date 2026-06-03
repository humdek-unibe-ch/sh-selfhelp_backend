<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Auth\ProfileService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Focused service-level coverage for {@see ProfileService} paths that the
 * controller test does not exercise — specifically the system-account deletion
 * guard. Name/timezone/password/normal-delete flows are already covered through
 * {@see \App\Tests\Controller\Api\V1\Auth\ProfileControllerTest}; this test only
 * adds the security guard and the deletion DB side effect.
 *
 * The system-account check keys off the user's name ('admin'/'tpf'); the test
 * transiently renames a QA persona (rolled back by DAMA) rather than touching a
 * real system account.
 */
final class ProfileServiceTest extends QaKernelTestCase
{
    private ProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(ProfileService::class);
    }

    public function testSystemAccountCannotBeDeleted(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);

        // Make the managed entity look like a system account ('tpf'); DAMA rolls
        // this back. deleteAccount() re-reads the managed user and must refuse.
        $user->setName('tpf');
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete system accounts');
        $this->service->deleteAccount($user, (string) $user->getEmail());
    }

    public function testNonSystemAccountWithMatchingEmailIsDeleted(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $userId = (int) $user->getId();
        $email = (string) $user->getEmail();

        $this->service->deleteAccount($user, $email);

        self::assertNull(
            $this->em->getRepository(User::class)->find($userId),
            'A non-system account with matching email confirmation must be removed.'
        );
    }

    public function testDeleteAccountRejectsEmailMismatch(): void
    {
        $user = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deleteAccount($user, 'qa-not-my-email@selfhelp.test');
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
