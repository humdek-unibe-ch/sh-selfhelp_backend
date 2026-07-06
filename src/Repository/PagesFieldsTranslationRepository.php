<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\PagesFieldsTranslation;
use App\Util\TranslationContentHelper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PagesFieldsTranslation>
 */
class PagesFieldsTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PagesFieldsTranslation::class);
    }

    /**
     * Fetch all page field translations for a list of page IDs and specific language
     * Only fetches translations for fields with display=1 (title fields)
     *
     * @param list<int> $pageIds Array of page IDs
     * @param int $languageId Language ID
     * @return array<int|string, array<string, mixed>> Associative array with page_id as key and translations as values
     */
    public function fetchTitleTranslationsForPages(array $pageIds, int $languageId): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('pft')
            ->select('p.id AS page_id, f.id AS field_id, f.name AS field_name, pft.content')
            ->leftJoin('pft.page', 'p')
            ->leftJoin('pft.field', 'f')
            ->leftJoin('pft.language', 'l')
            ->where('p.id IN (:pageIds)')
            ->andWhere('l.id = :languageId')
            ->andWhere('f.display = true') // Only display fields (title fields)
            ->setParameter('pageIds', $pageIds)
            ->setParameter('languageId', $languageId);

        /** @var list<array{page_id: int|string, field_id: int|string, field_name: string, content: mixed}> $results */
        $results = $qb->getQuery()->getResult();
        
        // Organize results by page_id
        $translations = [];
        foreach ($results as $result) {
            $pageId = $result['page_id'];
            if (!isset($translations[$pageId])) {
                $translations[$pageId] = [];
            }
            
            $translations[$pageId][$result['field_name']] = $result['content'];
        }
        
        return $translations;
    }

    /**
     * Fetch page PROPERTY field values (display = 0) by field name for a list of
     * pages, in one query. Property fields are non-translatable and normally
     * stored as a single row; when several language rows exist the lowest
     * language id wins deterministically, and an empty lower-language value is
     * superseded by a non-empty higher-language one.
     *
     * Used to project page property fields (e.g. `icon`, `mobile_icon`,
     * `icon`, `mobile_icon`, `search_visibility`) into the ACL-filtered page tree
     * without an N+1 per page and without touching the `get_user_acl` procedure.
     *
     * @param list<int> $pageIds Array of page IDs
     * @param list<string> $fieldNames Property field names to fetch
     * @return array<int, array<string, string|null>> page_id => [field_name => content]
     */
    public function fetchPropertyFieldsForPages(array $pageIds, array $fieldNames): array
    {
        if (empty($pageIds) || empty($fieldNames)) {
            return [];
        }

        $qb = $this->createQueryBuilder('pft')
            ->select('p.id AS page_id, f.name AS field_name, l.id AS language_id, pft.content')
            ->leftJoin('pft.page', 'p')
            ->leftJoin('pft.field', 'f')
            ->leftJoin('pft.language', 'l')
            ->where('p.id IN (:pageIds)')
            ->andWhere('f.name IN (:fieldNames)')
            ->setParameter('pageIds', $pageIds)
            ->setParameter('fieldNames', $fieldNames)
            ->orderBy('l.id', 'ASC');

        /** @var list<array{page_id: int|string, field_name: string, language_id: int|string|null, content: mixed}> $results */
        $results = $qb->getQuery()->getResult();

        $values = [];
        foreach ($results as $result) {
            $pageId = (int) $result['page_id'];
            $name = $result['field_name'];
            $content = $result['content'];
            if (is_string($content)) {
                $stringContent = $content;
            } elseif (is_scalar($content)) {
                $stringContent = (string) $content;
            } else {
                $stringContent = null;
            }

            if (!isset($values[$pageId])) {
                $values[$pageId] = [];
            }

            $existing = $values[$pageId][$name] ?? null;
            if (!array_key_exists($name, $values[$pageId]) || $existing === null || $existing === '') {
                $values[$pageId][$name] = $stringContent;
            }
        }

        return $values;
    }

    /**
     * Fetch every display-field text (title/description) of the given pages in
     * ALL languages, flattened per page. Used by the public search so a query
     * typed in any site language finds the page ("Impressum" also matches the
     * English UI), while the hit itself is still rendered in the current
     * language.
     *
     * @param list<int> $pageIds
     * @return array<int, list<string>> page_id => distinct non-empty texts across languages
     */
    public function fetchDisplayFieldTextsAllLanguages(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('pft')
            ->select('p.id AS page_id, pft.content')
            ->leftJoin('pft.page', 'p')
            ->leftJoin('pft.field', 'f')
            ->where('p.id IN (:pageIds)')
            ->andWhere('f.display = true')
            ->setParameter('pageIds', $pageIds);

        /** @var list<array{page_id: int|string, content: mixed}> $results */
        $results = $qb->getQuery()->getResult();

        $texts = [];
        foreach ($results as $result) {
            $content = $result['content'];
            if (!is_string($content) || trim($content) === '') {
                continue;
            }
            $pageId = (int) $result['page_id'];
            $texts[$pageId] ??= [];
            if (!in_array($content, $texts[$pageId], true)) {
                $texts[$pageId][] = $content;
            }
        }

        return $texts;
    }

    /**
     * Fetch the `title` field of the given pages in every language, keyed by
     * page and language. Lets admin pickers label pages in the admin's current
     * UI language without a per-language refetch.
     *
     * @param list<int> $pageIds
     * @return array<int, array<int, string>> page_id => [language_id => title]
     */
    public function fetchTitleByLanguageForPages(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('pft')
            ->select('p.id AS page_id, l.id AS language_id, pft.content')
            ->leftJoin('pft.page', 'p')
            ->leftJoin('pft.field', 'f')
            ->leftJoin('pft.language', 'l')
            ->where('p.id IN (:pageIds)')
            ->andWhere("f.name = 'title'")
            ->andWhere('f.display = true')
            ->setParameter('pageIds', $pageIds);

        /** @var list<array{page_id: int|string, language_id: int|string|null, content: mixed}> $results */
        $results = $qb->getQuery()->getResult();

        $titles = [];
        foreach ($results as $result) {
            $content = $result['content'];
            if (!is_string($content) || trim($content) === '' || $result['language_id'] === null) {
                continue;
            }
            $titles[(int) $result['page_id']][(int) $result['language_id']] = $content;
        }

        return $titles;
    }

    /**
     * Fetch page field translations with fallback to default language
     * Only fetches translations for fields with display=1 (title fields)
     *
     * Field-level merge: a primary-language translation only overrides the
     * default-language translation when its content is user-visibly non-empty.
     * This avoids empty rich-text wrappers (e.g. `<p></p>`,
     * `<p class="single-line-paragraph"></p>`) silently winning over the
     * default-language value. See {@see TranslationContentHelper::isEffectivelyEmpty()}.
     *
     * @param list<int> $pageIds Array of page IDs
     * @param int $languageId Primary language ID
     * @param int|null $defaultLanguageId Default language ID for fallback
     * @return array<int|string, array<string, mixed>> Associative array with page_id as key and translations as values
     */
    public function fetchTitleTranslationsWithFallback(array $pageIds, int $languageId, ?int $defaultLanguageId = null): array
    {
        if (empty($pageIds)) {
            return [];
        }

        // Get primary language translations
        $primaryTranslations = $this->fetchTitleTranslationsForPages($pageIds, $languageId);

        // If no default language or it's the same as primary, return primary translations
        if ($defaultLanguageId === null || $defaultLanguageId === $languageId) {
            return $primaryTranslations;
        }

        // Get default language translations for fallback
        $defaultTranslations = $this->fetchTitleTranslationsForPages($pageIds, $defaultLanguageId);

        // Merge translations with primary taking precedence at field level
        $mergedTranslations = [];
        foreach ($pageIds as $pageId) {
            $mergedTranslations[$pageId] = [];

            // Start with default translations
            if (isset($defaultTranslations[$pageId])) {
                $mergedTranslations[$pageId] = $defaultTranslations[$pageId];
            }

            // Override with primary translations only when content is user-visibly non-empty
            if (isset($primaryTranslations[$pageId])) {
                foreach ($primaryTranslations[$pageId] as $fieldName => $primaryContent) {
                    if (!TranslationContentHelper::isEffectivelyEmpty($primaryContent)) {
                        $mergedTranslations[$pageId][$fieldName] = $primaryContent;
                    }
                    // Else keep the default value already seeded above.
                }
            }
        }

        return $mergedTranslations;
    }
} 