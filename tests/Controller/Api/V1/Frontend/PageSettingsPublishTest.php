<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\PagesFieldsTranslation;
use App\Entity\PageVersion;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * L5: a page's title and description are part of its version. Editing them
 * changes nothing for visitors until Publish, the change is reported as
 * unpublished, and restoring a version brings the old values back. A version
 * that holds no settings serves none (the live page is never read for it).
 */
final class PageSettingsPublishTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_page_settings_publish';
    private const LANGUAGE_ID = 2;

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testTitleAndDescriptionChangesWaitForPublishAndComeBackOnRestore(): void
    {
        $admin = $this->loginAsQaAdmin();
        $page = $this->createPageWithSection();
        $this->setPageField($page, 'title', 'qa title v1');
        $this->setPageField($page, 'description', 'qa description v1');

        $v1 = $this->publish($page, $admin);
        self::assertSame('qa title v1', $this->publicPage()['title'] ?? null);

        $this->setPageField($page, 'title', 'qa title v2');
        $this->setPageField($page, 'description', 'qa description v2');

        $public = $this->publicPage();
        self::assertSame('qa title v1', $public['title'] ?? null, 'An unpublished title must not reach visitors');
        self::assertSame('qa description v1', $public['description'] ?? null);
        self::assertTrue($this->hasUnpublishedChanges($page, $admin), 'A title edit is an unpublished change');

        $this->publish($page, $admin);
        $public = $this->publicPage();
        self::assertSame('qa title v2', $public['title'] ?? null, 'Publish takes the new title live');
        self::assertSame('qa description v2', $public['description'] ?? null);
        self::assertFalse($this->hasUnpublishedChanges($page, $admin));

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/restore-from-version/%d', (int) $page->getId(), $v1),
            null,
            $admin
        ));
        $this->em->clear();
        self::assertSame('qa title v1', $this->storedField((int) $page->getId(), 'title'), 'Restore brings the old title back');
        self::assertSame('qa description v1', $this->storedField((int) $page->getId(), 'description'));
    }

    public function testAVersionWithoutSettingsServesNoneAndReportsTheDraftAsChangedUntilThePublish(): void
    {
        $admin = $this->loginAsQaAdmin();
        $page = $this->createPageWithSection();
        $this->setPageField($page, 'title', 'qa title v1');
        $versionId = $this->publish($page, $admin);

        // A version written before settings were versioned has no page.fields.
        $version = $this->em->getRepository(PageVersion::class)->find($versionId);
        self::assertInstanceOf(PageVersion::class, $version);
        $json = $version->getPageJson();
        self::assertIsArray($json['page'] ?? null);
        unset($json['page']['fields']);
        $version->setPageJson($json);
        $this->em->flush();

        $this->setPageField($page, 'title', 'qa live title');

        $public = $this->publicPage();
        self::assertNull($public['title'] ?? null, 'Nothing is read from the live page for such a version');
        self::assertNull($public['description'] ?? null);
        self::assertFalse($public['open_in_modal'] ?? null);
        self::assertTrue($this->hasUnpublishedChanges($page, $admin), 'The draft has settings the version lacks');

        // The next Publish writes them, and the page is in step again.
        $this->publish($page, $admin);
        self::assertSame('qa live title', $this->publicPage()['title'] ?? null);
        self::assertFalse($this->hasUnpublishedChanges($page, $admin));
    }

    private function createPageWithSection(): Page
    {
        $page = $this->pages->createPage(self::KEYWORD, true);
        $this->pages->linkSectionToPage($page, $this->pages->createSection(self::KEYWORD . '_image', 'image'), 10);
        $this->pages->invalidatePageScopedCaches();

        return $page;
    }

    private function setPageField(Page $page, string $fieldName, string $content): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        $language = $this->em->getRepository(Language::class)->find(self::LANGUAGE_ID);
        self::assertInstanceOf(Field::class, $field, sprintf('The %s field must be seeded.', $fieldName));
        self::assertInstanceOf(Language::class, $language);

        $translation = $this->em->getRepository(PagesFieldsTranslation::class)
            ->findOneBy(['page' => $page, 'field' => $field, 'language' => $language]);
        if ($translation === null) {
            $translation = new PagesFieldsTranslation();
            $translation->setPage($page);
            $translation->setField($field);
            $translation->setLanguage($language);
            $this->em->persist($translation);
        }
        $translation->setContent($content);
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();
    }

    private function publish(Page $page, string $admin): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', sprintf('/cms-api/v1/admin/pages/%d/versions/publish', (int) $page->getId()), [], $admin),
            Response::HTTP_CREATED
        );
        self::assertIsInt($data['version_id'] ?? null);

        return $data['version_id'];
    }

    private function hasUnpublishedChanges(Page $page, string $admin): bool
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/versions/has-changes', (int) $page->getId()),
            null,
            $admin
        ));

        return (bool) ($data['has_unpublished_changes'] ?? null);
    }

    /**
     * @return array<mixed> the public (published) page as a visitor gets it
     */
    private function publicPage(): array
    {
        $this->client->request('GET', '/cms-api/v1/pages/by-keyword/' . self::KEYWORD . '?language_id=' . self::LANGUAGE_ID);
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['data'] ?? null);
        self::assertIsArray($payload['data']['page'] ?? null);

        return $payload['data']['page'];
    }

    private function storedField(int $pageId, string $fieldName): ?string
    {
        $translation = $this->em->getRepository(PagesFieldsTranslation::class)->findOneBy([
            'page' => $pageId,
            'field' => $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]),
            'language' => self::LANGUAGE_ID,
        ]);

        return $translation?->getContent();
    }
}
