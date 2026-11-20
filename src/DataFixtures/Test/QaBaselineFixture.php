<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Entity\ValidationCode;
use App\Service\CMS\Admin\AdminRoleService;
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
 * ONE group (`subject`, the default group every new public page gives
 * `select`) and one role (`admin`); admin rights come from the role, there is
 * no admin group. There is no `editor`/`user`/`guest` role in production, so
 * the personas map onto the REAL group instead of fabricating roles. A test
 * that needs a second group creates a custom `qa_` group itself
 * (GroupFactory), it never relies on a seeded one:
 *
 *   qa.admin   (no group)       role=admin   full admin access
 *   qa.editor  group=subject    (no role)    non-admin with the default group
 *   qa.user    group=subject    (no role)    ordinary end user
 *   qa.guest   (no group)       (no role)    authenticated but unprivileged
 *
 * Only qa.admin holds the admin role among these four, so only qa.admin
 * passes admin API route permissions — which is exactly what the Slice 3
 * permission matrix asserts (admin → 200, everyone else → 403).
 *
 * Restricted personas (F239, least-privilege walk). For every core permission
 * `admin.<area>.read` the fixture seeds role `qa_read_<area>` holding exactly
 * `admin.access` + that read right, and user
 * `qa.read.<area with _ as ->@selfhelp.test` in it (see readPersonaEmail()).
 * Role `qa_read_update` holds `admin.access`, every core `admin.*.read` and
 * every core `admin.*.update`; its user is `qa.readupdate@selfhelp.test`. Its
 * view-only grants on the QA items are added by {@see QaAccessPersonaFixture}
 * once those items exist. Roles and their permissions go through
 * {@see AdminRoleService} as qa.admin (Testing Rule 8), never raw SQL. No
 * restricted persona has a group or two-factor login.
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
    public const QA_FIXTURE_VERSION = '2026_11_19_001';

    /** Shared password for every QA persona. Test-only, never a real secret. */
    public const QA_PASSWORD = 'QaPassw0rd!2026';

    public const QA_ADMIN_EMAIL = 'qa.admin@selfhelp.test';
    public const QA_EDITOR_EMAIL = 'qa.editor@selfhelp.test';
    public const QA_USER_EMAIL = 'qa.user@selfhelp.test';
    public const QA_GUEST_EMAIL = 'qa.guest@selfhelp.test';

    /** Persona holding every core read and update right (view grants only on the QA items). */
    public const QA_READ_UPDATE_EMAIL = 'qa.readupdate@selfhelp.test';
    public const QA_READ_UPDATE_ROLE = 'qa_read_update';

    /** Matches a core area read right and captures the area (`admin.page_version.read` → `page_version`). */
    private const READ_PERMISSION_PATTERN = '/^admin\.([a-z0-9_]+)\.read$/';
    private const UPDATE_PERMISSION_PATTERN = '/^admin\.[a-z0-9_]+\.update$/';
    private const ADMIN_ACCESS_PERMISSION = 'admin.access';

    /**
     * Marker user whose display name carries QA_FIXTURE_VERSION so the
     * applied fixture version is observable from the database itself.
     */
    public const QA_FIXTURE_MARKER_EMAIL = 'qa.fixture@selfhelp.test';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminRoleService $adminRoleService,
    ) {
    }

    /**
     * Email of the read-only persona for one core read right, by the persona
     * contract: `admin.page_version.read` → `qa.read.page-version@selfhelp.test`.
     */
    public static function readPersonaEmail(string $readPermission): string
    {
        return sprintf('qa.read.%s@selfhelp.test', str_replace('_', '-', self::readArea($readPermission)));
    }

    /** Role name of the read-only persona: `admin.page_version.read` → `qa_read_page_version`. */
    public static function readPersonaRole(string $readPermission): string
    {
        return 'qa_read_' . self::readArea($readPermission);
    }

    /** Whether a permission name is a core area read right (`admin.<area>.read`). */
    public static function isAreaReadPermission(string $permissionName): bool
    {
        return preg_match(self::READ_PERMISSION_PATTERN, $permissionName) === 1;
    }

    private static function readArea(string $readPermission): string
    {
        if (preg_match(self::READ_PERMISSION_PATTERN, $readPermission, $match) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an admin.<area>.read permission.', $readPermission));
        }

        return $match[1];
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

        $adminRole = $manager->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        if (!$adminRole instanceof Role) {
            throw new \RuntimeException(
                'QA baseline cannot seed qa.admin: no role with is_superuser is present. '
                . 'The schema baseline migrations must run first.'
            );
        }

        // `subject` is part of the production seed too; map the editor/user
        // personas onto it. Resolve defensively so the fixture still seeds the
        // critical admin persona on an unusual DB.
        $subjectGroup = $manager->getRepository(Group::class)->findOneBy(['name' => 'subject']);

        $qaAdmin = $this->createPersona(
            $manager,
            self::QA_ADMIN_EMAIL,
            'QA Admin',
            $activeStatus,
            $userType,
            [],
            $adminRole,
        );
        $this->createPersona(
            $manager,
            self::QA_EDITOR_EMAIL,
            'QA Editor',
            $activeStatus,
            $userType,
            array_filter([$subjectGroup]),
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

        $this->seedRestrictedPersonas($manager, (int) $qaAdmin->getId(), $activeStatus, $userType);

        $manager->flush();
    }

    /**
     * One read-only persona per core `admin.<area>.read`, plus the read/update
     * persona (see the class docblock). Roles are created and filled through
     * {@see AdminRoleService} as qa.admin, exactly as the Roles screen does.
     */
    private function seedRestrictedPersonas(ObjectManager $manager, int $qaAdminId, Lookup $activeStatus, Lookup $userType): void
    {
        /** @var list<Permission> $corePermissions */
        $corePermissions = $manager->getRepository(Permission::class)->findBy(['plugin' => null], ['name' => 'ASC']);

        $idsByName = [];
        foreach ($corePermissions as $permission) {
            $idsByName[$permission->getName()] = (int) $permission->getId();
        }
        if (!isset($idsByName[self::ADMIN_ACCESS_PERMISSION])) {
            throw new \RuntimeException('QA baseline cannot seed restricted personas: the core permission admin.access is missing.');
        }
        $accessId = $idsByName[self::ADMIN_ACCESS_PERMISSION];

        $readUpdateIds = [$accessId];
        foreach ($idsByName as $name => $id) {
            if (self::isAreaReadPermission($name)) {
                $role = $this->createRoleWithPermissions($manager, $qaAdminId, self::readPersonaRole($name), 'QA read-only persona for ' . $name, [$accessId, $id]);
                $this->createPersona($manager, self::readPersonaEmail($name), 'QA Read ' . $name, $activeStatus, $userType, [], $role);
                $readUpdateIds[] = $id;
            } elseif (preg_match(self::UPDATE_PERMISSION_PATTERN, $name) === 1) {
                $readUpdateIds[] = $id;
            }
        }

        $role = $this->createRoleWithPermissions($manager, $qaAdminId, self::QA_READ_UPDATE_ROLE, 'QA persona with every core read and update right', $readUpdateIds);
        $this->createPersona($manager, self::QA_READ_UPDATE_EMAIL, 'QA Read Update', $activeStatus, $userType, [], $role);
    }

    /**
     * @param list<int> $permissionIds
     */
    private function createRoleWithPermissions(ObjectManager $manager, int $qaAdminId, string $name, string $description, array $permissionIds): Role
    {
        $this->adminRoleService->createRole($qaAdminId, ['name' => $name, 'description' => $description]);
        $role = $manager->getRepository(Role::class)->findOneBy(['name' => $name]);
        if (!$role instanceof Role) {
            throw new \RuntimeException(sprintf('QA baseline could not create role %s.', $name));
        }
        $this->adminRoleService->updateRolePermissions($qaAdminId, (int) $role->getId(), $permissionIds);

        return $role;
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
