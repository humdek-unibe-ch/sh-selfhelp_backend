<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Role;
use App\Entity\User;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * F10: superuser is a flag; renaming the role keeps full access; name alone does not.
 */
#[Group('security')]
final class SuperuserFlagTest extends QaWebTestCase
{
    public function testRenamingSuperuserRoleKeepsFullAccess(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $role = $em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $role);
        $originalName = $role->getName();
        $roleId = (int) $role->getId();

        $token = $this->loginAsQaAdmin();
        $newName = 'qa_owners_' . substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $this->client->request(
                'PUT',
                '/cms-api/v1/admin/roles/' . $roleId,
                [],
                [],
                [
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                    'CONTENT_TYPE' => 'application/json',
                ],
                (string) json_encode(['name' => $newName], JSON_THROW_ON_ERROR)
            );
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

            $em->clear();
            $renamed = $em->getRepository(Role::class)->find($roleId);
            self::assertInstanceOf(Role::class, $renamed);
            self::assertSame($newName, $renamed->getName());
            self::assertTrue($renamed->isSuperuser());

            // Admin still reaches a guarded admin endpoint after the rename.
            $this->client->request(
                'GET',
                '/cms-api/v1/admin/pages',
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        } finally {
            $em->clear();
            $role = $em->getRepository(Role::class)->find($roleId);
            if ($role instanceof Role) {
                $role->setName($originalName);
                $em->flush();
            }
        }
    }

    public function testRoleNamedAdminWithoutFlagHasNoBypass(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $security = static::getContainer()->get(DataAccessSecurityService::class);
        self::assertInstanceOf(DataAccessSecurityService::class, $security);

        $super = $em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $super);
        $originalName = $super->getName();
        // Free the name "admin" so a flag-less decoy can use it.
        $super->setName('qa_temp_super_' . substr(bin2hex(random_bytes(3)), 0, 6));
        $em->flush();

        $fake = new Role();
        $fake->setName('admin');
        $fake->setDescription('name-only decoy');
        $fake->setIsSuperuser(false);
        $em->persist($fake);

        $user = new User();
        $user->setEmail('qa.fake.admin.' . bin2hex(random_bytes(3)) . '@selfhelp.test');
        $user->setName('Fake Admin');
        $user->setUserName('qa_fake_admin_' . bin2hex(random_bytes(3)));
        $user->setPassword('unused');
        $template = $em->getRepository(User::class)->findOneBy(['email' => \App\DataFixtures\Test\QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $template);
        $user->setUserType($template->getUserType());
        $user->setStatus($template->getStatus());
        $user->addRole($fake);
        $em->persist($user);
        $em->flush();
        $security->invalidateUserPermissions((int) $user->getId());

        try {
            self::assertFalse($security->userHasAdminRole((int) $user->getId()));
        } finally {
            $user->removeRole($fake);
            $em->remove($user);
            $em->remove($fake);
            $em->flush();
            $super = $em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
            if ($super instanceof Role) {
                $super->setName($originalName);
                $em->flush();
            }
        }
    }

    public function testDeletingSuperuserRoleAnswers409(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $role = $em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $role);

        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/roles/' . $role->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAsQaAdmin()]
        );
        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        $body = $this->decode($this->client->getResponse());
        self::assertSame('This role holds full access and cannot be deleted.', $body['error'] ?? null);
    }

    public function testRoleUpdateRejectsIsSuperuserKey(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $role = $em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $role);

        // Include a valid field so the body is not empty; is_superuser must be refused.
        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/roles/' . $role->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAsQaAdmin(),
                'CONTENT_TYPE' => 'application/json',
            ],
            (string) json_encode([
                'description' => $role->getDescription() ?? 'superuser',
                'is_superuser' => false,
            ], JSON_THROW_ON_ERROR)
        );
        $status = $this->client->getResponse()->getStatusCode();
        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $status,
            'Response: ' . (string) $this->client->getResponse()->getContent()
        );
    }
}
