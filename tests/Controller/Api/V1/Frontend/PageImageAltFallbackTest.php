<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Asset;
use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Lookup;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AssetStorageService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * An image's default alt is set once on the asset and fills every image
 * section that leaves its own `alt` empty; a section's own alt always wins.
 */
final class PageImageAltFallbackTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_image_alt_fallback';
    private const LANGUAGE_ID = 2;
    private const ASSET_ALT = 'qa default alt from the asset';

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

    public function testEmptySectionAltFallsBackToTheAssetAltAndOwnAltWins(): void
    {
        $url = $this->createAssetWithAlt();

        $page = $this->pages->createPage(self::KEYWORD, true);
        $fallback = $this->pages->createSection(self::KEYWORD . '_fallback', 'image');
        $own = $this->pages->createSection(self::KEYWORD . '_own', 'image');
        $this->pages->linkSectionToPage($page, $fallback, 10);
        $this->pages->linkSectionToPage($page, $own, 20);
        $this->setField($fallback, 'img_src', $url);
        $this->setField($own, 'img_src', $url);
        $this->setField($own, 'alt', 'qa section alt');
        $this->pages->invalidatePageScopedCaches();

        $this->client->request('GET', '/cms-api/v1/pages/by-keyword/' . self::KEYWORD . '?language_id=' . self::LANGUAGE_ID);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $alts = $this->altsBySectionName();
        self::assertSame(self::ASSET_ALT, $alts[self::KEYWORD . '_fallback'] ?? null, 'An empty alt must take the asset default');
        self::assertSame('qa section alt', $alts[self::KEYWORD . '_own'] ?? null, "A section's own alt must win");
    }

    private function createAssetWithAlt(): string
    {
        /** @var AssetStorageService $storage */
        $storage = $this->service(AssetStorageService::class);
        $type = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::ASSET_TYPES,
            'lookupCode' => LookupService::ASSET_TYPES_ASSET,
        ]);
        self::assertInstanceOf(Lookup::class, $type);

        $uuid = $storage->generateUuid();
        $asset = new Asset();
        $asset->setUuid($uuid);
        $asset->setAssetType($type);
        $asset->setName('qa-alt.png');
        $asset->setAlt(self::ASSET_ALT);
        $this->em->persist($asset);
        $this->em->flush();

        return $storage->publicUrl($uuid);
    }

    private function setField(Section $section, string $fieldName, string $value): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        $language = $this->em->getRepository(Language::class)->find(self::LANGUAGE_ID);
        self::assertInstanceOf(Field::class, $field, sprintf('The %s field must be seeded.', $fieldName));
        self::assertInstanceOf(Language::class, $language);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent($value);
        $this->em->persist($translation);
        $this->em->flush();
    }

    /**
     * @return array<string, mixed> section name => alt content
     */
    private function altsBySectionName(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        $page = $data['page'] ?? null;
        self::assertIsArray($page);
        $sections = $page['sections'] ?? null;
        self::assertIsArray($sections);

        $alts = [];
        foreach ($sections as $section) {
            self::assertIsArray($section);
            $name = $section['section_name'] ?? $section['name'] ?? null;
            $alt = $section['alt'] ?? null;
            if (is_string($name)) {
                $alts[$name] = is_array($alt) ? ($alt['content'] ?? null) : null;
            }
        }

        return $alts;
    }
}
