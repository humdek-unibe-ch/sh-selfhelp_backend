<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The access stamp (live-sync contract C5) and the groups and roles it covers.
 *
 * The stamp is a short fingerprint of everything that decides what a signed-in
 * caller may see and do: the user's own `acl_version`, and the `acl_version` of
 * each group and role the user belongs to. Any change to the user's groups or
 * roles, to a group's page ACL, or to a role's permissions or grants moves one of
 * these values, so the stamp changes. Every API response carries it
 * (`X-Acl-Stamp`), so a client whose open screen missed a push notices on its
 * next reply and refreshes its user data.
 *
 * Format: the first 16 hexadecimal characters of SHA-256 over the text
 * `<user acl_version>\n<group pairs>\n<role pairs>`, where the pairs are
 * `<id>:<acl_version>` sorted by id and joined with `,`; a missing version is
 * the empty string. The stamp holds nothing a client could read back.
 *
 * It is per caller and never cached: one small query per call, no state kept.
 */
final class AclStampService
{
    public const HEADER = 'X-Acl-Stamp';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The access stamp of a user.
     */
    public function stampFor(User $user): string
    {
        $memberships = $this->memberships((int) $user->getId());

        return self::compose($memberships['user'], $memberships['groups'], $memberships['roles']);
    }

    /**
     * The stamp of the given versions (pure; the tests use it as the contract).
     *
     * @param array<int, string|null> $groupVersions group id => acl_version
     * @param array<int, string|null> $roleVersions role id => acl_version
     */
    public static function compose(?string $userVersion, array $groupVersions, array $roleVersions): string
    {
        return substr(hash('sha256', ($userVersion ?? '') . "\n" . self::pairs($groupVersions) . "\n" . self::pairs($roleVersions)), 0, 16);
    }

    /**
     * @param array<int, string|null> $versions id => acl_version
     */
    private static function pairs(array $versions): string
    {
        ksort($versions);
        $parts = [];
        foreach ($versions as $id => $version) {
            $parts[] = $id . ':' . ($version ?? '');
        }

        return implode(',', $parts);
    }

    /**
     * The user's own version and the groups and roles of the user with their versions, in one query.
     * Read from the database, not from the loaded entity: the stamp must show what is stored.
     *
     * @return array{user: string|null, groups: array<int, string|null>, roles: array<int, string|null>} id => acl_version
     */
    public function memberships(int $userId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT \'u\' AS kind, u.id AS id, u.acl_version AS version FROM users u WHERE u.id = :user'
            . ' UNION ALL'
            . ' SELECT \'g\' AS kind, g.id AS id, g.acl_version AS version'
            . ' FROM rel_groups_users ug INNER JOIN `groups` g ON g.id = ug.id_groups WHERE ug.id_users = :user'
            . ' UNION ALL'
            . ' SELECT \'r\' AS kind, r.id AS id, r.acl_version AS version'
            . ' FROM rel_roles_users ru INNER JOIN roles r ON r.id = ru.id_roles WHERE ru.id_users = :user',
            ['user' => $userId],
            ['user' => ParameterType::INTEGER],
        );

        $userVersion = null;
        $groups = [];
        $roles = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['id'] ?? null)) {
                continue;
            }
            $version = is_string($row['version'] ?? null) ? $row['version'] : null;
            if (($row['kind'] ?? null) === 'u') {
                $userVersion = $version;
            } elseif (($row['kind'] ?? null) === 'g') {
                $groups[(int) $row['id']] = $version;
            } else {
                $roles[(int) $row['id']] = $version;
            }
        }

        return ['user' => $userVersion, 'groups' => $groups, 'roles' => $roles];
    }
}
