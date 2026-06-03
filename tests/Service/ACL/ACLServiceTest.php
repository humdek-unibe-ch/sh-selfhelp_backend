<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\ACL;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Behavioural coverage for {@see ACLService::hasAccess()} — the group-based page
 * ACL gate used by the frontend (plan Phase 7: ACLService).
 *
 * A qa page is granted SELECT-only to the seeded `subject` group; qa.user belongs
 * to it (grant path) while qa.guest does not (deny path). Grants are applied
 * through {@see PageSectionFactory} which also bumps the permissions cache
 * generation so the freshly-granted ACL is observed deterministically.
 */
#[TestGroup('security')]
final class ACLServiceTest extends QaKernelTestCase
{
    private ACLService $acl;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acl = $this->service(ACLService::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->acl,
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testGroupMemberWithSelectGrantHasReadAccess(): void
    {
        $page = $this->pages->createPage('qa_acl_select_page', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        self::assertTrue(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_USER_EMAIL), (int) $page->getId(), 'select'),
            'A subject-group member must have select access to a select-granted page.',
        );
    }

    public function testSelectGrantDoesNotImplyDeleteAccess(): void
    {
        $page = $this->pages->createPage('qa_acl_select_only_page', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        self::assertFalse(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_USER_EMAIL), (int) $page->getId(), 'delete'),
            'Select-only grant must not confer delete access.',
        );
    }

    public function testNonMemberIsDenied(): void
    {
        $page = $this->pages->createPage('qa_acl_denied_page', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        // qa.guest is not in the subject group -> no ACL row applies.
        self::assertFalse(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_GUEST_EMAIL), (int) $page->getId(), 'select'),
            'A non-member must be denied access to a group-granted page.',
        );
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return (int) $user->getId();
    }
}
