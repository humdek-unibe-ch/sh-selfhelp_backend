<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden public-page rendering workflow:
 *
 *   build a CMS page graph (page + three form sections at out-of-order
 *   positions) -> grant the subject group ACL select -> a subject user loads
 *   the page through the PUBLIC frontend API by id and by keyword ->
 *   the response envelope + JSON schema are valid, the sections come back in
 *   ascending position order, and a second load is consistent (cache-stable).
 *
 * This exercises the real recursive section rendering + ACL gate + page-access
 * mode resolution end to end through the public API (plan §"golden workflow":
 * assert domain-visible effects, not internals). All data is qa_-prefixed and
 * created inside the DAMA transaction, which is rolled back afterwards.
 */
#[TestGroup('golden')]
final class PublicPageRenderingWorkflowTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_public_render_workflow';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();

        // One container for the whole test so the permissions cache the factory
        // invalidates is the exact pool the request reads (see FormControllerTest).
        $this->client->disableReboot();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $schema = $container->get(JsonSchemaValidationService::class);
        self::assertInstanceOf(JsonSchemaValidationService::class, $schema);
        $this->schema = $schema;

        $acl = $container->get(ACLService::class);
        self::assertInstanceOf(ACLService::class, $acl);
        $lookup = $container->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookup);
        $cache = $container->get(CacheService::class);
        self::assertInstanceOf(CacheService::class, $cache);

        $this->pages = new PageSectionFactory($this->em, $acl, $lookup, $cache);
    }

    public function testSubjectUserRendersAclGatedPageWithOrderedSections(): void
    {
        // 1. Build the page graph: one page + three form sections linked at
        //    out-of-order positions so we can prove ordering is applied.
        $page = $this->pages->createPage(self::KEYWORD, openAccess: false);

        $sectionLast = $this->pages->createSection('qa_render_s_last', 'form-record');
        $sectionFirst = $this->pages->createSection('qa_render_s_first', 'form-record');
        $sectionMiddle = $this->pages->createSection('qa_render_s_middle', 'form-record');

        $this->pages->linkSectionToPage($page, $sectionLast, 30);
        $this->pages->linkSectionToPage($page, $sectionFirst, 10);
        $this->pages->linkSectionToPage($page, $sectionMiddle, 20);

        $expectedOrder = [
            (int) $sectionFirst->getId(),
            (int) $sectionMiddle->getId(),
            (int) $sectionLast->getId(),
        ];

        // 2. Grant the subject group ACL select; the qa.user persona is a member.
        $this->pages->grantGroupAcl(
            $page,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
            affectedUserIds: [$this->qaUserId()],
        );

        $pageId = (int) $page->getId();
        $token = $this->loginAsQaUser();

        // 3. Load by keyword through the public API (the single page-content path).
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . self::KEYWORD, null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertResponseSchema('responses/frontend/get_page');
        self::assertArrayHasKey('page', $data);
        $pageData = $this->asArray($data['page']);
        self::assertSame($pageId, $pageData['id'] ?? null);
        self::assertSame(self::KEYWORD, $pageData['keyword'] ?? null);

        $renderedOrder = $this->topLevelSectionIds($data);
        self::assertSame(
            $expectedOrder,
            $renderedOrder,
            'Sections must render in ascending position order.'
        );

        // 4. A second load is consistent (cache-stable, no drift).
        $again = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . self::KEYWORD, null, $token)
        );
        self::assertSame($expectedOrder, $this->topLevelSectionIds($again), 'Repeat render must be stable.');
    }

    public function testSubjectUserWithoutGrantIsForbidden(): void
    {
        // Same page graph but no ACL grant -> the public API denies the read.
        $page = $this->pages->createPage('qa_public_render_denied', openAccess: false);
        $section = $this->pages->createSection('qa_render_denied_s', 'form-record');
        $this->pages->linkSectionToPage($page, $section, 10);

        $envelope = $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/qa_public_render_denied', null, $this->loginAsQaUser());

        self::assertSame(Response::HTTP_FORBIDDEN, $envelope['status'] ?? null, 'No ACL grant must be forbidden.');
    }

    /**
     * Regression for the "anonymous == user id 1 (admin)" ACL bug: a genuinely
     * anonymous caller (no JWT) must NOT inherit the admin group's ACL. The page
     * is non-open and granted ACL select to the *admin* group only. Before the
     * fix the guest fallback resolved to user id 1 — an admin-group member — so
     * `get_user_acl` returned the group grant and leaked the page (200). The
     * guest sentinel (id 0) is in no group, so branch 1 of `get_user_acl` is
     * empty and the read is forbidden.
     */
    public function testAnonymousVisitorCannotInheritAdminGroupAcl(): void
    {
        $page = $this->pages->createPage('qa_public_render_anon_admin', openAccess: false);
        $this->pages->linkSectionToPage($page, $this->pages->createSection('qa_render_anon_admin_s'), 10);
        $this->pages->grantGroupAcl(
            $page,
            $this->adminGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
        );

        // No token => genuinely anonymous request (the route is permission-less).
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/qa_public_render_anon_admin');

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $envelope['status'] ?? null,
            'Anonymous callers must not inherit the admin group ACL.',
        );
    }

    /**
     * The flip side: an open-access page carries no ACL requirement (branch 2 of
     * `get_user_acl` grants select to any caller), so an anonymous visitor reads
     * it. Open-access is the only anonymous entry point after the sentinel fix.
     */
    public function testAnonymousVisitorReadsOpenAccessPage(): void
    {
        $page = $this->pages->createPage('qa_public_render_anon_open', openAccess: true);
        $this->pages->linkSectionToPage($page, $this->pages->createSection('qa_render_anon_open_s'), 10);

        $envelope = $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/qa_public_render_anon_open');

        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame('qa_public_render_anon_open', $this->asArray($data['page'] ?? [])['keyword'] ?? null);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return list<int>
     */
    private function topLevelSectionIds(array $data): array
    {
        self::assertIsArray($data['page'] ?? null);
        $sections = $data['page']['sections'] ?? null;
        self::assertIsArray($sections, 'Rendered page must carry a sections array.');

        $ids = [];
        foreach ($sections as $section) {
            if (is_array($section) && isset($section['id']) && is_scalar($section['id'])) {
                $ids[] = (int) $section['id'];
            }
        }

        return $ids;
    }

    private function assertResponseSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, "Response failed schema {$schemaName}:\n" . implode("\n", $errors));
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }

    private function adminGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "admin" group must exist.');

        return $group;
    }

    private function qaUserId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
