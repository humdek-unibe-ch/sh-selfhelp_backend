<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Reading a section in the admin needs the READ grant on its page, on EVERY
 * read. The section payload (content, fields, translations) is cached and is
 * the same for every caller, so the grant must be checked outside that cache:
 * one editor opening a section must never hand its content to a role that only
 * holds the route permission.
 */
#[TestGroup('security')]
final class AdminSectionReadAccessTest extends QaWebTestCase
{
    private const GRANTED_EMAIL = 'qa.secread.granted@selfhelp.test';
    private const DENIED_EMAIL = 'qa.secread.denied@selfhelp.test';
    private const GRANTED_ROLE = 'qa_secread_granted_role';
    private const DENIED_ROLE = 'qa_secread_denied_role';

    private int $pageId;
    private int $sectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $users = new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $dataAccess = new RoleDataAccessFactory($em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class));
        $pages = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );

        // Both roles pass the ROUTE permission; only one may read THIS page.
        $grantedRole = $roles->createRole(self::GRANTED_ROLE);
        $deniedRole = $roles->createRole(self::DENIED_ROLE);
        foreach ([$grantedRole, $deniedRole] as $role) {
            $roles->grantPermission($role, 'admin.page.read');
        }
        $users->createUser(self::GRANTED_EMAIL, 'QA Section Granted', roles: [$grantedRole]);
        $users->createUser(self::DENIED_EMAIL, 'QA Section Denied', roles: [$deniedRole]);

        $page = $pages->createPage('qa_secread_page', false);
        $otherPage = $pages->createPage('qa_secread_other_page', false);
        $section = $pages->createSection('qa_secread_text', 'text');
        $pages->linkSectionToPage($page, $section, 10);

        $dataAccess->grantPageAccess($grantedRole, (int) $page->getId(), DataAccessSecurityService::PERMISSION_READ);
        // The denied role is an editor too: it may read a DIFFERENT page.
        $dataAccess->grantPageAccess($deniedRole, (int) $otherPage->getId(), DataAccessSecurityService::PERMISSION_READ);

        $this->pageId = (int) $page->getId();
        $this->sectionId = (int) $section->getId();
    }

    public function testThePageGrantIsCheckedOnEveryReadWhoeverFilledTheCache(): void
    {
        $granted = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::GRANTED_EMAIL)];
        $denied = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::DENIED_EMAIL)];

        self::assertSame(Response::HTTP_FORBIDDEN, $this->readSection($denied), 'No grant on the page: refused before anything is cached');
        self::assertSame(Response::HTTP_OK, $this->readSection($granted), 'The role with the grant reads the section (this fills the cache)');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->readSection($denied), 'Still refused after another role filled the cache');
        self::assertSame(Response::HTTP_OK, $this->readSection($granted), 'The granted role is not affected by the refusals');
    }

    public function testTheContentOfACachedSectionIsNeverInTheRefusal(): void
    {
        $granted = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::GRANTED_EMAIL)];
        $denied = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::DENIED_EMAIL)];

        self::assertSame(Response::HTTP_OK, $this->readSection($granted));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->readSection($denied));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertNull($body['data'] ?? null, 'A refusal carries no section payload');
    }

    /**
     * @param array<string, string> $headers
     */
    private function readSection(array $headers): int
    {
        $this->client->request('GET', sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $this->pageId, $this->sectionId), [], [], $headers);

        return $this->client->getResponse()->getStatusCode();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
