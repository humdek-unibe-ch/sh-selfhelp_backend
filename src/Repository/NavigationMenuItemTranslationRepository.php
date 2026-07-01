<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\NavigationMenuItem;
use App\Entity\NavigationMenuItemTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NavigationMenuItemTranslation>
 */
class NavigationMenuItemTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NavigationMenuItemTranslation::class);
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, array<int, array{label: ?string, description: ?string, aria_label: ?string}>>
     */
    public function fetchTranslationsForItems(array $itemIds, int $languageId, ?int $fallbackLanguageId): array
    {
        if ($itemIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.navigationMenuItem) AS item_id', 'IDENTITY(t.language) AS language_id', 't.label', 't.description', 't.ariaLabel AS aria_label')
            ->andWhere('t.navigationMenuItem IN (:ids)')
            ->setParameter('ids', $itemIds);

        $languageIds = array_values(array_unique(array_filter([$languageId, $fallbackLanguageId])));
        if ($languageIds !== []) {
            $qb->andWhere('t.language IN (:langs)')->setParameter('langs', $languageIds);
        }

        /** @var list<array{item_id:int|string, language_id:int|string, label:?string, description:?string, aria_label:?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $langId = (int) $row['language_id'];
            $out[$itemId][$langId] = [
                'label' => $row['label'],
                'description' => $row['description'],
                'aria_label' => $row['aria_label'],
            ];
        }

        return $out;
    }

    public function resolveLabelForItem(
        NavigationMenuItem $item,
        int $languageId,
        ?int $fallbackLanguageId,
        ?string $pageTitle,
    ): string {
        $presentation = $this->resolvePresentationForItem(
            $item,
            $languageId,
            $fallbackLanguageId,
            $pageTitle,
        );

        return $presentation['label'];
    }

    /**
     * @param array<int, array<int, array{label: ?string, description: ?string, aria_label: ?string}>> $translationsByItem
     *
     * @return array{label: string, description: ?string, aria_label: ?string}
     */
    public function resolvePresentationFromMap(
        NavigationMenuItem $item,
        array $translationsByItem,
        int $languageId,
        ?int $fallbackLanguageId,
        ?string $pageTitle,
    ): array {
        $row = $this->pickTranslationRow($translationsByItem, (int) $item->getId(), $languageId, $fallbackLanguageId);
        $label = $row['label'] ?? null;
        if ($label === null || $label === '') {
            if ($pageTitle !== null && $pageTitle !== '') {
                $label = $pageTitle;
            } else {
                $label = $item->getPage()?->getKeyword() ?? '';
            }
        }

        return [
            'label' => $label,
            'description' => $this->nullableString($row['description'] ?? null),
            'aria_label' => $this->nullableString($row['aria_label'] ?? null),
        ];
    }

    /**
     * @return array{label: string, description: ?string, aria_label: ?string}
     */
    public function resolvePresentationForItem(
        NavigationMenuItem $item,
        int $languageId,
        ?int $fallbackLanguageId,
        ?string $pageTitle,
    ): array {
        $map = $this->fetchTranslationsForItems([(int) $item->getId()], $languageId, $fallbackLanguageId);

        return $this->resolvePresentationFromMap($item, $map, $languageId, $fallbackLanguageId, $pageTitle);
    }

    /**
     * @param array<int, array<int, array{label: ?string, description: ?string, aria_label: ?string}>> $translationsByItem
     *
     * @return array{label: ?string, description: ?string, aria_label: ?string}
     */
    private function pickTranslationRow(
        array $translationsByItem,
        int $itemId,
        int $languageId,
        ?int $fallbackLanguageId,
    ): array {
        $empty = ['label' => null, 'description' => null, 'aria_label' => null];
        $byLang = $translationsByItem[$itemId] ?? [];
        if (isset($byLang[$languageId])) {
            return $byLang[$languageId];
        }
        if ($fallbackLanguageId !== null && isset($byLang[$fallbackLanguageId])) {
            return $byLang[$fallbackLanguageId];
        }

        return $empty;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
