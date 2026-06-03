<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\AclRepository;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see AclRepository::getUserAcl()} — the
 * `get_user_acl` stored-procedure wrapper behind {@see ACLService} (plan
 * Phase 9: repository integration tests).
 *
 * Each test uses a fresh qa page (a never-before-seen auto-increment id), so the
 * Redis-backed permissions cache cannot leak a previous run's result and the
 * stored-procedure output is what is asserted.
 */
final class AclRepositoryTest extends QaKernelTestCase
{
    private AclRepository $repository;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->service(AclRepository::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testGetUserAclForGrantedPageReturnsSelectFlag(): void
    {
        $user = $this->user(QaBaselineFixture::QA_USER_EMAIL);
        $page = $this->pages->createPage('qa_acl_repo_granted', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $acl = $this->repository->getUserAcl((int) $user->getId(), (int) $page->getId());

        self::assertNotEmpty($acl, 'A group member must receive an ACL row for the granted page.');
        self::assertArrayHasKey('acl_select', $acl[0]);
        self::assertSame(1, $this->coerceInt($acl[0]['acl_select']), 'The granted select flag must be set.');
        self::assertSame(0, $this->coerceInt($acl[0]['acl_insert']), 'Ungranted insert must remain off.');
    }

    public function testGetUserAclDeniesNonMember(): void
    {
        $guest = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $page = $this->pages->createPage('qa_acl_repo_denied', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        self::assertSame(
            [],
            $this->repository->getUserAcl((int) $guest->getId(), (int) $page->getId()),
            'A non-member (qa.guest is not in subject) must get no ACL row for a non-open page.',
        );
    }

    public function testRepeatedReadsAreStable(): void
    {
        $user = $this->user(QaBaselineFixture::QA_USER_EMAIL);
        $page = $this->pages->createPage('qa_acl_repo_stable', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: true, update: false, delete: false);

        self::assertSame(
            $this->repository->getUserAcl((int) $user->getId(), (int) $page->getId()),
            $this->repository->getUserAcl((int) $user->getId(), (int) $page->getId()),
            'Repeated reads (cache hit) must return identical ACL data.',
        );
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return $user;
    }
}
