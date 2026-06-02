<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Backend flagship integration test (plan §"backend flagship example").
 *
 * Exercises the real {@see \App\Command\CreateAdminUserCommand} end to end
 * through the DI-wired console application against the seeded QA baseline.
 * The command is the supported "fresh install bootstrap / forgot-my-admin-
 * password" tool, so the test asserts the FULL production user model it must
 * produce: active+non-blocked+validated user, hashed-and-verifiable password,
 * admin group + admin role membership, the `ROLE_ADMIN` security shape,
 * idempotent re-run (no duplicate users/relations, password reset), the
 * `--no-admin` / `--group` / `--role` option surface, validation-code reuse,
 * cache invalidation, and the two hard-failure paths (missing lookup, empty
 * password) that must leave NO partial user behind.
 *
 * Runs under DAMA (kernel test DB), so every write — including the raw
 * `rel_groups_users` INSERT the command performs — rolls back after each test.
 * All created data uses the `qa.`/`qa_` prefix (AGENTS.md Testing Rule 9).
 */
final class CreateAdminUserCommandTest extends QaKernelTestCase
{
    private const COMMAND = 'app:create-admin-user';

    /** Test-only password, never a real secret (AGENTS.md Testing Rule). */
    private const PASSWORD = 'QaCreateAdmin!2026';

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
    }

    /**
     * Unique, deterministic-per-case email so parallel/repeated runs never
     * collide and the data is unmistakably QA-owned.
     */
    private function email(string $case): string
    {
        return "qa.create-admin-command.{$case}@selfhelp.test";
    }

    /**
     * Always run non-interactively: the command's password prompt is a HIDDEN
     * question, which reads the real STDIN (not the CommandTester input stream)
     * and would hang the test runner. In non-interactive mode the QuestionHelper
     * returns the question's default (null here) without prompting, which is
     * exactly the "no password supplied" path we want to exercise.
     *
     * @param array<string, string|bool> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->application->find(self::COMMAND));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function findUser(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function countRows(string $table, int $userId): int
    {
        // $table is an internal literal (never user input); safe to interpolate.
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE id_users = :u",
            ['u' => $userId],
        );
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return $this->service(UserPasswordHasherInterface::class);
    }

    // -- 1. Creates an admin user with the full production shape -------------

    public function testCreatesPreValidatedAdminUserWithProductionRoleShape(): void
    {
        $email = $this->email('create');

        $tester = $this->runCommand([
            'email' => $email,
            'display-name' => 'QA Create Admin',
            '--password' => self::PASSWORD,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('admin user #', $tester->getDisplay());
        self::assertStringContainsString($email, $tester->getDisplay());

        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user, 'The admin user must exist after the command runs.');

        // Active / non-blocked / non-intern, mapped to the real lookups.
        self::assertFalse($user->isBlocked(), 'Admin user must not be blocked.');
        self::assertFalse($user->isIntern(), 'Admin user must not be intern.');
        self::assertInstanceOf(Lookup::class, $user->getStatus());
        self::assertSame(LookupService::USER_STATUS_ACTIVE, $user->getStatus()->getLookupCode());
        self::assertInstanceOf(Lookup::class, $user->getUserType());
        self::assertSame(LookupService::USER_TYPES_USER, $user->getUserType()->getLookupCode());

        // Password is hashed (not stored in plain text) and verifiable.
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(
            $this->hasher()->isPasswordValid($user, self::PASSWORD),
            'The stored hash must verify against the supplied password.',
        );

        // Admin group + admin role membership + production security shape.
        $userId = (int) $user->getId();
        self::assertSame(1, $this->countRows('rel_groups_users', $userId), 'Admin user joins exactly one group.');
        self::assertSame(1, $this->countRows('rel_roles_users', $userId), 'Admin user holds exactly one role.');
        self::assertContains('ROLE_ADMIN', $user->getRoles(), 'Admin user must carry ROLE_ADMIN.');
        self::assertSame(['ROLE_ADMIN'], array_values($user->getRoles()), 'getRoles() must equal the admin shape.');

        // Validation code was created so the account is immediately usable.
        self::assertSame(1, $this->countRows('validation_codes', $userId), 'A validation code must be issued.');

        // ACL version was set so cached ACLs refresh for the new admin.
        self::assertNotNull($user->getAclVersion());
    }

    // -- 2. Idempotent re-run resets the password without duplicating --------

    public function testRerunResetsPasswordAndDoesNotDuplicateMembership(): void
    {
        $email = $this->email('rerun');
        $newPassword = self::PASSWORD . '-rotated';

        $this->runCommand([
            'email' => $email,
            'display-name' => 'QA Rerun Admin',
            '--password' => self::PASSWORD,
        ])->assertCommandIsSuccessful();

        $first = $this->findUser($email);
        self::assertInstanceOf(User::class, $first);
        $firstId = (int) $first->getId();

        $tester = $this->runCommand([
            'email' => $email,
            'display-name' => 'QA Rerun Admin',
            '--password' => $newPassword,
        ]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Updated', $tester->getDisplay(), 'Re-run must report an update, not a create.');

        // Re-fetch from a clean identity map so we observe the persisted state.
        $this->em->clear();
        $second = $this->findUser($email);
        self::assertInstanceOf(User::class, $second);

        // Not duplicated: same row id, single users row, single relation rows.
        self::assertSame($firstId, (int) $second->getId(), 'Re-run must update the same user, not create a new one.');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM users WHERE email = :e',
                ['e' => $email],
            ),
            'Re-run must not duplicate the user row.',
        );
        self::assertSame(1, $this->countRows('rel_groups_users', $firstId), 'Group membership must not be duplicated.');
        self::assertSame(1, $this->countRows('rel_roles_users', $firstId), 'Role membership must not be duplicated.');

        // Password was reset: the new one verifies, the old one no longer does.
        self::assertTrue($this->hasher()->isPasswordValid($second, $newPassword), 'New password must verify.');
        self::assertFalse($this->hasher()->isPasswordValid($second, self::PASSWORD), 'Old password must no longer verify.');
        self::assertContains('ROLE_ADMIN', $second->getRoles(), 'Admin role must remain after re-run.');
    }

    // -- 3. --no-admin creates a plain validated user ------------------------

    public function testNoAdminOptionCreatesPlainValidatedUserWithoutAdminMembership(): void
    {
        $email = $this->email('no-admin');

        $this->runCommand([
            'email' => $email,
            'display-name' => 'QA Plain User',
            '--password' => self::PASSWORD,
            '--no-admin' => true,
        ])->assertCommandIsSuccessful();

        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);
        $userId = (int) $user->getId();

        // Still a correct active validated user...
        self::assertFalse($user->isBlocked());
        self::assertInstanceOf(Lookup::class, $user->getStatus());
        self::assertSame(LookupService::USER_STATUS_ACTIVE, $user->getStatus()->getLookupCode());
        self::assertSame(1, $this->countRows('validation_codes', $userId), 'Plain user is still validated.');
        self::assertTrue($this->hasher()->isPasswordValid($user, self::PASSWORD));

        // ...but with NO admin group/role.
        self::assertSame(0, $this->countRows('rel_groups_users', $userId), '--no-admin must not assign a group.');
        self::assertSame(0, $this->countRows('rel_roles_users', $userId), '--no-admin must not assign a role.');
        self::assertSame([], $user->getRoles(), '--no-admin user must have no roles.');
    }

    // -- 4. Custom --group / --role options ---------------------------------

    public function testCustomGroupAndRoleOptionsAssignRequestedMembership(): void
    {
        $email = $this->email('custom-group');

        // therapist group + admin role both exist in the production seed.
        $this->runCommand([
            'email' => $email,
            'display-name' => 'QA Custom Membership',
            '--password' => self::PASSWORD,
            '--group' => 'therapist',
            '--role' => 'admin',
        ])->assertCommandIsSuccessful();

        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);
        $userId = (int) $user->getId();

        $therapist = $this->em->getRepository(Group::class)->findOneBy(['name' => 'therapist']);
        self::assertInstanceOf(Group::class, $therapist);

        $membershipGroupId = $this->em->getConnection()->fetchOne(
            'SELECT id_groups FROM rel_groups_users WHERE id_users = :u',
            ['u' => $userId],
        );
        self::assertSame((int) $therapist->getId(), (int) $membershipGroupId, 'User must join the requested group.');
        self::assertContains('ROLE_ADMIN', $user->getRoles(), 'User must hold the requested role.');
    }

    // -- 4b. Unknown group/role warns but still creates the user ------------

    public function testUnknownGroupAndRoleWarnButStillCreateValidatedUser(): void
    {
        $email = $this->email('unknown-membership');

        $tester = $this->runCommand([
            'email' => $email,
            'display-name' => 'QA Unknown Membership',
            '--password' => self::PASSWORD,
            '--group' => 'qa_nonexistent_group',
            '--role' => 'qa_nonexistent_role',
        ]);

        // Command tolerates unknown group/role: it warns and still creates a
        // validated user (this is the documented production behaviour).
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('qa_nonexistent_group', $display);
        self::assertStringContainsString('qa_nonexistent_role', $display);

        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);
        $userId = (int) $user->getId();
        self::assertSame(0, $this->countRows('rel_groups_users', $userId), 'Unknown group must not be assigned.');
        self::assertSame(0, $this->countRows('rel_roles_users', $userId), 'Unknown role must not be assigned.');
        self::assertSame(1, $this->countRows('validation_codes', $userId), 'User is still validated.');
    }

    // -- 5. Validation-code reuse on re-run ---------------------------------

    public function testActiveValidationCodeIsReusedOnRerun(): void
    {
        $email = $this->email('code-reuse');

        $this->runCommand([
            'email' => $email,
            '--password' => self::PASSWORD,
        ])->assertCommandIsSuccessful();

        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);
        $userId = (int) $user->getId();

        $codeBefore = $this->em->getConnection()->fetchOne(
            'SELECT code FROM validation_codes WHERE id_users = :u',
            ['u' => $userId],
        );
        self::assertNotFalse($codeBefore);

        // A real second CLI invocation is a fresh process with a fresh EM, so
        // it loads the user (and its existing validation code) from the DB.
        // Clear the identity map here to faithfully model that; otherwise the
        // shared in-memory inverse collection would look empty.
        $this->em->clear();

        // Re-run: the existing unconsumed code must be reused, not duplicated.
        $this->runCommand([
            'email' => $email,
            '--password' => self::PASSWORD . '-again',
        ])->assertCommandIsSuccessful();

        self::assertSame(1, $this->countRows('validation_codes', $userId), 'Unconsumed validation code must be reused.');
        $codeAfter = $this->em->getConnection()->fetchOne(
            'SELECT code FROM validation_codes WHERE id_users = :u',
            ['u' => $userId],
        );
        self::assertSame($codeBefore, $codeAfter, 'The reused validation code must be identical.');
    }

    // -- 6. Cache invalidation ----------------------------------------------

    public function testCommandInvalidatesUsersAndPermissionsCaches(): void
    {
        $cache = $this->service(CacheService::class);
        $probe = 'qa_create_admin_cache_probe_' . bin2hex(random_bytes(6));

        $usersList = static fn (CacheService $c, string $val): mixed => $c
            ->withCategory(CacheService::CATEGORY_USERS)
            ->getList($probe, static fn (): string => $val);
        $permsList = static fn (CacheService $c, string $val): mixed => $c
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->getList($probe, static fn (): string => $val);

        // Prime both list caches and prove they are cached (second call returns
        // the original value even though the compute would return a new one).
        self::assertSame('seed', $usersList($cache, 'seed'));
        self::assertSame('seed', $usersList($cache, 'recompute-should-not-run'));
        self::assertSame('seed', $permsList($cache, 'seed'));
        self::assertSame('seed', $permsList($cache, 'recompute-should-not-run'));

        $this->runCommand([
            'email' => $this->email('cache'),
            '--password' => self::PASSWORD,
        ])->assertCommandIsSuccessful();

        // The command invalidates the USERS + PERMISSIONS list tags, so the
        // primed probes must now miss and recompute the fresh value.
        self::assertSame('after-users-invalidation', $usersList($cache, 'after-users-invalidation'));
        self::assertSame('after-perms-invalidation', $permsList($cache, 'after-perms-invalidation'));
    }

    // -- 7. Missing lookup failure ------------------------------------------

    public function testMissingActiveStatusLookupFailsWithoutCreatingUser(): void
    {
        $email = $this->email('missing-lookup');

        // Make the active userStatus lookup unresolvable for the command's
        // exact-match query by renaming its code. FK references are by id, so
        // renaming the string code does not break existing users; DAMA rolls
        // the change back after the test.
        $activeStatus = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => LookupService::USER_STATUS_ACTIVE,
        ]);
        self::assertInstanceOf(Lookup::class, $activeStatus);
        $activeStatus->setLookupCode('qa_temporarily_renamed_active');
        $this->em->flush();

        $tester = $this->runCommand([
            'email' => $email,
            '--password' => self::PASSWORD,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Active user status lookup not found', $tester->getDisplay());
        self::assertNull($this->findUser($email), 'A failed run must leave no partial user behind.');
    }

    // -- 8. Empty password failure ------------------------------------------

    public function testEmptyPasswordFailsWithoutCreatingUser(): void
    {
        $email = $this->email('empty-password');

        // No --password option: run non-interactively so the hidden prompt
        // resolves to its (empty) default and the command rejects it.
        $tester = $this->runCommand([
            'email' => $email,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Password is required', $tester->getDisplay());
        self::assertNull($this->findUser($email), 'An empty-password run must leave no partial user behind.');
    }
}
