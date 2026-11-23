<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Style;
use App\Service\CMS\Admin\PageDraftVersionService;
use App\Service\CMS\Admin\PageDraftWriteRoutes;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Live sync contract C8: a write to a page's draft carries the page version
 * (`X-Page-Version`). Missing: 400 `page_version_required`. Not the current
 * version: 409 `stale_page_version` with the current version. The current
 * version: success, and the response header is one higher.
 *
 * The 400 and 409 answers come from the listener before the controller runs, so
 * they are asserted on every draft-write route with placeholder ids; the success
 * path is asserted on the routes of the brief with real data.
 */
#[Group('security')]
final class PageDraftVersionTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/admin/pages/';

    /**
     * Every draft-write route of {@see PageDraftWriteRoutes}, as method + path suffix
     * (`%1$d` is the page; section and version ids are placeholders the guard never reads).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function draftWriteRoutes(): iterable
    {
        yield 'admin_pages_update' => ['PUT', '%1$d'];
        yield 'admin_pages_delete' => ['DELETE', '%1$d'];
        yield 'admin_pages_add_section' => ['PUT', '%1$d/sections'];
        yield 'admin_pages_bulk_remove_sections' => ['DELETE', '%1$d/sections'];
        yield 'admin_pages_create_section' => ['POST', '%1$d/sections/create'];
        yield 'admin_sections_import_to_page' => ['POST', '%1$d/sections/import'];
        yield 'admin_sections_restore_from_version' => ['POST', '%1$d/sections/restore-from-version/1'];
        yield 'admin_sections_import_to_section' => ['POST', '%1$d/sections/1/import'];
        yield 'admin_sections_add' => ['PUT', '%1$d/sections/1/sections'];
        yield 'admin_sections_create_child' => ['POST', '%1$d/sections/1/sections/create'];
        yield 'admin_sections_remove' => ['DELETE', '%1$d/sections/1/sections/1'];
        yield 'admin_pages_remove_section' => ['DELETE', '%1$d/sections/1'];
        yield 'admin_sections_update' => ['PUT', '%1$d/sections/1'];
        yield 'admin_page_publish' => ['POST', '%1$d/versions/publish'];
        yield 'admin_page_unpublish' => ['POST', '%1$d/versions/unpublish'];
        yield 'admin_page_version_delete' => ['DELETE', '%1$d/versions/1'];
        yield 'admin_page_publish_specific_version' => ['POST', '%1$d/versions/1/publish'];
    }

    public function testTheProviderCoversEveryGuardedRoute(): void
    {
        self::assertCount(count(PageDraftWriteRoutes::NAMES), iterator_to_array(self::draftWriteRoutes()));
        self::assertSame(
            [],
            array_diff(PageDraftWriteRoutes::NAMES, array_keys(iterator_to_array(self::draftWriteRoutes()))),
        );
    }

    public function testReadingAPageTellsItsVersionInBodyAndHeader(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_read', $admin);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::BASE . $pageId, null, $admin));

        self::assertSame(1, $data['draft_version'] ?? null);
        self::assertSame('1', $this->client->getResponse()->headers->get('X-Page-Version'));
    }

    #[DataProvider('draftWriteRoutes')]
    public function testAWriteWithoutTheVersionIsRefusedWithBadRequest(string $method, string $suffix): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_missing', $admin);

        $envelope = $this->jsonRequest($method, self::BASE . sprintf($suffix, $pageId), [], $admin, [self::NO_PAGE_VERSION => '1']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $envelope['status'] ?? null);
        self::assertSame(PageDraftVersionService::ERROR_REQUIRED, $envelope['error'] ?? null);
        self::assertSame(1, $this->versionOf($pageId), 'A refused write leaves the version alone.');
    }

    public function testAMalformedVersionIsTreatedAsMissing(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_malformed', $admin);

        foreach (['abc', '0', '-1', '1.5', '', '01'] as $sent) {
            $envelope = $this->jsonRequest('PUT', self::BASE . $pageId, [], $admin, ['HTTP_X_PAGE_VERSION' => $sent]);
            self::assertSame(
                Response::HTTP_BAD_REQUEST,
                $envelope['status'] ?? null,
                sprintf('"%s" is not a version.', $sent),
            );
        }
    }

    #[DataProvider('draftWriteRoutes')]
    public function testAnOldVersionIsRefusedWithConflictAndTheCurrentVersion(string $method, string $suffix): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_stale', $admin);
        $this->bumpVersionTo($pageId, 3);

        $envelope = $this->jsonRequest($method, self::BASE . sprintf($suffix, $pageId), [], $admin, ['HTTP_X_PAGE_VERSION' => '2']);

        self::assertSame(Response::HTTP_CONFLICT, $envelope['status'] ?? null);
        self::assertSame(PageDraftVersionService::ERROR_STALE, $envelope['error'] ?? null);
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(3, $data['current_version'] ?? null);
        self::assertSame($pageId, $data['page_id'] ?? null);
        self::assertSame(3, $this->versionOf($pageId), 'A refused write leaves the version alone.');
    }

    public function testAVersionFromTheFutureIsAlsoStale(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_future', $admin);

        $envelope = $this->jsonRequest('PUT', self::BASE . $pageId, [], $admin, ['HTTP_X_PAGE_VERSION' => '9']);

        self::assertSame(Response::HTTP_CONFLICT, $envelope['status'] ?? null);
    }

    public function testEveryWriteOfTheBriefRaisesTheVersionByOne(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_success', $admin);
        $containerStyle = $this->styleId('container');

        // Update the page.
        $this->assertWriteRaisesVersion($pageId, 'PUT', '', ['pageData' => ['headless' => false], 'fields' => []], $admin);

        // Create two sections, then update, remove and add one again.
        $first = $this->createSection($pageId, $containerStyle, 0, $admin);
        $second = $this->createSection($pageId, $containerStyle, 10, $admin);
        $this->assertWriteRaisesVersion($pageId, 'PUT', '/sections/' . $first, ['sectionName' => 'qa_ver_renamed', 'contentFields' => [], 'propertyFields' => []], $admin);
        $this->assertWriteRaisesVersion($pageId, 'DELETE', '/sections/' . $second, null, $admin, Response::HTTP_NO_CONTENT);
        $this->assertWriteRaisesVersion($pageId, 'PUT', '/sections', ['sectionId' => $second, 'position' => 20], $admin);

        // Import into the page.
        $this->assertWriteRaisesVersion($pageId, 'POST', '/sections/import', ['position' => 0, 'sections' => []], $admin);

        // Publish, restore the draft from that version, publish again.
        $published = $this->assertWriteRaisesVersion($pageId, 'POST', '/versions/publish', [], $admin, Response::HTTP_CREATED);
        $versionId = self::asInt($published['version_id'] ?? null);
        self::assertGreaterThan(0, $versionId);
        $this->assertWriteRaisesVersion($pageId, 'POST', '/sections/restore-from-version/' . $versionId, [], $admin);
        $this->assertWriteRaisesVersion($pageId, 'POST', '/versions/unpublish', [], $admin);

        // Delete the page.
        $this->jsonRequest('DELETE', self::BASE . $pageId, null, $admin);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->client->getResponse()->headers->get('X-Page-Version'), 'Deleting the page answers with the version too.');
    }

    public function testTwoWritesOnTheSameVersionLetExactlyOneThrough(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_race', $admin);

        $statuses = [];
        for ($i = 0; $i < 2; ++$i) {
            $this->jsonRequest('PUT', self::BASE . $pageId, ['pageData' => ['headless' => false], 'fields' => []], $admin, ['HTTP_X_PAGE_VERSION' => '1']);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        sort($statuses);
        self::assertSame([Response::HTTP_OK, Response::HTTP_CONFLICT], $statuses);
        self::assertSame(2, $this->versionOf($pageId));
    }

    public function testTheClaimIsAtomicInTheService(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_claim', $admin);
        $versions = $this->service(PageDraftVersionService::class);

        self::assertTrue($versions->claim($pageId, 1), 'The first claim of version 1 wins.');
        self::assertFalse($versions->claim($pageId, 1), 'The second claim of the same version loses.');
        self::assertSame(2, $versions->current($pageId));

        $versions->unclaim($pageId, 1);
        self::assertSame(1, $versions->current($pageId), 'A claim of a failed write is given back.');
    }

    public function testAFailedWriteLeavesTheVersionAlone(): void
    {
        $admin = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_ver_failed', $admin);

        // Section 2147483000 does not exist: the controller answers an error, the claim is given back.
        $this->jsonRequest('PUT', self::BASE . $pageId . '/sections/2147483000', ['sectionName' => 'x', 'contentFields' => [], 'propertyFields' => []], $admin);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->client->getResponse()->headers->get('X-Page-Version'));
        self::assertSame(1, $this->versionOf($pageId));
    }

    /**
     * Sends the write with the current version, expects success and a header one higher.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> the response data
     */
    private function assertWriteRaisesVersion(
        int $pageId,
        string $method,
        string $suffix,
        ?array $body,
        string $token,
        int $expectedStatus = Response::HTTP_OK,
    ): array {
        $before = $this->versionOf($pageId);
        if ($expectedStatus === Response::HTTP_NO_CONTENT) {
            $this->client->request($method, self::BASE . $pageId . $suffix, [], [], $this->authHeaders($token, [
                'HTTP_X_PAGE_VERSION' => (string) $before,
            ]));
            self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
            $data = [];
        } else {
            $data = $this->assertEnvelopeSuccess(
                $this->jsonRequest($method, self::BASE . $pageId . $suffix, $body, $token),
                $expectedStatus,
            );
        }

        self::assertSame(
            (string) ($before + 1),
            $this->client->getResponse()->headers->get('X-Page-Version'),
            sprintf('%s %s must answer with the next version.', $method, $suffix),
        );
        self::assertSame($before + 1, $this->versionOf($pageId));

        return $data;
    }

    private function createSection(int $pageId, int $styleId, int $position, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BASE . $pageId . '/sections/create', [
                'styleId' => $styleId,
                'position' => $position,
                'name' => 'qa_ver_section_' . $position,
            ], $token),
            Response::HTTP_CREATED,
        );
        $id = self::asInt($data['id'] ?? $data['section_id'] ?? null);
        self::assertGreaterThan(0, $id);

        return $id;
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

    private function versionOf(int $pageId): int
    {
        return self::asInt($this->em()->getConnection()->fetchOne('SELECT draft_version FROM pages WHERE id = ?', [$pageId]));
    }

    private function bumpVersionTo(int $pageId, int $version): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE pages SET draft_version = ? WHERE id = ?', [$version, $pageId]);
    }

    private function styleId(string $name): int
    {
        $style = $this->em()->getRepository(Style::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Style::class, $style);

        return (int) $style->getId();
    }

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }
}
