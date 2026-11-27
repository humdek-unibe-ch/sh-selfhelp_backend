<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Admin;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\PageEditLockService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\PhpUnit\Attribute\TimeSensitive;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F258: after the idle end (holder kept renewing, never wrote), renew answers
 * lock_idle_ended; take-over and a draft write resume. Service-level so ClockMock
 * is unambiguous.
 */
#[Group('security')]
#[TimeSensitive(PageEditLockService::class)]
final class PageEditLockIdleEndTest extends QaWebTestCase
{
    private PageEditLockService $locks;
    private User $alice;
    private User $bob;
    private int $pageId = 424242;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        ClockMock::register(PageEditLockService::class);
        $this->now = time();
        ClockMock::withClockMock($this->now);

        $this->locks = $this->service(PageEditLockService::class);
        $this->locks->forget($this->pageId);

        $em = $this->service(EntityManagerInterface::class);
        $hasher = $this->service(UserPasswordHasherInterface::class);
        $this->alice = $this->persistUser($em, $hasher, 'qa.lock.idle.alice@selfhelp.test', 'Alice Idle');
        $this->bob = $this->persistUser($em, $hasher, 'qa.lock.idle.bob@selfhelp.test', 'Bob Idle');
    }

    protected function tearDown(): void
    {
        $this->locks->forget($this->pageId);
        ClockMock::withClockMock(false);
        parent::tearDown();
    }

    public function testRenewAfterIdleEndIsRefusedAndTakeOverResumes(): void
    {
        $this->holdThroughRenewals($this->alice);

        // Past both the idle end (900) and the lease end from the last renewal (920).
        ClockMock::withClockMock($this->now + PageEditLockService::IDLE_SECONDS + PageEditLockService::LEASE_SECONDS);

        try {
            $this->locks->acquire($this->pageId, $this->alice);
            self::fail('Expected lock_idle_ended');
        } catch (ServiceException $e) {
            self::assertSame(PageEditLockService::ERROR_IDLE_ENDED, $e->getMessage());
            self::assertSame(Response::HTTP_CONFLICT, $e->getCode());
            $data = $e->getData() ?? [];
            self::assertFalse($data['held'] ?? true);
            self::assertTrue(($data['holder'] ?? null) === null);
        }

        $status = $this->locks->takeOver($this->pageId, $this->alice);
        self::assertTrue($status['mine']);
        self::assertTrue($status['held']);
    }

    public function testAnotherUserMayAcquireAfterIdleEnd(): void
    {
        $this->holdThroughRenewals($this->alice);

        ClockMock::withClockMock($this->now + PageEditLockService::IDLE_SECONDS + PageEditLockService::LEASE_SECONDS);

        $status = $this->locks->acquire($this->pageId, $this->bob);
        self::assertTrue($status['mine']);
        $holder = self::asArray($status['holder'] ?? null);
        self::assertSame('Bob Idle', $holder['name'] ?? null);
    }

    public function testDraftWriteAfterIdleEndTakesTheLock(): void
    {
        $this->holdThroughRenewals($this->alice);

        ClockMock::withClockMock($this->now + PageEditLockService::IDLE_SECONDS + PageEditLockService::LEASE_SECONDS);

        self::assertTrue($this->locks->assertMayWrite($this->pageId, $this->alice));
        $status = $this->locks->status($this->pageId, (int) $this->alice->getId());
        self::assertTrue($status['mine']);
    }

    /** Renew every 100 s through 800 so the idle end (900) arrives before the lease end (920). */
    private function holdThroughRenewals(User $holder): void
    {
        $this->locks->acquire($this->pageId, $holder);
        for ($at = 100; $at <= 800; $at += 100) {
            ClockMock::withClockMock($this->now + $at);
            $this->locks->acquire($this->pageId, $holder);
        }
    }

    private function persistUser(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        string $email,
        string $name,
    ): User {
        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $users = new UserFactory(
            $em,
            $hasher,
            $this->service(LookupService::class),
        );

        return $users->createUser($email, $name);
    }
}
