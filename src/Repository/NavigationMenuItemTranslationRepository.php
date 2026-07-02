<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

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
     * @param list<int> $menuItemIds
     *
     * @return array<int, array<int, string>> menu item id → language id → label
     */
    public function findLabelsByMenuItemIds(array $menuItemIds): array
    {
        if ($menuItemIds === []) {
            return [];
        }

        /** @var list<array{menuItemId: int|string, languageId: int|string, label: string|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.menuItem) AS menuItemId', 'IDENTITY(t.language) AS languageId', 't.label')
            ->andWhere('t.menuItem IN (:ids)')
            ->setParameter('ids', $menuItemIds)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $label = $row['label'];
            if (!is_string($label) || $label === '') {
                continue;
            }
            $menuItemId = (int) $row['menuItemId'];
            $languageId = (int) $row['languageId'];
            $out[$menuItemId][$languageId] = $label;
        }

        return $out;
    }

    /**
     * @param list<int> $menuItemIds
     *
     * @return array<int, list<array{language_id: int, locale: string, label: ?string, description: ?string, aria_label: ?string}>>
     */
    public function findPortableTranslationsByMenuItemIds(array $menuItemIds): array
    {
        if ($menuItemIds === []) {
            return [];
        }

        /** @var list<NavigationMenuItemTranslation> $rows */
        $rows = $this->createQueryBuilder('t')
            ->addSelect('l')
            ->join('t.language', 'l')
            ->andWhere('t.menuItem IN (:ids)')
            ->setParameter('ids', $menuItemIds)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $itemId = $row->getMenuItem()?->getId();
            if ($itemId === null) {
                continue;
            }
            $locale = $row->getLanguage()?->getLocale() ?? '';
            $out[$itemId][] = [
                'language_id' => $row->getLanguage()?->getId() ?? 0,
                'locale' => $locale,
                'label' => $row->getLabel(),
                'description' => $row->getDescription(),
                'aria_label' => $row->getAriaLabel(),
            ];
        }

        foreach ($out as &$translations) {
            usort($translations, static fn (array $a, array $b): int => $a['language_id'] <=> $b['language_id']);
        }
        unset($translations);

        return $out;
    }

    /**
     * @return list<NavigationMenuItemTranslation>
     */
    public function findByMenuItemId(int $menuItemId): array
    {
        /** @var list<NavigationMenuItemTranslation> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.menuItem = :id')
            ->setParameter('id', $menuItemId)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
