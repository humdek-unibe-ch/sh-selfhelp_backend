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
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Security regression matrix for every audited SQL filter call site
 * (see docs/developer/data-table-filter-safety.md).
 */
final class FilterSafetyTest extends QaWebTestCase
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

    /** Call site #1 — DataTableFilterService::prepareFilter (direct). */
    public function testCallSitePrepareFilterRejectsMaliciousRouteRecordId(): void
    {
        $service = $this->service(DataTableFilterService::class);
        $filter = $service->prepareFilter(
            'record_id = {{route.record_id}}',
            ['route' => ['record_id' => "1 OR 1=1"]],
        );

        self::assertSame('', $filter);
    }

    /** Call site #1 — SQL denylist. */
    public function testCallSitePrepareFilterRejectsSqlInjectionFragment(): void
    {
        $service = $this->service(DataTableFilterService::class);
        $filter = $service->prepareFilter("status = 'ok'; DROP TABLE data_rows", []);

        self::assertSame('', $filter);
    }

    /** Call site #4 — DataService::getData boundary strips unsafe filters. */
    public function testCallSiteDataServiceGetDataStripsUnsafeFilter(): void
    {
        [$tableId] = $this->seedSingleRowTable('qa_fs_getdata');

        $dataService = $this->service(DataService::class);
        $rows = $dataService->getData(
            $tableId,
            "'; DELETE FROM data_rows WHERE '1'='1",
            ownEntriesOnly: false,
            userId: -1,
        );

        self::assertSame([], $rows, 'Unsafe filter must return no rows, never inject SQL.');
    }

    /** Call site #4 — DataService::getDataWithUserGroupFilter boundary. */
    public function testCallSiteDataServiceGetDataWithUserGroupFilterStripsUnsafeFilter(): void
    {
        [$tableId] = $this->seedSingleRowTable('qa_fs_group');

        $dataService = $this->service(DataService::class);
        $rows = $dataService->getDataWithUserGroupFilter(
            $tableId,
            2,
            "1; DROP TABLE data_rows",
        );

        self::assertIsArray($rows);
    }

    /** Call site #3 — SectionUtilityService::fetchData / retrieveData (data_config path). */
    public function testCallSiteDataConfigRetrieveDataRejectsMaliciousFilter(): void
    {
        [, , $tableName] = $this->seedSingleRowTable('qa_fs_datacfg', returnTableName: true);

        $utility = $this->service(SectionUtilityService::class);
        $rows = $utility->retrieveData([
            'table' => $tableName,
            'retrieve' => 'all',
            'filter' => "'; DELETE FROM data_rows WHERE '1'='1",
            'current_user' => false,
        ]);

        self::assertSame([], $rows, 'Malicious data_config filter must return no rows.');
    }

    /** Call site #2 / #1 — PageService::resolveEntryRows via entry-list author filter. */
    public function testCallSiteEntryListAuthorFilterRejectsInjection(): void
    {
        [$tableId] = $this->seedSingleRowTable('qa_fs_elist');

        $listPage = $this->pages->createPage('qa_fs_elist_page', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_fs_elist_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionField($listSection, 'data_table', (string) $tableId, 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->setSectionField($listSection, 'filter', "'; DELETE FROM data_rows WHERE '1'='1", 1);

        $cardSection = $this->pages->createSection('qa_fs_elist_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $entryList = $this->findSectionByStyle($this->sectionsFromPagePayload($data), 'entry-list');
        self::assertNotNull($entryList);
        $children = is_array($entryList['children'] ?? null) ? $entryList['children'] : [];
        self::assertCount(0, $children, 'Rejected injection filter must yield no hydrated rows.');
    }

    /** Call site #1 — entry-record route param validation on public resolve. */
    public function testCallSiteEntryRecordMaliciousRouteParamDoesNotLeakRowData(): void
    {
        [$tableId] = $this->seedSingleRowTable('qa_fs_erec');

        $detailPage = $this->pages->createPage('qa_fs_erec_detail', openAccess: false);
        $this->pages->grantGroupAcl($detailPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $route = new PageRoute();
        $route->setPage($detailPage);
        $route->setPathPattern('/qa-fs-erec/{record_id}');
        $route->setRequirements(['record_id' => '\\d+']);
        $route->setIsCanonical(true);
        $route->setIsActive(true);
        $this->em->persist($route);
        $this->em->flush();

        $recordSection = $this->pages->createSection('qa_fs_erec_holder', 'entry-record');
        $this->pages->linkSectionToPage($detailPage, $recordSection, 10);
        $this->setSectionField($recordSection, 'data_table', (string) $tableId, 1);
        $this->setSectionField($recordSection, 'own_entries_only', '0', 1);

        $cardSection = $this->pages->createSection('qa_fs_erec_card', 'text');
        $this->linkChild($recordSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();

        $badResolve = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-fs-erec/1%20OR%201%3D1') . '&preview=true',
            null,
            $token,
        );
        self::assertNotSame(200, $badResolve['status'] ?? 0);

        $missingResolve = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-fs-erec/999999999') . '&preview=true',
                null,
                $token,
            ),
        );
        $entryRecord = $this->findSectionByStyle($this->sectionsFromPagePayload($missingResolve), 'entry-record');
        self::assertNotNull($entryRecord);
        self::assertSame('', $this->firstChildTextContent($entryRecord));
    }

    /** Call site #6 — DataService update-path typed predicate builder. */
    public function testCallSiteUpdatePredicateEscapesQuotes(): void
    {
        $service = $this->service(DataTableFilterService::class);
        self::assertSame(
            " AND title = 'O''Reilly'",
            $service->buildStringEqualityPredicate('title', "O'Reilly"),
        );
        self::assertSame('', $service->buildStringEqualityPredicate("title; DROP", 'x'));
    }

    /** Call site #5 — repository last-line guard (via service API used by repository). */
    #[DataProvider('repositoryGuardProvider')]
    public function testCallSiteRepositoryGuardRejectsBadFragments(string $raw, string $expected): void
    {
        $service = $this->service(DataTableFilterService::class);
        self::assertSame($expected, $service->guardForStoredProcedure($raw));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function repositoryGuardProvider(): array
    {
        return [
            ['AND status = 1', 'AND status = 1'],
            ['AND x = {{route.record_id}}', ''],
            [str_repeat('A', DataTableFilterService::MAX_FILTER_LENGTH + 5), ''],
        ];
    }

    /**
     * @return array{0: int, 1: int, 2?: string}
     */
    private function seedSingleRowTable(string $keyword, bool $returnTableName = false): array
    {
        [$formPage, $formSection] = $this->pages->createFormPage($keyword . '_form', openAccess: true);
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'seed'],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableId = (int) $dataTable->getId();

        $result = [$tableId, $recordId];
        if ($returnTableName) {
            $result[] = (string) $dataTable->getName();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function sectionsFromPagePayload(array $data): array
    {
        if (is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)) {
            return $data['page']['sections'];
        }

        return is_array($data['sections'] ?? null) ? $data['sections'] : [];
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
        self::assertInstanceOf(Group::class, $group);

        return $group;
    }
}
