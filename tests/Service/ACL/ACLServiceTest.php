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
 *
 * Admin-role users always pass `hasAccess` (documented "admins always have
 * access" contract used by page import / CMS-in-CMS templates).
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

    public function testAdminRoleHasAccessWithoutGroupAclRow(): void
    {
        // Create a closed page with NO group ACL grants — only open_access=false.
        // Admin-role users must still pass hasAccess so imported template pages
        // are visible even if the admin-group ACL row is missing or ACL caches
        // are stale.
        $page = $this->pages->createPage('qa_acl_admin_role_bypass_page', openAccess: false);

        self::assertTrue(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_ADMIN_EMAIL), (int) $page->getId(), 'select'),
            'Admin-role users must have frontend page access regardless of page_acl_groups rows.',
        );
        self::assertTrue(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_ADMIN_EMAIL), (int) $page->getId(), 'update'),
            'Admin-role users must have full CRUD access on frontend ACL checks.',
        );
        self::assertFalse(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_USER_EMAIL), (int) $page->getId(), 'select'),
            'Non-admin users must still be denied when no ACL row exists.',
        );
    }

    public function testAdminRoleIsListedEveryPageAndEveryoneElseOnlyWhatTheirGroupsShow(): void
    {
        // F89: opening a page and being listed it are one question. A closed
        // page that no group sees is listed for the admin role (which has no
        // group on a fresh install and may open it by address) with full
        // rights, and for nobody else.
        $closed = $this->pages->createPage('qa_acl_admin_list_closed', openAccess: false);
        $shared = $this->pages->createPage('qa_acl_admin_list_shared', openAccess: false);
        $this->pages->grantGroupAcl($shared, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $adminRows = $this->acl->getAllUserAcls($this->userId(QaBaselineFixture::QA_ADMIN_EMAIL));
        $closedRow = $this->rowForPage($adminRows, (int) $closed->getId());
        self::assertNotNull($closedRow, 'The admin role is listed a page no group sees');
        foreach (['acl_select', 'acl_insert', 'acl_update', 'acl_delete'] as $bit) {
            self::assertEquals(1, $closedRow[$bit], $bit);
        }
        self::assertSame('qa_acl_admin_list_closed', $closedRow['keyword']);
        self::assertNotNull($this->rowForPage($adminRows, (int) $shared->getId()));

        $memberRows = $this->acl->getAllUserAcls($this->userId(QaBaselineFixture::QA_USER_EMAIL));
        self::assertNull($this->rowForPage($memberRows, (int) $closed->getId()), 'A group member is not listed a page no group sees');
        self::assertNotNull($this->rowForPage($memberRows, (int) $shared->getId()));

        $guestRows = $this->acl->getAllUserAcls(null);
        self::assertNull($this->rowForPage($guestRows, (int) $closed->getId()));
        self::assertNull($this->rowForPage($guestRows, (int) $shared->getId()), 'An anonymous visitor is not listed a group page');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowForPage(array $rows, int $pageId): ?array
    {
        foreach ($rows as $row) {
            if (is_numeric($row['id_pages']) && (int) $row['id_pages'] === $pageId) {
                return $row;
            }
        }

        return null;
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
