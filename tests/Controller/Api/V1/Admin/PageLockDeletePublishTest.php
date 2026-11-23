<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\CMS\Admin\PageEditLockService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Live sync follow-ups F259: delete and publish callers respect another's page lock.
 */
#[Group('security')]
final class PageLockDeletePublishTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/admin/pages/';

    private EntityManagerInterface $em;
    private string $tokenEditor;
    private string $tokenDelete;
    private string $tokenPublish;
    private int $pageId;
    private int $roleEditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        $editorRole = $roles->createRole('qa_lock_dp_editor');
        foreach ([
            'admin.access',
            'admin.page.read',
            'admin.page.create',
            'admin.page.update',
            'admin.page_version.publish',
            'admin.page_version.delete',
        ] as $permission) {
            $roles->grantPermission($editorRole, $permission);
        }
        $users->createUser('qa.lock.dp.editor@selfhelp.test', 'QA Lock DP Editor', roles: [$editorRole]);
        $this->roleEditor = (int) $editorRole->getId();

        $this->tokenEditor = $this->loginAs('qa.lock.dp.editor@selfhelp.test');
        $this->pageId = $this->createPage('qa_lock_dp_page', $this->tokenEditor);

        $deleteRole = $this->em->getRepository(Role::class)->findOneBy(['name' => QaBaselineFixture::QA_PAGE_DELETE_ROLE]);
        $publishRole = $this->em->getRepository(Role::class)->findOneBy(['name' => QaBaselineFixture::QA_PAGE_PUBLISH_ROLE]);
        self::assertInstanceOf(Role::class, $deleteRole);
        self::assertInstanceOf(Role::class, $publishRole);
        $this->grantOnPage(
            (int) $deleteRole->getId(),
            $this->pageId,
            DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_DELETE,
        );
        $this->grantOnPage(
            (int) $publishRole->getId(),
            $this->pageId,
            DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE,
        );

        $this->tokenDelete = $this->loginAs(QaBaselineFixture::QA_PAGE_DELETE_EMAIL);
        $this->tokenPublish = $this->loginAs(QaBaselineFixture::QA_PAGE_PUBLISH_EMAIL);
    }

    public function testDeleteIsRefusedWhileAnotherEditorHoldsTheLockAndSucceedsWhenFree(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenEditor));

        $locked = $this->jsonRequest('DELETE', self::BASE . $this->pageId, null, $this->tokenDelete);
        $this->assertLocked($locked, 'QA Lock DP Editor');

        $this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenEditor));
        self::assertFalse($this->assertEnvelopeSuccess($this->lock('GET', $this->tokenEditor))['held']);

        $deleted = $this->jsonRequest('DELETE', self::BASE . $this->pageId, null, $this->tokenDelete);
        self::assertSame(Response::HTTP_OK, $deleted['status'] ?? null, json_encode($deleted) ?: 'delete failed');

        // Page is gone, so the lease cannot outlive it (GET is 403 or 404).
        $asEditor = $this->jsonRequest('GET', self::BASE . $this->pageId . '/lock', null, $this->tokenEditor);
        self::assertContains($asEditor['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
    }

    public function testPublishPathsAreRefusedWhileLockedAndSucceedWhenFreeWithoutRaisingDraftVersion(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenEditor));
        $versionBefore = $this->versionOf($this->pageId);

        $this->assertLocked(
            $this->jsonRequest('POST', self::BASE . $this->pageId . '/versions/publish', null, $this->tokenPublish),
            'QA Lock DP Editor',
        );
        self::assertSame($versionBefore, $this->versionOf($this->pageId));

        $this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenEditor));
        $published = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . $this->pageId . '/versions/publish', null, $this->tokenPublish),
            Response::HTTP_CREATED,
        );
        self::assertSame($versionBefore, $this->versionOf($this->pageId), 'A successful publish by a non-editor does not raise draft_version.');

        $versionId = self::asInt($published['version_id'] ?? null);
        self::assertGreaterThan(0, $versionId);

        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenEditor));
        $this->assertLocked(
            $this->jsonRequest('POST', self::BASE . $this->pageId . '/versions/' . $versionId . '/publish', null, $this->tokenPublish),
            'QA Lock DP Editor',
        );
        $this->assertLocked(
            $this->jsonRequest('POST', self::BASE . $this->pageId . '/versions/unpublish', null, $this->tokenPublish),
            'QA Lock DP Editor',
        );
        self::assertSame($versionBefore, $this->versionOf($this->pageId));

        $this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenEditor));
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . $this->pageId . '/versions/unpublish', null, $this->tokenPublish),
        );
        self::assertSame($versionBefore, $this->versionOf($this->pageId));
    }

    public function testCallersWithoutTheActionRightNeverLearnTheHolder(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenEditor));

        $reader = $this->loginAs(QaBaselineFixture::readPersonaEmail('admin.page.read'));
        foreach ([
            ['DELETE', self::BASE . $this->pageId],
            ['POST', self::BASE . $this->pageId . '/versions/publish'],
            ['POST', self::BASE . $this->pageId . '/versions/unpublish'],
        ] as [$method, $uri]) {
            $refused = $this->jsonRequest($method, $uri, null, $reader);
            self::assertContains($refused['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
            self::assertNotSame(PageEditLockService::ERROR_LOCKED, $refused['error'] ?? null);
        }

        // Route right without a grant on this page: another page the delete persona was never granted.
        $otherPageId = $this->createPage('qa_lock_dp_nogrant_page', $this->tokenEditor);
        $refused = $this->jsonRequest('DELETE', self::BASE . $otherPageId, null, $this->tokenDelete);
        self::assertContains($refused['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
        self::assertNotSame(PageEditLockService::ERROR_LOCKED, $refused['error'] ?? null);

        $guest = $this->jsonRequest('DELETE', self::BASE . $this->pageId, null, null);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $guest['status'] ?? null);
        self::assertNotSame(PageEditLockService::ERROR_LOCKED, $guest['error'] ?? null);

        self::assertTrue($this->assertEnvelopeSuccess($this->lock('GET', $this->tokenEditor))['mine']);
        unset($this->roleEditor);
    }

    /**
     * @return array<string, mixed>
     */
    private function lock(string $method, string $token, string $suffix = ''): array
    {
        return $this->jsonRequest($method, self::BASE . $this->pageId . '/lock' . $suffix, null, $token);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertLocked(array $envelope, string $holder): void
    {
        self::assertSame(Response::HTTP_CONFLICT, $envelope['status'] ?? null, json_encode($envelope) ?: 'not conflict');
        self::assertSame(PageEditLockService::ERROR_LOCKED, $envelope['error'] ?? null);
        $data = self::asArray($envelope['data'] ?? null);
        self::assertTrue($data['held'] ?? null);
        self::assertFalse($data['mine'] ?? null);
        $holderData = self::asArray($data['holder'] ?? null);
        self::assertSame($holder, $holderData['name'] ?? null);
        self::assertSame($this->pageId, $data['page_id'] ?? null);
    }

    private function versionOf(int $pageId): int
    {
        return self::asInt($this->em->getConnection()->fetchOne('SELECT draft_version FROM pages WHERE id = ?', [$pageId]));
    }

    private function createPage(string $keyword, string $token): int
    {
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'url' => '/' . $keyword,
            ], $token),
            Response::HTTP_CREATED,
        );
        $pageId = self::asInt($created['id'] ?? null);
        self::assertGreaterThan(0, $pageId);

        return $pageId;
    }

    private function grantOnPage(int $roleId, int $pageId, int $crud): void
    {
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES,
        );
        self::assertNotNull($typeId);
        $type = $this->em->find(Lookup::class, $typeId);
        self::assertInstanceOf(Lookup::class, $type);
        $role = $this->em->find(Role::class, $roleId);
        self::assertInstanceOf(Role::class, $role);

        $grant = (new RoleDataAccess())
            ->setRole($role)
            ->setResourceType($type)
            ->setResourceId($pageId)
            ->setCrudPermissions($crud);
        $this->em->persist($grant);
        $this->em->flush();

        $this->service(DataAccessSecurityService::class)->invalidateRolePermissions($roleId);
    }
}
