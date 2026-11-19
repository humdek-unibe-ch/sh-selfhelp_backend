<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Service\JSON\JsonSchemaValidationService;
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
        'receives_notifications', 'receives_emails',
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

        // Communication preferences (issue #29) are part of the IUserData contract.
        self::assertIsBool($data['receives_notifications']);
        self::assertIsBool($data['receives_emails']);

        // Language is always resolved (own or CMS default fallback).
        self::assertIsArray($data['language']);
        foreach (['id', 'locale', 'name'] as $key) {
            self::assertArrayHasKey($key, $data['language']);
        }

        // qa.admin holds the production admin role + real perms; admin rights
        // come from the role, there is no admin group.
        self::assertContains('admin', $this->names($data['roles']), 'qa.admin must carry the admin role.');
        // C4 (rule 4.11, F10): every role item carries `is_superuser`, and the
        // superuser role is recognised by the flag, not by its name.
        $superuserRoles = 0;
        foreach (self::asArray($data['roles']) as $role) {
            $role = self::asArray($role);
            self::assertArrayHasKey('is_superuser', $role, 'user-data role items must carry is_superuser.');
            self::assertIsBool($role['is_superuser']);
            if ($role['is_superuser'] === true) {
                ++$superuserRoles;
            }
        }
        self::assertSame(1, $superuserRoles, 'qa.admin must carry exactly one superuser role.');

        // The whole response passes its schema (response validation is off in
        // the test env, so check it here).
        /** @var JsonSchemaValidationService $schemaValidator */
        $schemaValidator = self::getContainer()->get(JsonSchemaValidationService::class);
        $body = json_decode((string) $this->client->getResponse()->getContent(), false);
        self::assertIsObject($body);
        self::assertSame([], $schemaValidator->validate($body, 'responses/auth/user_data'));
        self::assertIsArray($data['groups']);
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
