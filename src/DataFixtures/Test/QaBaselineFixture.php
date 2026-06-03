<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Entity\ValidationCode;
use App\Service\Core\LookupService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Canonical QA baseline: the four permission personas every backend test
 * logs in as, seeded once by `php bin/console app:test:reset-db` and kept
 * stable across the run by DAMA transaction rollback.
 *
 * WHY this mirrors {@see \App\Command\CreateAdminUserCommand} exactly
 * (active status lookup, user-type lookup, group membership through
 * `rel_groups_users`, admin role, validation code, bumped ACL version):
 * the seeded users must be INDISTINGUISHABLE from production users so the
 * permission checks the tests exercise are the real ones. Do not invent a
 * simplified fake role here (plan §16 / AGENTS.md Testing Rule 8).
 *
 * Persona → real seeded group/role mapping. The baseline schema seeds
 * exactly three groups (`admin`, `therapist`, `subject`) and one role
 * (`admin`) — there is no `editor`/`user`/`guest` role in production, so
 * the personas map onto the REAL groups instead of fabricating roles:
 *
 *   qa.admin   group=admin     role=admin   full admin access
 *   qa.editor  group=therapist (no role)    content/experiment manager
 *   qa.user    group=subject   (no role)    ordinary end user
 *   qa.guest   (no group)      (no role)    authenticated but unprivileged
 *
 * Only qa.admin holds the admin role, so only qa.admin passes admin API
 * route permissions — which is exactly what the Slice 3 permission matrix
 * asserts (admin → 200, everyone else → 403).
 *
 * The fixture is tagged with the `qa` group so `doctrine:fixtures:load
 * --group=qa --append` loads ONLY these rows on top of the migration
 * baseline (never purging the seeded lookups/styles/routes).
 */
final class QaBaselineFixture extends Fixture implements FixtureGroupInterface
{
    /**
     * Bump whenever the seeded rows change shape (added/removed/renamed
     * persona or attribute). Smoke tests read the marker user's name and
     * assert it equals this constant so a DB seeded by an OLD fixture fails
     * fast with a clear message instead of producing confusing downstream
     * failures (plan §32 / AGENTS.md Testing Rule 32).
     */
    public const QA_FIXTURE_VERSION = '2026_05_22_001';

    /** Shared password for every QA persona. Test-only, never a real secret. */
    public const QA_PASSWORD = 'QaPassw0rd!2026';

    public const QA_ADMIN_EMAIL = 'qa.admin@selfhelp.test';
    public const QA_EDITOR_EMAIL = 'qa.editor@selfhelp.test';
    public const QA_USER_EMAIL = 'qa.user@selfhelp.test';
    public const QA_GUEST_EMAIL = 'qa.guest@selfhelp.test';

    /**
     * Marker user whose display name carries QA_FIXTURE_VERSION so the
     * applied fixture version is observable from the database itself.
     */
    public const QA_FIXTURE_MARKER_EMAIL = 'qa.fixture@selfhelp.test';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['qa'];
    }

    public function load(ObjectManager $manager): void
    {
        $activeStatus = $manager->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => LookupService::USER_STATUS_ACTIVE,
        ]);
        $userType = $manager->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_TYPES,
            'lookupCode' => LookupService::USER_TYPES_USER,
        ]);

        if (!$activeStatus instanceof Lookup || !$userType instanceof Lookup) {
            throw new \RuntimeException(
                'QA baseline cannot seed users: userStatus/userTypes lookups are missing. '
                . 'Run migrations before loading fixtures (php bin/console doctrine:migrations:migrate).'
            );
        }

        $adminGroup = $manager->getRepository(Group::class)->findOneBy(['name' => 'admin']);
        $adminRole = $manager->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        if (!$adminGroup instanceof Group || !$adminRole instanceof Role) {
            throw new \RuntimeException(
                'QA baseline cannot seed qa.admin: the seeded "admin" group/role is missing. '
                . 'The schema baseline migrations must run first.'
            );
        }

        // therapist/subject are part of the production seed too; map the
        // editor/user personas onto them. Resolve defensively so the
        // fixture still seeds the critical admin persona on an unusual DB.
        $therapistGroup = $manager->getRepository(Group::class)->findOneBy(['name' => 'therapist']);
        $subjectGroup = $manager->getRepository(Group::class)->findOneBy(['name' => 'subject']);

        $this->createPersona(
            $manager,
            self::QA_ADMIN_EMAIL,
            'QA Admin',
            $activeStatus,
            $userType,
            // $adminGroup is guaranteed non-null by the guard above, so no filter.
            [$adminGroup],
            $adminRole,
        );
        $this->createPersona(
            $manager,
            self::QA_EDITOR_EMAIL,
            'QA Editor',
            $activeStatus,
            $userType,
            array_filter([$therapistGroup]),
            null,
        );
        $this->createPersona(
            $manager,
            self::QA_USER_EMAIL,
            'QA User',
            $activeStatus,
            $userType,
            array_filter([$subjectGroup]),
            null,
        );
        $this->createPersona(
            $manager,
            self::QA_GUEST_EMAIL,
            'QA Guest',
            $activeStatus,
            $userType,
            [],
            null,
        );

        // Version marker: name carries QA_FIXTURE_VERSION (see constant docblock).
        $this->createPersona(
            $manager,
            self::QA_FIXTURE_MARKER_EMAIL,
            self::QA_FIXTURE_VERSION,
            $activeStatus,
            $userType,
            [],
            null,
        );

        $manager->flush();
    }

    /**
     * @param list<Group> $groups
     */
    private function createPersona(
        ObjectManager $manager,
        string $email,
        string $displayName,
        Lookup $activeStatus,
        Lookup $userType,
        array $groups,
        ?Role $role,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setName($displayName);
        $user->setUserName($email);
        $user->setBlocked(false);
        $user->setIntern(false);
        $user->setStatus($activeStatus);
        $user->setUserType($userType);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::QA_PASSWORD));
        $user->bumpAclVersion();
        $manager->persist($user);

        foreach ($groups as $group) {
            $membership = new UsersGroup();
            $membership->setUser($user);
            $membership->setGroup($group);
            $manager->persist($membership);
        }

        if ($role instanceof Role) {
            $user->addRole($role);
        }

        // Deterministic validation code (no randomness, anti-flakiness §9):
        // derived from the email so re-seeding a fresh DB is reproducible.
        $code = new ValidationCode();
        $code->setCode(strtoupper(substr(hash('sha256', 'qa-validation-' . $email), 0, 16)));
        $code->setUser($user);
        $manager->persist($code);

        return $user;
    }
}
