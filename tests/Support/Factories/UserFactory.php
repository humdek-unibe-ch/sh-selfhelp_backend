<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Entity\ValidationCode;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Builds `qa.`-prefixed users that are INDISTINGUISHABLE from production users —
 * active status lookup, user-type lookup, hashed password, group membership
 * through `rel_groups_users`, optional roles, a validation code and a bumped ACL
 * version — exactly like {@see QaBaselineFixture} and the production
 * {@see \App\Command\CreateAdminUserCommand}.
 *
 * Unlike the five fixed QA personas, this factory lets a single test stand up a
 * fresh, loginable user in any state (blocked, locked, in a specific group / role)
 * so workflow tests can exercise the real auth + permission path rather than a
 * fabricated fake. The default password is {@see QaBaselineFixture::QA_PASSWORD}
 * so created users authenticate through {@see \App\Tests\Support\QaWebTestCase::loginAs()}
 * with no extra wiring.
 *
 * Everything is persisted through the real EntityManager inside the DAMA
 * transaction and rolled back at tearDown; emails are qa-prefixed and the caller
 * supplies a deterministic slug (anti-flakiness §9).
 */
final class UserFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LookupService $lookupService,
    ) {
    }

    /**
     * Create a loginable, active, qa-prefixed user.
     *
     * @param list<Group> $groups   memberships seeded through UsersGroup
     * @param list<Role>  $roles    roles granted to the user
     */
    public function createUser(
        string $email,
        string $name = 'QA User',
        array $groups = [],
        array $roles = [],
        bool $blocked = false,
        string $statusCode = LookupService::USER_STATUS_ACTIVE,
        string $password = QaBaselineFixture::QA_PASSWORD,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setUserName($email);
        $user->setBlocked($blocked);
        $user->setIntern(false);
        $user->setStatus($this->lookup(LookupService::USER_STATUS, $statusCode));
        $user->setUserType($this->lookup(LookupService::USER_TYPES, LookupService::USER_TYPES_USER));
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->bumpAclVersion();
        $this->em->persist($user);

        foreach ($groups as $group) {
            $membership = new UsersGroup();
            $membership->setUser($user);
            $membership->setGroup($group);
            $this->em->persist($membership);
        }

        foreach ($roles as $role) {
            $user->addRole($role);
        }

        // Deterministic validation code derived from the email (no randomness,
        // anti-flakiness §9), mirroring the QA baseline persona seeding.
        $code = new ValidationCode();
        $code->setCode(strtoupper(substr(hash('sha256', 'qa-factory-' . $email), 0, 16)));
        $code->setUser($user);
        $this->em->persist($code);

        $this->em->flush();

        return $user;
    }

    /**
     * Attach a user to a group through the `rel_groups_users` join entity.
     */
    public function addToGroup(User $user, Group $group): UsersGroup
    {
        $membership = new UsersGroup();
        $membership->setUser($user);
        $membership->setGroup($group);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    private function lookup(string $typeCode, string $lookupCode): Lookup
    {
        $lookup = $this->lookupService->findByTypeAndCode($typeCode, $lookupCode);
        if (!$lookup instanceof Lookup) {
            throw new \RuntimeException(sprintf(
                'Missing lookup %s/%s. Run: composer test:reset-db',
                $typeCode,
                $lookupCode,
            ));
        }

        return $lookup;
    }
}
