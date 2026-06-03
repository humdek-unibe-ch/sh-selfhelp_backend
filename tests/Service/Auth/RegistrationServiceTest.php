<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\Group;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Entity\ValidationCode;
use App\Service\Auth\RegistrationService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Integration coverage for {@see RegistrationService} — the atomic, single-use
 * consumption of a registration code and its linkage to the new user.
 *
 * Uses the seeded `register` page (code-required by default: the register
 * style's open_registration default is 0) and real services end to end (no
 * domain mocking). All rows are qa-scoped and rolled back by DAMA.
 *
 * Concurrency note: DAMA wraps the whole test in ONE connection/transaction, so
 * genuinely parallel connections cannot be exercised here. The "only one user
 * per code" guarantee is therefore proven by a deterministic SEQUENTIAL
 * double-use (the second attempt must see consumed != null and be rejected) —
 * the same outcome the FOR UPDATE lock enforces under real concurrency.
 */
#[TestGroup('integration')]
final class RegistrationServiceTest extends QaKernelTestCase
{
    private RegistrationService $service;
    private Group $qaGroup;
    private int $registerPageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(RegistrationService::class);
        $this->qaGroup = (new GroupFactory($this->em))->createGroup('qa_register_group');
        $this->registerPageId = $this->resolveRegisterPageId();
    }

    public function testRegisterWithValidCodeCreatesBlockedUserConsumesAndLinksCode(): void
    {
        $code = $this->seedCode('QAREGVALID1', $this->qaGroup);
        $email = 'qa_register_valid@selfhelp.test';

        $this->service->register($this->registerPageId, $email, $code);

        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user, 'A user must be created.');
        self::assertTrue($user->isBlocked(), 'A freshly registered user is blocked until validation.');
        self::assertSame(
            LookupService::USER_STATUS_INVITED,
            $user->getStatus()?->getLookupCode(),
            'Registration leaves the account in the invited state pending email validation.'
        );
        self::assertSame(1, $this->groupMembershipCount($user, $this->qaGroup), 'The user joins the code group.');

        // The code row in the database is consumed and linked to the new user.
        $row = $this->codeRow($code);
        self::assertNotNull($row['consumed'], 'A successful registration consumes the code.');
        self::assertSame((int) $user->getId(), (int) $row['id_users'], 'validation_codes.id_users must be the new user id.');
    }

    public function testRegisterWithoutCodeInCodeRequiredModeIsRejected(): void
    {
        $email = 'qa_register_nocode@selfhelp.test';

        try {
            $this->service->register($this->registerPageId, $email, null);
            self::fail('Registration without a code must be rejected in code-required mode.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('registration code is required', $e->getMessage());
        }

        self::assertNull($this->findUser($email), 'No user may be created without a valid code.');
    }

    public function testRegisterWithInvalidCodeIsRejectedAndCreatesNoUser(): void
    {
        $email = 'qa_register_badcode@selfhelp.test';

        try {
            $this->service->register($this->registerPageId, $email, 'NOSUCHCODE0');
            self::fail('An unknown code must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid registration code', $e->getMessage());
        }

        self::assertNull($this->findUser($email), 'A failed registration must not create a user.');
    }

    public function testAlreadyConsumedCodeCannotBeReused(): void
    {
        $code = $this->seedCode('QAREGUSED01', $this->qaGroup, consumed: true);
        $email = 'qa_register_consumed@selfhelp.test';

        try {
            $this->service->register($this->registerPageId, $email, $code);
            self::fail('An already-consumed code must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already been used', $e->getMessage());
        }

        self::assertNull($this->findUser($email));
    }

    public function testFailedRegistrationLeavesTheCodeUnconsumedAndUnlinked(): void
    {
        // A code with no group fails AFTER it is claimed but BEFORE the user is
        // created, proving the transaction rolls back validation_codes untouched
        // and never leaves an orphan user behind.
        $code = $this->seedCode('QAREGNOGRP1', null);
        $email = 'qa_register_nogroup@selfhelp.test';

        try {
            $this->service->register($this->registerPageId, $email, $code);
            self::fail('A code with no group must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no group assigned', $e->getMessage());
        }

        $row = $this->codeRow($code);
        self::assertNull($row['consumed'], 'A failed registration must NOT consume the code.');
        self::assertNull($row['id_users'], 'A failed registration must NOT link the code to a user.');
        self::assertNull($this->findUser($email), 'A failed registration must not create an orphan user.');
    }

    public function testCodeCanBeConsumedOnlyOnceUnderSequentialDoubleUse(): void
    {
        $code = $this->seedCode('QAREGONCE01', $this->qaGroup);
        $firstEmail = 'qa_register_first@selfhelp.test';
        $secondEmail = 'qa_register_second@selfhelp.test';

        $this->service->register($this->registerPageId, $firstEmail, $code);

        try {
            $this->service->register($this->registerPageId, $secondEmail, $code);
            self::fail('Re-using the same code must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already been used', $e->getMessage());
        }

        $firstUser = $this->findUser($firstEmail);
        self::assertInstanceOf(User::class, $firstUser, 'The first registration must succeed.');
        self::assertNull($this->findUser($secondEmail), 'The second registration must create no user.');

        $row = $this->codeRow($code);
        self::assertNotNull($row['consumed']);
        self::assertSame((int) $firstUser->getId(), (int) $row['id_users'], 'The code stays linked to the FIRST user only.');
    }

    // -- helpers ------------------------------------------------------------

    private function resolveRegisterPageId(): int
    {
        $raw = $this->em->getConnection()->fetchOne("SELECT id FROM pages WHERE keyword = 'register' LIMIT 1");
        self::assertNotFalse($raw, 'The seeded register page must exist (run composer test:reset-db).');

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function seedCode(string $code, ?Group $group, bool $consumed = false): string
    {
        $entity = new ValidationCode();
        $entity->setCode($code);
        $entity->setGroup($group);
        if ($consumed) {
            $entity->setConsumed(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }
        $this->em->persist($entity);
        $this->em->flush();

        return $code;
    }

    private function findUser(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower($email)]);
    }

    private function groupMembershipCount(User $user, Group $group): int
    {
        // UsersGroup has a composite identity, so COUNT(ug) is not a valid DQL
        // path expression; hydrate the matching rows and count them instead.
        $rows = $this->em->createQuery(
            'SELECT ug FROM ' . UsersGroup::class . ' ug WHERE ug.user = :user AND ug.group = :group'
        )->setParameter('user', $user)->setParameter('group', $group)->getResult();

        return is_array($rows) ? count($rows) : 0;
    }

    /**
     * @return array{consumed: ?string, id_users: ?int}
     */
    private function codeRow(string $code): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT consumed, id_users FROM validation_codes WHERE code = :code',
            ['code' => $code]
        );
        self::assertIsArray($row, "Code {$code} must exist.");

        $consumed = $row['consumed'] ?? null;
        $idUsers = $row['id_users'] ?? null;

        return [
            'consumed' => is_string($consumed) ? $consumed : null,
            'id_users' => is_numeric($idUsers) ? (int) $idUsers : null,
        ];
    }
}
