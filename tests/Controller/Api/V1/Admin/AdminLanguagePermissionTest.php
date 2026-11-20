<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Permission matrix for the admin languages API (plan §29).
 *
 * The read route asserts the full admin-only matrix (qa.admin → 200, non-admins
 * → 403, anonymous → 401). The create/update/delete routes assert only the
 * negative half (with qa_-prefixed bodies and a non-existent id) so the matrix
 * test never mutates a real language. The QA baseline grants the language
 * permissions only to the admin role, so non-admins are rejected by the
 * permission gate before any controller logic runs.
 *
 * P1-A5 (role-scope): a caller with only `admin.language.read` may list but not
 * create; adding `admin.language.update` opens create.
 */
#[Group('security')]
final class AdminLanguagePermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const READ_ONLY_EMAIL = 'qa.lang.read@selfhelp.test';
    private const READ_WRITE_EMAIL = 'qa.lang.write@selfhelp.test';

    public function testLanguagesListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/languages');
    }

    public function testLanguageCreateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/languages', [
            'locale' => 'qa-Xa',
            'language' => 'qa_perm_probe_should_not_create',
            'csv_separator' => ',',
        ]);
    }

    public function testLanguageUpdateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('PUT', '/cms-api/v1/admin/languages/2147483600', [
            'locale' => 'qa-Xb',
            'language' => 'qa_perm_probe_should_not_update',
            'csv_separator' => ',',
        ]);
    }

    public function testLanguageDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/languages/2147483600');
    }

    public function testLanguageReadAllowsGetButNotPost(): void
    {
        $this->seedScopedLanguageUsers();
        $token = $this->loginAs(self::READ_ONLY_EMAIL);

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/languages', null, $token)
        );
        $this->assertEnvelope403($this->jsonRequest('POST', '/cms-api/v1/admin/languages', [
            'locale' => 'qa-Xr',
            'language' => 'qa_lang_read_should_not_create',
            'csv_separator' => ',',
        ], $token));
    }

    public function testLanguageUpdateAllowsPost(): void
    {
        $this->seedScopedLanguageUsers();
        $token = $this->loginAs(self::READ_WRITE_EMAIL);

        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/languages', [
                'locale' => 'qa-Xw',
                'language' => 'qa_lang_write_ok',
                'csv_separator' => ',',
            ], $token)
        );
        self::assertSame('qa-Xw', $created['locale'] ?? null);
    }

    private function seedScopedLanguageUsers(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($em);
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $readRole = $roles->createRole('qa_lang_read_role');
        $roles->grantPermission($readRole, 'admin.access');
        $roles->grantPermission($readRole, 'admin.language.read');
        $users->createUser(self::READ_ONLY_EMAIL, 'QA Lang Read', roles: [$readRole]);

        $writeRole = $roles->createRole('qa_lang_write_role');
        $roles->grantPermission($writeRole, 'admin.access');
        $roles->grantPermission($writeRole, 'admin.language.read');
        $roles->grantPermission($writeRole, 'admin.language.update');
        $users->createUser(self::READ_WRITE_EMAIL, 'QA Lang Write', roles: [$writeRole]);
    }
}
