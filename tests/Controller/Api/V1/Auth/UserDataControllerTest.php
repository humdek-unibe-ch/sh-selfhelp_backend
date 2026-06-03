<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Coverage for GET /auth/user-data ({@see \App\Controller\Api\V1\Auth\UserDataController}).
 *
 * This is the permission/ACL contract the frontend `IUserData` type consumes
 * (roles, permissions, groups, acl_version) plus the profile fields. The
 * route is intentionally permission-less (JWT-authenticated, returns the
 * caller's own data) so the tests assert the full envelope + every required
 * field for a privileged (qa.admin) and an unprivileged (qa.guest) persona,
 * plus the unauthenticated 401 path.
 */
#[Group('security')]
final class UserDataControllerTest extends QaWebTestCase
{
    private const URI = '/cms-api/v1/auth/user-data';

    /** @var list<string> */
    private const REQUIRED_KEYS = [
        'id', 'email', 'name', 'user_name', 'blocked',
        'acl_version', 'language', 'timezone', 'roles', 'permissions', 'groups',
    ];

    public function testAdminUserDataExposesFullPermissionContract(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::URI, null, $this->loginAsQaAdmin())
        );

        foreach (self::REQUIRED_KEYS as $key) {
            self::assertArrayHasKey($key, $data, "user-data must expose '{$key}'");
        }

        self::assertSame(QaBaselineFixture::QA_ADMIN_EMAIL, $data['email']);
        self::assertFalse($data['blocked'], 'qa.admin must not be blocked.');
        self::assertIsString($data['acl_version']);
        self::assertNotSame('', $data['acl_version'], 'acl_version must be a non-empty token.');

        // Language is always resolved (own or CMS default fallback).
        self::assertIsArray($data['language']);
        foreach (['id', 'locale', 'name'] as $key) {
            self::assertArrayHasKey($key, $data['language']);
        }

        // qa.admin holds the production admin role + admin group + real perms.
        self::assertContains('admin', $this->names($data['roles']), 'qa.admin must carry the admin role.');
        self::assertContains('admin', $this->names($data['groups']), 'qa.admin must be in the admin group.');
        self::assertIsArray($data['permissions']);
        self::assertNotEmpty($data['permissions'], 'qa.admin must have a non-empty permission set.');
        self::assertContainsOnlyString($data['permissions']);
    }

    public function testGuestUserDataHasNoRolesGroupsOrPermissions(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::URI, null, $this->loginAsQaGuest())
        );

        self::assertSame(QaBaselineFixture::QA_GUEST_EMAIL, $data['email']);
        self::assertSame([], $data['roles'], 'qa.guest must have no roles.');
        self::assertSame([], $data['groups'], 'qa.guest must have no groups.');
        self::assertSame([], $data['permissions'], 'qa.guest must have no permissions.');
        // The contract fields still resolve for an unprivileged user.
        self::assertIsArray($data['language']);
        self::assertArrayHasKey('timezone', $data);
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $envelope = $this->jsonRequest('GET', self::URI, null, null);
        $this->assertEnvelope401($envelope);
    }

    /**
     * @param mixed $entries
     * @return list<string>
     */
    private function names(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }
        $names = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }
}
