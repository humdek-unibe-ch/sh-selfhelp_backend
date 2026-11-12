<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Group;
use App\Entity\PageAclGroup;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * New pages and the default groups: every group flagged `add_to_new_pages`
 * gets `select` (and nothing else) on a new PUBLIC page; no flagged group means
 * nothing is added and creating the page still works; CMS-surface pages never
 * get the default groups.
 *
 * The seed has one group, `subject`, which carries the flag; the other groups
 * here are custom `qa_` groups.
 */
#[TestGroup('security')]
final class PageDefaultGroupsTest extends QaWebTestCase
{
    private const PAGES_URI = '/cms-api/v1/admin/pages';
    private const GROUPS_URI = '/cms-api/v1/admin/groups';

    public function testNewPublicPageGivesSelectToEveryFlaggedGroupAndToNoOtherGroup(): void
    {
        $admin = $this->loginAsQaAdmin();
        $this->createGroup('qa_default_group_a', true, $admin);
        $this->createGroup('qa_default_group_b', true, $admin);
        $this->createGroup('qa_plain_group', false, $admin);

        $pageId = $this->createPage('qa_default_groups_public', '/qa-default-groups-public', 'public', $admin);

        try {
            $acls = $this->aclsByGroupName($pageId);

            self::assertEqualsCanonicalizing(
                ['subject', 'qa_default_group_a', 'qa_default_group_b'],
                array_keys($acls),
                'Exactly the flagged groups (the seeded subject plus the two flagged ones) are added'
            );
            foreach ($acls as $name => $rights) {
                self::assertSame(
                    ['select' => true, 'insert' => false, 'update' => false, 'delete' => false],
                    $rights,
                    sprintf('Group %s gets read access only', $name)
                );
            }
        } finally {
            $this->jsonRequest('DELETE', self::PAGES_URI . '/' . $pageId, null, $admin);
        }
    }

    public function testCreatingAPageWithoutAnyFlaggedGroupAddsNothingAndDoesNotFail(): void
    {
        $admin = $this->loginAsQaAdmin();
        // Switch the flag off on every group, the seeded subject included.
        foreach ($this->flaggedGroupIds() as $groupId) {
            $this->assertEnvelopeSuccess(
                $this->jsonRequest('PUT', self::GROUPS_URI . '/' . $groupId, ['add_to_new_pages' => false], $admin)
            );
        }
        self::assertSame([], $this->flaggedGroupIds(), 'No group carries the flag any more');

        $pageId = $this->createPage('qa_default_groups_none', '/qa-default-groups-none', 'public', $admin);

        try {
            self::assertSame([], $this->aclsByGroupName($pageId), 'No flagged group means no group ACL row and no error');
        } finally {
            $this->jsonRequest('DELETE', self::PAGES_URI . '/' . $pageId, null, $admin);
        }
    }

    public function testCmsSurfacePageDoesNotGetTheDefaultGroups(): void
    {
        $admin = $this->loginAsQaAdmin();
        $this->createGroup('qa_default_group_cms', true, $admin);

        $pageId = $this->createPage('qa_default_groups_cms', '/cms/qa-default-groups-cms', 'cms', $admin);

        try {
            self::assertSame([], $this->aclsByGroupName($pageId), 'CMS application pages stay admin/editor-only');
        } finally {
            $this->jsonRequest('DELETE', self::PAGES_URI . '/' . $pageId, null, $admin);
        }
    }

    /** Create a custom group through the API and return its id. */
    private function createGroup(string $name, bool $addToNewPages, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::GROUPS_URI, [
                'name' => $name,
                'description' => 'QA default group probe',
                'add_to_new_pages' => $addToNewPages,
            ], $token),
            Response::HTTP_CREATED
        );
        self::assertSame($addToNewPages, $data['add_to_new_pages'] ?? null);

        return self::coerceInt($data['id'] ?? null);
    }

    private function createPage(string $keyword, string $url, string $surface, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::PAGES_URI, [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'openAccess' => true,
                'url' => $url,
                'surface' => $surface,
            ], $token),
            Response::HTTP_CREATED
        );

        return self::coerceInt($data['id'] ?? null);
    }

    /**
     * @return list<int>
     */
    private function flaggedGroupIds(): array
    {
        $ids = [];
        foreach ($this->em()->getRepository(Group::class)->findBy(['addToNewPages' => true]) as $group) {
            $ids[] = (int) $group->getId();
        }

        return $ids;
    }

    /**
     * @return array<string, array{select: bool, insert: bool, update: bool, delete: bool}>
     */
    private function aclsByGroupName(int $pageId): array
    {
        $em = $this->em();
        $em->clear();

        $rows = [];
        foreach ($em->getRepository(PageAclGroup::class)->findBy(['page' => $pageId]) as $acl) {
            $rows[(string) $acl->getGroup()?->getName()] = [
                'select' => (bool) $acl->getAclSelect(),
                'insert' => (bool) $acl->getAclInsert(),
                'update' => (bool) $acl->getAclUpdate(),
                'delete' => (bool) $acl->getAclDelete(),
            ];
        }

        return $rows;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
