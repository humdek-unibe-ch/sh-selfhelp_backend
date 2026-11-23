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
 * A qa page is granted SELECT to the seeded `subject` group; qa.user belongs
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
        $this->pages->grantGroupAcl($page, $this->subjectGroup());

        self::assertTrue(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_USER_EMAIL), (int) $page->getId()),
            'A subject-group member must have select access to a select-granted page.',
        );
    }

    public function testNonMemberIsDenied(): void
    {
        $page = $this->pages->createPage('qa_acl_denied_page', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup());

        // qa.guest is not in the subject group -> no ACL row applies.
        self::assertFalse(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_GUEST_EMAIL), (int) $page->getId()),
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
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_ADMIN_EMAIL), (int) $page->getId()),
            'Admin-role users must have frontend page access regardless of page_acl_groups rows.',
        );
        self::assertFalse(
            $this->acl->hasAccess($this->userId(QaBaselineFixture::QA_USER_EMAIL), (int) $page->getId()),
            'Non-admin users must still be denied when no ACL row exists.',
        );
    }

    public function testAdminRoleIsListedEveryPageAndEveryoneElseOnlyWhatTheirGroupsShow(): void
    {
        // F89: opening a page and being listed it are one question. A closed
        // page that no group sees is listed for the admin role (which has no
        // group on a fresh install and may open it by address) with select,
        // and for nobody else.
        $closed = $this->pages->createPage('qa_acl_admin_list_closed', openAccess: false);
        $shared = $this->pages->createPage('qa_acl_admin_list_shared', openAccess: false);
        $this->pages->grantGroupAcl($shared, $this->subjectGroup());

        $adminRows = $this->acl->getAllUserAcls($this->userId(QaBaselineFixture::QA_ADMIN_EMAIL));
        $closedRow = $this->rowForPage($adminRows, (int) $closed->getId());
        self::assertNotNull($closedRow, 'The admin role is listed a page no group sees');
        self::assertEquals(1, $closedRow['acl_select'], 'acl_select');
        self::assertArrayNotHasKey('acl_insert', $closedRow);
        self::assertSame('qa_acl_admin_list_closed', $closedRow['keyword']);
        self::assertNotNull($this->rowForPage($adminRows, (int) $shared->getId()));

        $memberRows = $this->acl->getAllUserAcls($this->userId(QaBaselineFixture::QA_USER_EMAIL));
        self::assertNull($this->rowForPage($memberRows, (int) $closed->getId()), 'A group member is not listed a page no group sees');
        self::assertNotNull($this->rowForPage($memberRows, (int) $shared->getId()));

        $guestRows = $this->acl->getAllUserAcls(null);
        self::assertNull($this->rowForPage($guestRows, (int) $closed->getId()));
        self::assertNull($this->rowForPage($guestRows, (int) $shared->getId()), 'An anonymous visitor is not listed a group page');
    }

    public function testAddingAGroupAclDoesNotBumpThePermissionsCategoryGeneration(): void
    {
        // F27: one new ACL row used to bump the generation of the whole permissions category,
        // which is part of every key of it. It now clears the group scope, the page scope and
        // the category's lists. The generation is read through the key it builds: same key,
        // same generation.
        $cache = $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_PERMISSIONS);
        $keyOf = new \ReflectionMethod($cache, 'getCacheKey');
        // The factory's page creation clears caches itself; take the baseline after it.
        $page = $this->pages->createPage('qa_acl_f27_page', openAccess: false);
        $before = $keyOf->invoke($cache, 'item', 'qa_f27_probe');

        $this->acl->addGroupAcl($page, $this->subjectGroup(), $this->em, true);
        $this->em->flush();

        self::assertSame($before, $keyOf->invoke($cache, 'item', 'qa_f27_probe'), 'The category generation did not move.');
    }

    public function testRemovingGroupAclIsSeenOnNextHasAccessWithoutManualCacheClear(): void
    {
        // F01: hasAccess must not cache the boolean; invalidating the ACL rows'
        // scopes (as addGroupAcl / AdminGroupService do) is enough.
        $page = $this->pages->createPage('qa_acl_f01_revoke', openAccess: false);
        $this->pages->grantGroupAcl($page, $this->subjectGroup());
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $pageId = (int) $page->getId();

        self::assertTrue($this->acl->hasAccess($userId, $pageId));

        $acl = $this->em->getRepository(\App\Entity\PageAclGroup::class)->findOneBy([
            'page' => $page,
            'group' => $this->subjectGroup(),
        ]);
        self::assertInstanceOf(\App\Entity\PageAclGroup::class, $acl);
        $this->em->remove($acl);
        $this->em->flush();
        $this->service(CacheService::class)->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        $this->service(CacheService::class)->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
        $this->service(CacheService::class)->invalidateEntityScope(
            CacheService::ENTITY_SCOPE_GROUP,
            (int) $this->subjectGroup()->getId(),
        );

        self::assertFalse(
            $this->acl->hasAccess($userId, $pageId),
            'After the group loses the page, hasAccess must answer false without a hand cache clear of a boolean key.',
        );
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
