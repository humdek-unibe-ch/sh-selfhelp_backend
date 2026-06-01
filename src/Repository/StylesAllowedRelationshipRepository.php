<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\StylesAllowedRelationship;
use App\Entity\Style;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StylesAllowedRelationship>
 */
class StylesAllowedRelationshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StylesAllowedRelationship::class);
    }

    /**
     * Get all allowed children for a specific parent style
     *
     * @return list<array<string, mixed>>
     */
    public function findAllowedChildren(Style $parentStyle): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = $this->createQueryBuilder('sar')
            ->select('s.id AS id', 's.name AS name')
            ->join('sar.childStyle', 's')
            ->where('sar.parentStyle = :parentStyle')
            ->setParameter('parentStyle', $parentStyle)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $result;
    }

    /**
     * Get all allowed parents for a specific child style
     *
     * @return list<array<string, mixed>>
     */
    public function findAllowedParents(Style $childStyle): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = $this->createQueryBuilder('sar')
            ->select('s.id AS id', 's.name AS name')
            ->join('sar.parentStyle', 's')
            ->where('sar.childStyle = :childStyle')
            ->setParameter('childStyle', $childStyle)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $result;
    }

    /**
     * Check if a specific parent-child relationship is allowed
     */
    public function isRelationshipAllowed(Style $parentStyle, Style $childStyle): bool
    {
        $count = $this->createQueryBuilder('sar')
            ->select('COUNT(sar)')
            ->where('sar.parentStyle = :parentStyle')
            ->andWhere('sar.childStyle = :childStyle')
            ->setParameter('parentStyle', $parentStyle)
            ->setParameter('childStyle', $childStyle)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Get relationship information for multiple styles at once
     *
     * @param list<int> $styleIds
     * @return array{allowedChildren: array<int|string, list<array<string, mixed>>>, allowedParents: array<int|string, list<array<string, mixed>>>}
     */
    public function getRelationshipsForStyles(array $styleIds): array
    {
        $relationships = [
            'allowedChildren' => [],
            'allowedParents' => []
        ];

        if (empty($styleIds)) {
            return $relationships;
        }

        // Get allowed children for all styles
        /** @var list<array{parent_id: int|string, child_id: int|string, child_name: string}> $childrenQuery */
        $childrenQuery = $this->createQueryBuilder('sar')
            ->select('IDENTITY(sar.parentStyle) AS parent_id', 's.id AS child_id', 's.name AS child_name')
            ->join('sar.childStyle', 's')
            ->where('sar.parentStyle IN (:styleIds)')
            ->setParameter('styleIds', $styleIds)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Get allowed parents for all styles
        /** @var list<array{child_id: int|string, parent_id: int|string, parent_name: string}> $parentsQuery */
        $parentsQuery = $this->createQueryBuilder('sar')
            ->select('IDENTITY(sar.childStyle) AS child_id', 's.id AS parent_id', 's.name AS parent_name')
            ->join('sar.parentStyle', 's')
            ->where('sar.childStyle IN (:styleIds)')
            ->setParameter('styleIds', $styleIds)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Organize by style ID
        foreach ($childrenQuery as $row) {
            $parentId = $row['parent_id'];
            if (!isset($relationships['allowedChildren'][$parentId])) {
                $relationships['allowedChildren'][$parentId] = [];
            }
            $relationships['allowedChildren'][$parentId][] = [
                'id' => $row['child_id'],
                'name' => $row['child_name']
            ];
        }

        foreach ($parentsQuery as $row) {
            $childId = $row['child_id'];
            if (!isset($relationships['allowedParents'][$childId])) {
                $relationships['allowedParents'][$childId] = [];
            }
            $relationships['allowedParents'][$childId][] = [
                'id' => $row['parent_id'],
                'name' => $row['parent_name']
            ];
        }

        return $relationships;
    }
}
