<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Service\Cache\Core\CacheService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Section;

/**
 * @extends ServiceEntityRepository<Section>
 */
class SectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheService $cache)
    {
        parent::__construct($registry, Section::class);
    }

    /**
     * Fetch hierarchical sections for a page using a stored procedure.
     *
     * @param int $pageId
     * @return list<array<string, mixed>>
     */
    public function fetchSectionsHierarchicalByPageId(int $pageId): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId)
            ->getList("page_sections_hierarchical_{$pageId}", function () use ($pageId) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_page_sections_hierarchical(:page_id)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('page_id', $pageId, \Doctrine\DBAL\ParameterType::INTEGER);
                $result = $stmt->executeQuery(); // Use executeQuery for statements returning results
                return $result->fetchAllAssociative();
            });
    }

    /**
     * Return all page IDs that contain the given section anywhere in their
     * hierarchy (direct rel_pages_sections link or nested at any depth via
     * rel_sections_hierarchy).
     *
     * @return list<int>
     */
    public function getPageIdsContainingSection(int $sectionId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        /** @var list<array{id_pages: int|string}> $rows */
        $rows = $conn->fetchAllAssociative(<<<SQL
            WITH RECURSIVE ancestors AS (
                SELECT id_child_section AS id, id_parent_section AS parent_id
                FROM rel_sections_hierarchy
                WHERE id_child_section = :sectionId

                UNION ALL

                SELECT sh.id_child_section, sh.id_parent_section
                FROM rel_sections_hierarchy sh
                INNER JOIN ancestors a ON sh.id_child_section = a.parent_id
            )
            SELECT DISTINCT ps.id_pages
            FROM rel_pages_sections ps
            WHERE ps.id_sections = :sectionId
               OR ps.id_sections IN (SELECT parent_id FROM ancestors WHERE parent_id IS NOT NULL)
        SQL, ['sectionId' => $sectionId]);

        return array_map(static fn(array $r): int => (int) $r['id_pages'], $rows);
    }

    /**
     * Return every page that references any of the given sections anywhere in
     * its hierarchy (direct rel_pages_sections link or nested at any depth via
     * rel_sections_hierarchy), deduplicated by page id and ordered by keyword.
     *
     * Shares the same ancestor-walk as {@see getPageIdsContainingSection()} but
     * resolves the full page metadata in a single query for a batch of section
     * ids, so callers (the publish/delete refContainer warnings) do not run one
     * query per section.
     *
     * @param list<int> $sectionIds
     * @return list<array{id: int, keyword: string, isPublished: bool}>
     */
    public function getPagesContainingSections(array $sectionIds): array
    {
        $sectionIds = array_values(array_unique(array_filter(
            array_map('intval', $sectionIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($sectionIds === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        /** @var list<array{id: int|string, keyword: string|null, is_published: int|string|bool|null}> $rows */
        $rows = $conn->fetchAllAssociative(
            <<<SQL
            WITH RECURSIVE ancestors AS (
                SELECT id_child_section AS id, id_parent_section AS parent_id
                FROM rel_sections_hierarchy
                WHERE id_child_section IN (:seedSections)

                UNION ALL

                SELECT sh.id_child_section, sh.id_parent_section
                FROM rel_sections_hierarchy sh
                INNER JOIN ancestors a ON sh.id_child_section = a.parent_id
            )
            SELECT DISTINCT p.id, p.keyword,
                (p.id_published_page_versions IS NOT NULL) AS is_published
            FROM pages p
            JOIN rel_pages_sections ps ON ps.id_pages = p.id
            WHERE ps.id_sections IN (:filterSections)
               OR ps.id_sections IN (SELECT parent_id FROM ancestors WHERE parent_id IS NOT NULL)
            ORDER BY p.keyword ASC
            SQL,
            ['seedSections' => $sectionIds, 'filterSections' => $sectionIds],
            [
                'seedSections' => ArrayParameterType::INTEGER,
                'filterSections' => ArrayParameterType::INTEGER,
            ]
        );

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'keyword' => (string) ($r['keyword'] ?? ''),
            'isPublished' => (bool) $r['is_published'],
        ], $rows);
    }

    /**
     * Get all section IDs for a given page
     *
     * @param int $pageId
     * @return list<int|string> Array of section IDs
     */
    public function getSectionIdsForPage(int $pageId): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DISTINCT s.id FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :page_id';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('page_id', $pageId, \Doctrine\DBAL\ParameterType::INTEGER);
        $result = $stmt->executeQuery();
        /** @var list<array{id: int|string}> $rows */
        $rows = $result->fetchAllAssociative();

        return array_column($rows, 'id');
    }
}