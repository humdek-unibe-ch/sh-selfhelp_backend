<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Group;
use App\Entity\PageType;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Who is listed which pages (F89): "may this caller see the page?" has one
 * answer. Groups decide for everyone, and the admin role sees every page: it
 * may open any page by its address, so the page list (which the menus and the
 * search are built from) does not hide one from it. A fresh install has no
 * admin group, so before this the admin account was listed only open pages.
 */
#[TestGroup('security')]
final class PagesListAdminRoleTest extends QaWebTestCase
{
    public function testTheAdminRoleIsListedEveryPageAndEveryoneElseOnlyWhatTheirGroupsShow(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $factory = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );

        $closed = $factory->createPage('qa_pages_list_closed', openAccess: false);
        $shared = $factory->createPage('qa_pages_list_shared', openAccess: false);
        $open = $factory->createPage('qa_pages_list_open', openAccess: true);

        // The page lists carry content pages only.
        $experiment = $em->getRepository(PageType::class)->findOneBy(['name' => 'experiment']);
        self::assertInstanceOf(PageType::class, $experiment);
        foreach ([$closed, $shared, $open] as $page) {
            $page->setPageType($experiment);
        }
        $em->flush();

        $subject = $em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $factory->grantGroupAcl($shared, $subject, select: true, insert: false, update: false, delete: false);

        // The admin account has the admin role and no group, and sees all three.
        $admin = $this->listedKeywords($this->loginAsQaAdmin());
        self::assertContains('qa_pages_list_closed', $admin, 'The admin role is listed a page no group sees');
        self::assertContains('qa_pages_list_shared', $admin);
        self::assertContains('qa_pages_list_open', $admin);

        // A member of the group sees the shared and the open page, not the closed one.
        $member = $this->listedKeywords($this->loginAsQaUser());
        self::assertNotContains('qa_pages_list_closed', $member);
        self::assertContains('qa_pages_list_shared', $member);
        self::assertContains('qa_pages_list_open', $member);

        // An anonymous visitor sees the open page only.
        $guest = $this->listedKeywords(null);
        self::assertNotContains('qa_pages_list_closed', $guest);
        self::assertNotContains('qa_pages_list_shared', $guest);
        self::assertContains('qa_pages_list_open', $guest);
    }

    /**
     * Every keyword in the page tree the caller is sent.
     *
     * @return list<string>
     */
    private function listedKeywords(?string $token): array
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/pages', null, $token));

        $keywords = [];
        $walk = function (mixed $nodes) use (&$walk, &$keywords): void {
            if (!is_array($nodes)) {
                return;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (is_string($node['keyword'] ?? null)) {
                    $keywords[] = $node['keyword'];
                }
                $walk($node['children'] ?? null);
            }
        };
        $walk($data);

        return $keywords;
    }
}
