<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Admin;

use App\Entity\Group;
use App\Repository\AssetFolderGroupRepository;
use App\Service\CMS\Admin\AssetFolderAclService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Service-level coverage for the non-admin-role enforcement core of asset folder
 * ACLs: the per-group grant storage and the union/strongest-grant reduction the
 * enforcement layer applies across a caller's groups.
 *
 * The admin-role bypass (an admin-role user is never denied) is proven
 * end-to-end in {@see \App\Tests\Controller\Api\V1\Admin\AdminGroupAssetAclBehaviorTest}.
 *
 * Uses `qa_`-prefixed folders and clears the seeded groups' grants in teardown.
 */
#[TestGroup('security')]
final class AssetFolderAclServiceTest extends QaKernelTestCase
{
    private const FOLDER = 'qa_svc_acl_folder';
    private const SEED_FOLDER = 'qa_svc_seed_folder';

    private AssetFolderAclService $service;
    private AssetFolderGroupRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(AssetFolderAclService::class);
        $this->repo = $this->service(AssetFolderGroupRepository::class);
    }

    protected function tearDown(): void
    {
        // Remove every row for the qa_ test folders (covers the admin row the
        // default-seed adds, which the per-group clear below would miss).
        foreach ([self::FOLDER, self::SEED_FOLDER] as $folder) {
            foreach ($this->repo->findByFolder($folder) as $row) {
                $this->em->remove($row);
            }
        }
        $this->em->flush();

        foreach (['subject', 'therapist'] as $name) {
            $group = $this->em->getRepository(Group::class)->findOneBy(['name' => $name]);
            if ($group instanceof Group) {
                $this->service->updateGroupAssetAcls((int) $group->getId(), []);
            }
        }

        parent::tearDown();
    }

    public function testUpdateAndReadRoundTripsAGroupsFolderGrants(): void
    {
        $groupId = $this->groupId('subject');

        $result = $this->service->updateGroupAssetAcls($groupId, [
            ['folder' => self::FOLDER, 'access_level' => 'read'],
        ]);

        self::assertSame($groupId, $result['id_groups']);
        self::assertCount(1, $result['acls']);
        self::assertSame(self::FOLDER, $result['acls'][0]['folder']);
        self::assertSame('read', $result['acls'][0]['access_level']);

        // A fresh read returns the same persisted grant.
        $read = $this->service->getGroupAssetAcls($groupId);
        self::assertSame($result['acls'], $read['acls']);
    }

    public function testEnforcementUnionTakesTheStrongestGrantAcrossGroups(): void
    {
        $subjectId = $this->groupId('subject');
        $therapistId = $this->groupId('therapist');

        // subject: read, therapist: manage on the same folder.
        $this->service->updateGroupAssetAcls($subjectId, [
            ['folder' => self::FOLDER, 'access_level' => 'read'],
        ]);
        $this->service->updateGroupAssetAcls($therapistId, [
            ['folder' => self::FOLDER, 'access_level' => 'manage'],
        ]);

        // A caller in only `subject` gets read.
        $subjectLevels = $this->repo->findAccessLevelsForGroups(self::FOLDER, [$subjectId]);
        self::assertSame(['read'], $subjectLevels);

        // A caller in BOTH groups: the enforcement layer reduces to the
        // strongest grant (manage) — the union `manage` is present.
        $bothLevels = $this->repo->findAccessLevelsForGroups(self::FOLDER, [$subjectId, $therapistId]);
        sort($bothLevels);
        self::assertSame(['manage', 'read'], $bothLevels);
        self::assertContains('manage', $bothLevels, 'A user in any manage-granting group must reach manage');

        // A caller in neither group gets nothing (denied).
        $noneLevels = $this->repo->findAccessLevelsForGroups(self::FOLDER, []);
        self::assertSame([], $noneLevels);
    }

    public function testClearingAGroupRemovesItsFolderGrants(): void
    {
        $subjectId = $this->groupId('subject');

        $this->service->updateGroupAssetAcls($subjectId, [
            ['folder' => self::FOLDER, 'access_level' => 'read'],
        ]);
        self::assertContains(self::FOLDER, $this->repo->findFoldersGrantedToGroups([$subjectId]));

        // Clearing the group removes its grant. Closed-by-default: with no grant
        // remaining the folder is now visible to admins only (no group sees it).
        $this->service->updateGroupAssetAcls($subjectId, []);
        self::assertNotContains(self::FOLDER, $this->repo->findFoldersGrantedToGroups([$subjectId]));
    }

    public function testClosedByDefaultVisibleFolderAllowListExcludesUngrantedFolders(): void
    {
        $subjectId = $this->groupId('subject');
        $therapistId = $this->groupId('therapist');

        // Grant subject (only) on the folder.
        $this->service->updateGroupAssetAcls($subjectId, [
            ['folder' => self::FOLDER, 'access_level' => 'read'],
        ]);

        // A subject member's allow-list includes the folder.
        self::assertContains(self::FOLDER, $this->repo->findFoldersGrantedToGroups([$subjectId]));

        // A therapist-only member's allow-list does NOT include it (closed by
        // default: no grant for your group => not visible, even though the folder
        // exists and has rows for another group).
        self::assertNotContains(self::FOLDER, $this->repo->findFoldersGrantedToGroups([$therapistId]));

        // A user in no groups sees nothing.
        self::assertSame([], $this->repo->findFoldersGrantedToGroups([]));
    }

    public function testSeedDefaultFolderAclsSeedsAdminManageAndSubjectTherapistReadForANewFolder(): void
    {
        // The folder starts with no rows (guaranteed by teardown).
        self::assertCount(0, $this->repo->findByFolder(self::SEED_FOLDER));

        $this->service->seedDefaultFolderAclsIfNew(self::SEED_FOLDER);

        $byGroup = [];
        foreach ($this->repo->findByFolder(self::SEED_FOLDER) as $row) {
            $byGroup[(string) $row->getGroup()->getName()] = $row->getAccessLevel();
        }

        self::assertSame('manage', $byGroup['admin'] ?? null, 'admin group must get manage');
        self::assertSame('read', $byGroup['subject'] ?? null, 'subject group must get read');
        self::assertSame('read', $byGroup['therapist'] ?? null, 'therapist group must get read');
    }

    public function testSeedDefaultFolderAclsIsANoOpWhenTheFolderAlreadyHasRows(): void
    {
        // Author-configured: subject already holds manage on the folder.
        $this->service->updateGroupAssetAcls($this->groupId('subject'), [
            ['folder' => self::SEED_FOLDER, 'access_level' => 'manage'],
        ]);

        $this->service->seedDefaultFolderAclsIfNew(self::SEED_FOLDER);

        // The existing single row is preserved; no admin/therapist defaults added.
        $rows = $this->repo->findByFolder(self::SEED_FOLDER);
        self::assertCount(1, $rows, 'seeding must not touch a folder that already has rows');
        self::assertSame('subject', (string) $rows[0]->getGroup()->getName());
        self::assertSame('manage', $rows[0]->getAccessLevel());
    }

    private function groupId(string $name): int
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Group::class, $group, sprintf('Seeded "%s" group is required for this test', $name));

        return (int) $group->getId();
    }
}
