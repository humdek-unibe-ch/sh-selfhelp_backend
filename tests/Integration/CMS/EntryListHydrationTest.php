<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\DataRow;
use App\Entity\DataTable;
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
 * The CMS-in-CMS "N cards" contract (issue #30): an `entry-list` bound to a
 * data table via `data_config` clones its child template once per DATA ROW and
 * flattens each row's cells into the clone's interpolation data, so `{{col}}`
 * resolves per card. This is the exact mechanism the template gallery's public
 * list pages rely on; rows are created through the real form-submit endpoint
 * (never raw SQL) so the table/columns/cells match production.
 */
final class EntryListHydrationTest extends QaWebTestCase
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

    public function testEntryListClonesChildTemplateOncePerDataRow(): void
    {
        // 1. A real form page creates the owned data table through the
        //    form-submit endpoint (three rows = three expected cards).
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_rows', openAccess: true);

        $values = ['Ada', 'Grace', 'Alan'];
        $recordIds = [];
        foreach ($values as $value) {
            $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => (int) $formPage->getId(),
                'section_id' => (int) $formSection->getId(),
                'form_data' => ['qa_answer' => $value],
            ]);
            $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
            self::assertIsInt($recordId);
            $recordIds[] = $recordId;
        }

        $firstRow = $this->em->getRepository(DataRow::class)->find($recordIds[0]);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        // 2. A list page with an entry-list bound to that table and a child
        //    template interpolating the submitted column.
        $listPage = $this->pages->createPage('qa_el_list', openAccess: false);
        $this->pages->grantGroupAcl(
            $listPage,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
            affectedUserIds: [],
        );

        $listSection = $this->pages->createSection('qa_el_list_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        // `data_config` is a global field stored as a column on `sections`.
        $listSection->setDataConfig(json_encode([[
            'scope' => 'entries',
            'table' => $tableName,
            'retrieve' => 'all',
            'current_user' => false,
        ]], JSON_THROW_ON_ERROR));
        $this->em->flush();

        $cardSection = $this->pages->createSection('qa_el_list_card', 'text');
        $this->linkChild($listSection, $cardSection);
        // `text` is a content field; cover both seeded content languages.
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 3);

        $this->pages->invalidatePageScopedCaches();

        // 3. Render: exactly one clone per row, each with its own value.
        $token = $this->loginAsQaUser();
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true',
            null,
            $token
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        $sections = is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)
            ? $data['page']['sections']
            : (is_array($data['sections'] ?? null) ? $data['sections'] : []);
        $entryList = $this->findSectionByStyle($sections, 'entry-list');
        self::assertNotNull($entryList, 'The rendered page must contain the entry-list section.');

        $children = is_array($entryList['children'] ?? null) ? $entryList['children'] : [];
        self::assertCount(
            count($values),
            $children,
            'The child template must be cloned once per data row (N cards).'
        );

        $texts = [];
        foreach ($children as $child) {
            self::assertIsArray($child);
            $textField = is_array($child['text'] ?? null) ? $child['text'] : [];
            $texts[] = $textField['content'] ?? null;
        }
        sort($texts);
        $expected = $values;
        sort($expected);
        self::assertSame(
            $expected,
            $texts,
            'Each clone must interpolate {{qa_answer}} with its own row value.'
        );
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
