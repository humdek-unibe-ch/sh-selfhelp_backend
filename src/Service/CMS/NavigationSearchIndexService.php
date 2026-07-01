<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\Language;
use App\Entity\Page;
use App\Entity\PageSearchIndex;
use App\Repository\LanguageRepository;
use App\Repository\PageRepository;
use App\Repository\PageSearchIndexRepository;
use App\Service\Core\BaseService;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintains the searchable text projection used by {@see NavigationSearchService}.
 */
class NavigationSearchIndexService extends BaseService
{
    /** Field names excluded from body indexing (submissions, secrets, raw config). */
    private const EXCLUDED_FIELD_NAMES = [
        'data_config',
        'json_config',
        'css',
        'css_mobile',
        'firebase_config',
        'own_entries_only',
        'data_table',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly PageSearchIndexRepository $pageSearchIndexRepository,
        private readonly LanguageRepository $languageRepository,
    ) {
    }

    public function rebuildForPage(int $pageId): void
    {
        if (!$this->searchIndexTableExists()) {
            return;
        }

        $page = $this->pageRepository->find($pageId);
        if (!$page instanceof Page) {
            return;
        }

        $languages = $this->languageRepository->findAll();
        foreach ($languages as $language) {
            $languageId = (int) $language->getId();
            $projection = $this->buildProjectionForPage($pageId, $languageId);
            if ($projection === null) {
                $existing = $this->pageSearchIndexRepository->findOneByPageAndLanguage($pageId, $languageId);
                if ($existing instanceof PageSearchIndex) {
                    $this->entityManager->remove($existing);
                }
                continue;
            }

            $row = $this->pageSearchIndexRepository->findOneByPageAndLanguage($pageId, $languageId)
                ?? (new PageSearchIndex())->setPage($page)->setLanguage($language);
            $row->setTitleText($projection['title']);
            $row->setDescriptionText($projection['description']);
            $row->setBodyText($projection['body']);
            $this->entityManager->persist($row);
        }

        $this->entityManager->flush();
    }

    public function deleteForPage(int $pageId): void
    {
        if (!$this->searchIndexTableExists()) {
            return;
        }

        $this->pageSearchIndexRepository->deleteForPage($pageId);
    }

    /**
     * @param array<int, true> $accessiblePageIds
     *
     * @return list<array{page_id: int, keyword: string, url: string|null, weight: int, snippet_source: string, snippet_text: string}>
     */
    public function searchIndexedContent(int $languageId, string $query, int $limit, array $accessiblePageIds): array
    {
        if ($accessiblePageIds === [] || !$this->searchIndexTableExists()) {
            return [];
        }

        $needle = mb_strtolower($query);
        $like = '%' . addcslashes($needle, '%_') . '%';
        $pageIdList = array_keys($accessiblePageIds);

        $connection = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($pageIdList), '?'));
        $sql = <<<SQL
            SELECT p.id AS page_id, p.keyword, p.url,
                   psi.title_text, psi.description_text, psi.body_text
            FROM page_search_index psi
            INNER JOIN pages p ON p.id = psi.id_pages
            WHERE psi.id_languages = ?
              AND p.id IN ({$placeholders})
              AND (
                LOWER(COALESCE(psi.title_text, '')) LIKE ?
                OR LOWER(COALESCE(psi.description_text, '')) LIKE ?
                OR LOWER(COALESCE(psi.body_text, '')) LIKE ?
              )
            LIMIT ?
            SQL;

        $params = array_merge([$languageId], $pageIdList, [$like, $like, $like, $limit * 4]);
        $types = array_merge(
            [ParameterType::INTEGER],
            array_fill(0, count($pageIdList), ParameterType::INTEGER),
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        $hits = [];
        foreach ($rows as $row) {
            $pageIdRaw = $row['page_id'] ?? null;
            if (!is_numeric($pageIdRaw)) {
                continue;
            }
            $pageId = (int) $pageIdRaw;
            $keywordRaw = $row['keyword'] ?? '';
            $keyword = is_string($keywordRaw) ? $keywordRaw : '';
            $title = is_string($row['title_text'] ?? null) ? $row['title_text'] : '';
            $description = is_string($row['description_text'] ?? null) ? $row['description_text'] : '';
            $body = is_string($row['body_text'] ?? null) ? $row['body_text'] : '';

            $weight = 0;
            $snippetSource = 'content';
            $snippetText = '';
            if ($title !== '' && str_contains(mb_strtolower($title), $needle)) {
                $weight = 30;
                $snippetSource = 'title';
                $snippetText = $title;
            } elseif ($description !== '' && str_contains(mb_strtolower($description), $needle)) {
                $weight = 20;
                $snippetSource = 'description';
                $snippetText = $description;
            } elseif ($body !== '' && str_contains(mb_strtolower($body), $needle)) {
                $weight = 10;
                $snippetSource = 'content';
                $snippetText = $this->extractSnippet($body, $query);
            }

            if ($weight === 0) {
                continue;
            }

            $hits[] = [
                'page_id' => $pageId,
                'keyword' => $keyword,
                'url' => is_string($row['url'] ?? null) ? $row['url'] : null,
                'weight' => $weight,
                'snippet_source' => $snippetSource,
                'snippet_text' => $snippetText,
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return array_slice($hits, 0, $limit);
    }

    /**
     * @return array{title: string|null, description: string|null, body: string|null}|null
     */
    private function buildProjectionForPage(int $pageId, int $languageId): ?array
    {
        $connection = $this->entityManager->getConnection();

        $titleSql = <<<'SQL'
            SELECT pft.content
            FROM pages_fields_translation pft
            INNER JOIN fields f ON f.id = pft.id_fields
            WHERE pft.id_pages = :pageId
              AND pft.id_languages = :langId
              AND f.display = 1
              AND f.name IN ('title', 'name')
            ORDER BY FIELD(f.name, 'title', 'name')
            LIMIT 1
            SQL;
        $title = $connection->fetchOne($titleSql, ['pageId' => $pageId, 'langId' => $languageId]);

        $descriptionSql = <<<'SQL'
            SELECT pft.content
            FROM pages_fields_translation pft
            INNER JOIN fields f ON f.id = pft.id_fields
            WHERE pft.id_pages = :pageId
              AND pft.id_languages = :langId
              AND f.display = 1
              AND f.name IN ('description', 'meta_description')
            LIMIT 1
            SQL;
        $description = $connection->fetchOne($descriptionSql, ['pageId' => $pageId, 'langId' => $languageId]);

        $excluded = implode(',', array_map(
            static fn (string $name): string => $connection->quote($name),
            self::EXCLUDED_FIELD_NAMES,
        ));

        $bodySql = <<<SQL
            SELECT sft.content
            FROM sections_fields_translation sft
            INNER JOIN fields f ON f.id = sft.id_fields
            INNER JOIN sections s ON s.id = sft.id_sections
            INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
            WHERE ps.id_pages = :pageId
              AND sft.id_languages = :langId
              AND f.display = 1
              AND f.name NOT IN ({$excluded})
              AND sft.content IS NOT NULL
              AND TRIM(sft.content) <> ''
            SQL;
        /** @var list<scalar|null> $bodyRows */
        $bodyRows = $connection->fetchFirstColumn($bodySql, ['pageId' => $pageId, 'langId' => $languageId]);

        $bodyParts = [];
        foreach ($bodyRows as $content) {
            if (!is_string($content)) {
                continue;
            }
            $plain = trim(strip_tags($content));
            if ($plain !== '') {
                $bodyParts[] = $plain;
            }
        }
        $body = $bodyParts === [] ? null : implode("\n", $bodyParts);

        $titleStr = is_string($title) ? trim(strip_tags($title)) : null;
        $descriptionStr = is_string($description) ? trim(strip_tags($description)) : null;

        if (($titleStr === null || $titleStr === '') && ($descriptionStr === null || $descriptionStr === '') && $body === null) {
            return null;
        }

        return [
            'title' => $titleStr !== '' ? $titleStr : null,
            'description' => $descriptionStr !== '' ? $descriptionStr : null,
            'body' => $body,
        ];
    }

    private function extractSnippet(string $text, string $query): string
    {
        $plain = trim($text);
        $pos = mb_stripos($plain, $query);
        if ($pos === false) {
            return mb_substr($plain, 0, 120);
        }
        $start = max(0, $pos - 40);

        return mb_substr($plain, $start, 120);
    }

    private function searchIndexTableExists(): bool
    {
        return $this->entityManager->getConnection()->createSchemaManager()->tablesExist(['page_search_index']);
    }
}
