<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Smoke;

use App\DataFixtures\Test\QaBaselineFixture;
use App\DataFixtures\Test\QaFrontendGoldenFixture;
use App\Entity\Page;
use App\Entity\Permission;
use App\Entity\User;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke proof of the restricted QA personas the least-privilege walk signs in
 * as (F239, persona contract): after `composer test:reset-db` there is one
 * read-only persona per core `admin.<area>.read` plus `qa.readupdate`, each
 * signs in for real, the route rights are exactly the persona's (read allowed,
 * write refused), and the read/update persona's page flags follow its
 * view-only grant (F234).
 *
 * @group smoke
 */
#[Group('smoke')]
final class QaAccessPersonaSmokeTest extends QaWebTestCase
{
    public function testEveryCoreReadPermissionHasAPersonaThatCanSignIn(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        /** @var list<Permission> $corePermissions */
        $corePermissions = $em->getRepository(Permission::class)->findBy(['plugin' => null], ['name' => 'ASC']);

        $readPermissions = [];
        foreach ($corePermissions as $permission) {
            if (QaBaselineFixture::isAreaReadPermission($permission->getName())) {
                $readPermissions[] = $permission->getName();
            }
        }
        self::assertNotEmpty($readPermissions, 'The core must have admin.<area>.read permissions.');

        $emails = array_map(QaBaselineFixture::readPersonaEmail(...), $readPermissions);
        $emails[] = QaBaselineFixture::QA_READ_UPDATE_EMAIL;
        $emails[] = QaBaselineFixture::QA_ROLE_UPDATE_EMAIL;

        foreach ($readPermissions as $readPermission) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::readPersonaEmail($readPermission)]);
            self::assertInstanceOf(User::class, $user, 'Missing persona for ' . $readPermission . '. Run: composer test:reset-db');
            $roleNames = [];
            foreach ($user->getUserRoles() as $role) {
                $roleNames[] = $role->getName();
            }
            self::assertSame([QaBaselineFixture::readPersonaRole($readPermission)], $roleNames, 'Persona for ' . $readPermission . ' must hold exactly its role.');
        }

        foreach ($emails as $email) {
            self::assertNotSame('', $this->loginAs($email), $email . ' must receive an access token.');
        }
    }

    public function testRoleUpdatePersonaHoldsExactlyAccessRoleReadAndRoleUpdate(): void
    {
        $user = $this->service(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => QaBaselineFixture::QA_ROLE_UPDATE_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.roleupdate missing. Run: composer test:reset-db');

        $roleNames = [];
        $permissionNames = [];
        foreach ($user->getUserRoles() as $role) {
            $roleNames[] = $role->getName();
            foreach ($role->getPermissions() as $permission) {
                $permissionNames[] = $permission->getName();
            }
        }
        sort($permissionNames);

        self::assertSame([QaBaselineFixture::QA_ROLE_UPDATE_ROLE], $roleNames);
        self::assertSame(['admin.access', 'admin.role.read', 'admin.role.update'], $permissionNames);
    }

    public function testLanguageReadPersonaMayListButNotCreateLanguages(): void
    {
        $token = $this->loginAs(QaBaselineFixture::readPersonaEmail('admin.language.read'));

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/languages', null, $token));
        self::assertNotEmpty($data, 'The language list must return the seeded languages.');

        $refused = $this->jsonRequest('POST', '/cms-api/v1/admin/languages', ['locale' => 'qa-QA', 'language' => 'qa_language'], $token);
        $this->assertEnvelope403($refused);
        self::assertTrue($refused['logged_in'] ?? null, 'A signed-in persona refused by its rights must stay logged in.');
    }

    public function testReadUpdatePersonaSeesQaFeedbackPageAsReadOnly(): void
    {
        $page = $this->service(EntityManagerInterface::class)
            ->getRepository(Page::class)
            ->findOneBy(['keyword' => QaFrontendGoldenFixture::QA_FORM_PAGE_KEYWORD]);
        self::assertInstanceOf(Page::class, $page, 'qa-feedback page missing. Run: composer test:reset-db');

        $token = $this->loginAs(QaBaselineFixture::QA_READ_UPDATE_EMAIL);
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . (int) $page->getId(), null, $token)
        );

        $rights = self::asArray($data['rights'] ?? null, 'The page response must carry rights.');
        self::assertFalse($rights['can_update'] ?? null, 'View grant only: qa.readupdate must not get can_update although it holds admin.page.update.');
        self::assertFalse($rights['can_delete'] ?? null, 'View grant only: qa.readupdate must not get can_delete.');
    }
}
