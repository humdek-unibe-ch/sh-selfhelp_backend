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
use App\Entity\PageRoute;
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
 * `entry-record` hydrates exactly one row from `fields.data_table` using
 * `load_record_from` (same visible contract as `entry-record-form`): the field
 * names the route param (e.g. `record_id`), and the server loads that row.
 * `data_config` must never choose the row table or retrieve mode.
 */
final class EntryRecordHydrationTest extends QaWebTestCase
{
    private const DETAIL_PATH_PREFIX = '/qa-er-detail';

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

    public function testEntryRecordClonesChildTemplateForMatchingRouteRecordId(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_er_rows', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'Curie'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);

        $detailPage = $this->pages->createPage('qa_er_detail', openAccess: false);
        $this->pages->grantGroupAcl(
            $detailPage,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
            affectedUserIds: [],
        );

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern(self::DETAIL_PATH_PREFIX . '/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_er_record_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        $this->setSectionField($recordSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($recordSection, 'own_entries_only', '0', 1);
        $this->setEntryRecordLoadFrom($recordSection);
        $this->em->flush();

        $cardSection = $this->pages->createSection('qa_er_record_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 3);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $resolve = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::DETAIL_PATH_PREFIX . '/' . $recordId) . '&preview=true',
            null,
            $token,
        );
        $data = $this->assertEnvelopeSuccess($resolve);

        $sections = is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)
            ? $data['page']['sections']
            : (is_array($data['sections'] ?? null) ? $data['sections'] : []);
        $entryRecord = $this->findSectionByStyle($sections, 'entry-record');
        self::assertNotNull($entryRecord, 'The resolved detail page must contain entry-record.');

        $children = is_array($entryRecord['children'] ?? null) ? $entryRecord['children'] : [];
        self::assertCount(1, $children, 'entry-record must clone its child template once for the matched row.');

        $firstChild = $children[0] ?? null;
        self::assertIsArray($firstChild);
        $textField = is_array($firstChild['text'] ?? null) ? $firstChild['text'] : [];
        self::assertSame('Curie', $textField['content'] ?? null);
    }

    public function testMaliciousRecordIdRouteParamYieldsNoHydratedChildren(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_er_mal_rows', openAccess: true);
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'safe'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);

        $detailPage = $this->pages->createPage('qa_er_mal_detail', openAccess: false);
        $this->pages->grantGroupAcl(
            $detailPage,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
            affectedUserIds: [],
        );

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-er-mal/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_er_mal_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        $this->setSectionField($recordSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($recordSection, 'own_entries_only', '0', 1);
        $this->setEntryRecordLoadFrom($recordSection);
        $this->em->flush();

        $cardSection = $this->pages->createSection('qa_er_mal_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();

        // Symfony route requirements reject non-digit segments before hydration.
        $badResolve = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-er-mal/1%20OR%201%3D1') . '&preview=true',
            null,
            $token,
        );
        self::assertNotSame(200, $badResolve['status'] ?? 0);

        // A syntactically valid but non-existent id must not leak another row.
        $missingResolve = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-er-mal/999999999') . '&preview=true',
            null,
            $token,
        );
        $data = $this->assertEnvelopeSuccess($missingResolve);
        $sections = is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)
            ? $data['page']['sections']
            : (is_array($data['sections'] ?? null) ? $data['sections'] : []);
        $entryRecord = $this->findSectionByStyle($sections, 'entry-record');
        self::assertNotNull($entryRecord);
        self::assertSame(
            '',
            $this->firstChildTextContent($entryRecord),
            'A non-existent record id must not hydrate row data into the entry-record template.'
        );
    }

    public function testEntryRecordLoadsViaLoadRecordFromEvenWhenDataConfigPresent(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_er_helper', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'visible'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        $detailPage = $this->pages->createPage('qa_er_helper_detail', openAccess: false);
        $this->pages->grantGroupAcl($detailPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-er-helper/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_er_helper_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        // data_config must not choose the row; load_record_from + data_table do.
        $this->setSectionDataConfig($recordSection, [[
            'scope' => 'filters',
            'table' => $tableName,
            'retrieve' => 'first',
            'current_user' => false,
        ]]);
        $this->setSectionField($recordSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($recordSection, 'own_entries_only', '0', 1);
        $this->setEntryRecordLoadFrom($recordSection);

        $cardSection = $this->pages->createSection('qa_er_helper_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();

        $matchData = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-er-helper/' . $recordId) . '&preview=true',
            null,
            $token,
        ));
        $matchRecord = $this->findSectionByStyle($this->sectionsFromPayload($matchData), 'entry-record');
        self::assertNotNull($matchRecord);
        self::assertSame('visible', $this->firstChildTextContent($matchRecord));
    }

    public function testEntryRecordDataConfigTableAloneDoesNotLoadRowWithoutPropertyDataTable(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_er_legacy_cfg', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'orphan'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        $detailPage = $this->pages->createPage('qa_er_legacy_cfg_detail', openAccess: false);
        $this->pages->grantGroupAcl($detailPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-er-legacy/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_er_legacy_cfg_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        $this->setSectionDataConfig($recordSection, [[
            'scope' => 'entries',
            'table' => $tableName,
            'retrieve' => 'all',
            'current_user' => false,
        ]]);

        $cardSection = $this->pages->createSection('qa_er_legacy_cfg_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-er-legacy/' . $recordId) . '&preview=true',
            null,
            $token,
        ));
        $entryRecord = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-record');
        self::assertNotNull($entryRecord);
        self::assertSame(
            '',
            $this->firstChildTextContent($entryRecord),
            'Legacy data_config row binding must not hydrate entry-record without fields.data_table.',
        );
    }

    public function testEntryRecordWrongLoadRecordFromParamDoesNotHydrate(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_er_wrong_param', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'leak'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);

        $detailPage = $this->pages->createPage('qa_er_wrong_param_detail', openAccess: false);
        $this->pages->grantGroupAcl($detailPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-er-wrong/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_er_wrong_param_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        $this->setSectionField($recordSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($recordSection, 'own_entries_only', '0', 1);
        // Param name does not match the route → fail closed.
        $this->setEntryRecordLoadFrom($recordSection, 'other_id');

        $cardSection = $this->pages->createSection('qa_er_wrong_param_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-er-wrong/' . $recordId) . '&preview=true',
            null,
            $token,
        ));
        $entryRecord = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-record');
        self::assertNotNull($entryRecord);
        self::assertSame('', $this->firstChildTextContent($entryRecord));
    }

    public function testEntryRecordWithoutLoadRecordFromDoesNotHydrate(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_er_no_filter', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'secret'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);

        $detailPage = $this->pages->createPage('qa_er_no_filter_detail', openAccess: false);
        $this->pages->grantGroupAcl($detailPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-er-no-filter/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_er_no_filter_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        $this->setSectionField($recordSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($recordSection, 'own_entries_only', '0', 1);

        $cardSection = $this->pages->createSection('qa_er_no_filter_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-er-no-filter/' . $recordId) . '&preview=true',
            null,
            $token,
        ));
        $entryRecord = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-record');
        self::assertNotNull($entryRecord);
        self::assertSame('', $this->firstChildTextContent($entryRecord));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function sectionsFromPayload(array $data): array
    {
        if (is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)) {
            return $data['page']['sections'];
        }

        return is_array($data['sections'] ?? null) ? $data['sections'] : [];
    }

    private function setEntryRecordLoadFrom(Section $section, string $urlParam = 'record_id'): void
    {
        $this->setSectionField($section, 'load_record_from', $urlParam, 1);
    }

    private function addNamedFormInput(Section $formSection, string $sectionName, string $inputName): Section
    {
        $input = $this->pages->createSection($sectionName, 'input');
        $this->linkChild($formSection, $input);
        $this->setSectionField($input, 'name', $inputName, 1);

        return $input;
    }

    /**
     * @param list<array<string, mixed>> $config
     */
    private function setSectionDataConfig(Section $section, array $config): void
    {
        $section->setDataConfig(json_encode($config, JSON_THROW_ON_ERROR));
        $this->em->persist($section);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $section
     */
    private function firstChildTextContent(array $section): ?string
    {
        $children = $section['children'] ?? null;
        if (!is_array($children)) {
            return null;
        }
        $firstChild = $children[0] ?? null;
        if (!is_array($firstChild)) {
            return null;
        }
        $text = $firstChild['text'] ?? null;
        if (!is_array($text)) {
            return null;
        }
        $content = $text['content'] ?? null;

        return is_string($content) ? $content : null;
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
