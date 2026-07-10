<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The `loop` style is a server-hydrated repeater (same mechanism as
 * `entry-list`): the backend clones the child template once per row of the
 * style's static `loop` JSON field and flattens each row's keys into the
 * interpolation data, so `{{key}}` resolves per item. These tests pin that
 * contract on the public page render (`/pages/by-keyword/...`), which is what
 * the web and mobile renderers consume.
 */
final class LoopHydrationTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EntityManagerInterface $em */
        $em = $this->service(EntityManagerInterface::class);
        $this->em = $em;
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testLoopFieldRowsCloneTheChildTemplatePerItem(): void
    {
        $page = $this->buildLoopPage(
            'qa_loop_static',
            '[{"title":"Alpha"},{"title":"Beta"},{"title":"Gamma"}]'
        );

        $loopSection = $this->renderAndFindLoopSection($page);

        $children = is_array($loopSection['children'] ?? null) ? $loopSection['children'] : [];
        self::assertCount(3, $children, 'The child template must be cloned once per loop row.');

        $texts = [];
        foreach ($children as $child) {
            self::assertIsArray($child);
            $textField = is_array($child['text'] ?? null) ? $child['text'] : [];
            $texts[] = $textField['content'] ?? null;
        }
        self::assertSame(
            ['Alpha', 'Beta', 'Gamma'],
            $texts,
            'Each clone must interpolate {{title}} with its own row.'
        );
    }

    public function testLoopWithoutRowsRendersNoChildren(): void
    {
        $page = $this->buildLoopPage('qa_loop_empty', '');

        $loopSection = $this->renderAndFindLoopSection($page);

        self::assertSame([], $loopSection['children'] ?? null, 'No rows must mean no children.');
    }

    private function buildLoopPage(string $keyword, string $loopJson): Page
    {
        $page = $this->pages->createPage($keyword, openAccess: false);
        $this->pages->grantGroupAcl(
            $page,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
            affectedUserIds: [],
        );

        $loopSection = $this->pages->createSection($keyword . '_holder', 'loop');
        $this->pages->linkSectionToPage($page, $loopSection, 10);
        if ($loopJson !== '') {
            // `loop` is a property field (display=0), stored under language 1.
            $this->setSectionField($loopSection, 'loop', $loopJson, 1);
        }

        $itemSection = $this->pages->createSection($keyword . '_item', 'text');
        $this->linkChild($loopSection, $itemSection);
        // `text` is a content field; cover both seeded content languages.
        $this->setSectionField($itemSection, 'text', '{{title}}', 2);
        $this->setSectionField($itemSection, 'text', '{{title}}', 3);

        $this->pages->invalidatePageScopedCaches();

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderAndFindLoopSection(Page $page): array
    {
        $token = $this->loginAsQaUser();
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?preview=true',
            null,
            $token
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        $sections = is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)
            ? $data['page']['sections']
            : (is_array($data['sections'] ?? null) ? $data['sections'] : []);

        $loopSection = $this->findSectionByStyle($sections, 'loop');
        self::assertNotNull($loopSection, 'The rendered page must contain the loop section.');

        return $loopSection;
    }

    /**
     * @param array<int|string, mixed> $sections
     * @return array<string, mixed>|null
     */
    private function findSectionByStyle(array $sections, string $styleName): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $styleName) {
                /** @var array<string, mixed> $section */
                return $section;
            }
            if (is_array($section['children'] ?? null)) {
                $found = $this->findSectionByStyle($section['children'], $styleName);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function linkChild(Section $parent, Section $child): void
    {
        $link = new SectionsHierarchy();
        $link->setParentSection($parent);
        $link->setChildSection($child);
        $link->setPosition(10);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function setSectionField(Section $section, string $fieldName, string $content, int $languageId): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        self::assertInstanceOf(Field::class, $field, sprintf('Missing seeded field "%s".', $fieldName));
        $language = $this->em->getRepository(Language::class)->find($languageId);
        self::assertInstanceOf(Language::class, $language);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'QA baseline subject group missing. Run: composer test:reset-db');

        return $group;
    }
}
