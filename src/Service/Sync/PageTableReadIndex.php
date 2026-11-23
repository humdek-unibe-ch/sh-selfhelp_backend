<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Repository\SectionRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Effective\EffectivePage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which pages read a data table for every user, as the visitors are served them
 * (published snapshot, or the live draft when nothing is published yet).
 *
 * A table change tells the groups that see such a page (C3). The answer is a
 * plain derivation of the page configuration, kept as one cache item of the
 * existing pages category: it is rebuilt after any page, section or publish
 * change ({@see invalidate()}), when a table it has not seen appears, and after a
 * short time as a safety net. It is a cache, never a source of truth.
 */
final class PageTableReadIndex
{
    private const KEY = 'sync_table_read_index';
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SectionRepository $sectionRepository,
        private readonly PageTableScanner $scanner,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @return list<int> ids of the pages that read the table for every user
     */
    public function pagesReadingForAllUsers(int $tableId): array
    {
        $index = $this->load();
        if (!isset($index['known'][$tableId])) {
            // A table that appeared after the index was built (the first save of a form creates it).
            $this->invalidate();
            $index = $this->load();
        }

        return $index['readers'][$tableId] ?? [];
    }

    public function invalidate(): void
    {
        $this->cache->withCategory(CacheService::CATEGORY_PAGES)->invalidateItem(self::KEY);
    }

    /**
     * @return array{known: array<int, true>, readers: array<int, list<int>>}
     */
    private function load(): array
    {
        /** @var array{known: array<int, true>, readers: array<int, list<int>>} $index */
        $index = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getItem(self::KEY, fn (): array => $this->build(), self::TTL_SECONDS);

        return $index;
    }

    /**
     * @return array{known: array<int, true>, readers: array<int, list<int>>}
     */
    private function build(): array
    {
        $connection = $this->entityManager->getConnection();

        $known = [];
        foreach ($connection->fetchFirstColumn('SELECT id FROM data_tables') as $id) {
            if (is_numeric($id)) {
                $known[(int) $id] = true;
            }
        }

        /** @var array<int, int|null> $versionByPage */
        $versionByPage = [];
        foreach ($connection->fetchAllAssociative('SELECT id, id_published_page_versions FROM pages') as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $versionByPage[(int) $row['id']] = is_numeric($row['id_published_page_versions'] ?? null) ? (int) $row['id_published_page_versions'] : null;
            }
        }

        $jsonByVersion = [];
        $versionIds = array_values(array_filter($versionByPage, static fn (?int $v): bool => $v !== null));
        if ($versionIds !== []) {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, page_json FROM page_versions WHERE id IN (?)',
                [$versionIds],
                [ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                if (is_numeric($row['id'] ?? null) && is_string($row['page_json'] ?? null)) {
                    $decoded = json_decode($row['page_json'], true);
                    if (is_array($decoded)) {
                        $jsonByVersion[(int) $row['id']] = $decoded;
                    }
                }
            }
        }

        /** @var array<int, array<int, true>> $readers */
        $readers = [];
        foreach ($versionByPage as $pageId => $versionId) {
            $effective = $versionId !== null && isset($jsonByVersion[$versionId])
                ? $this->publishedPage($pageId, $versionId, $jsonByVersion[$versionId])
                : $this->draftPage($pageId);
            foreach ($this->scanner->scan($effective)['all_users'] as $tableId) {
                $readers[$tableId][$pageId] = true;
            }
        }

        $byTable = [];
        foreach ($readers as $tableId => $pages) {
            $ids = array_keys($pages);
            sort($ids);
            $byTable[$tableId] = $ids;
        }

        return ['known' => $known, 'readers' => $byTable];
    }

    /**
     * @param array<array-key, mixed> $json
     */
    private function publishedPage(int $pageId, int $versionId, array $json): EffectivePage
    {
        $pageNode = $json['page'] ?? null;
        $sections = is_array($pageNode) && is_array($pageNode['sections'] ?? null) ? array_values($pageNode['sections']) : [];
        /** @var list<array<string, mixed>> $sections */

        return EffectivePage::published($pageId, $versionId, $sections);
    }

    private function draftPage(int $pageId): EffectivePage
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = $this->sectionRepository->fetchSectionsHierarchicalByPageId($pageId);

        return EffectivePage::draft($pageId, $rows, fn (int $sectionId): array => $this->liveFields($sectionId));
    }

    /**
     * @return array<string, array<int, string>> field name => language id => stored content
     */
    private function liveFields(int $sectionId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT f.name AS name, t.id_languages AS language_id, t.content AS content'
            . ' FROM sections_fields_translation t JOIN fields f ON f.id = t.id_fields'
            . ' WHERE t.id_sections = :section',
            ['section' => $sectionId],
            ['section' => ParameterType::INTEGER],
        );

        $raw = [];
        foreach ($rows as $row) {
            if (is_string($row['name'] ?? null) && is_numeric($row['language_id'] ?? null)) {
                $raw[$row['name']][(int) $row['language_id']] = is_scalar($row['content'] ?? null) ? (string) $row['content'] : '';
            }
        }

        return $raw;
    }
}
