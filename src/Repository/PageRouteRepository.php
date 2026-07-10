<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\PageRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageRoute>
 */
class PageRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageRoute::class);
    }

    /**
     * Return every active route joined with its page keyword as a plain list,
     * ready for {@see \App\Routing\PageRouteResolverService} to build a Symfony
     * RouteCollection. Ordering (static-before-dynamic + priority) is applied by
     * the resolver to keep this query simple and cacheable.
     *
     * @return list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}>
     */
    public function findActiveRoutesForResolver(): array
    {
        /** @var list<array{id:int|string, page_id:int|string, keyword:string, path_pattern:string, requirements:array<array-key, mixed>|null, is_canonical:bool|int, priority:int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select(
                'r.id AS id',
                'IDENTITY(r.page) AS page_id',
                'p.keyword AS keyword',
                'r.pathPattern AS path_pattern',
                'r.requirements AS requirements',
                'r.isCanonical AS is_canonical',
                'r.priority AS priority'
            )
            ->innerJoin('r.page', 'p')
            ->where('r.isActive = true')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $requirements = [];
            if (is_array($row['requirements'])) {
                foreach ($row['requirements'] as $key => $value) {
                    if (is_string($value)) {
                        $requirements[(string) $key] = $value;
                    }
                }
            }
            $result[] = [
                'id' => (int) $row['id'],
                'page_id' => (int) $row['page_id'],
                'keyword' => $row['keyword'],
                'path_pattern' => $row['path_pattern'],
                'requirements' => $requirements,
                'is_canonical' => (bool) $row['is_canonical'],
                'priority' => (int) $row['priority'],
            ];
        }

        return $result;
    }

    /**
     * All routes for a page (active and inactive), ordered canonical-first then
     * by priority. Used by the admin editor, export, and canonical-URL lookups.
     *
     * @return list<PageRoute>
     */
    public function findByPageId(int $pageId): array
    {
        /** @var list<PageRoute> $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.page = :pageId')
            ->setParameter('pageId', $pageId)
            ->orderBy('r.isCanonical', 'DESC')
            ->addOrderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The canonical active route for a page, if any.
     */
    public function findCanonicalForPage(int $pageId): ?PageRoute
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.page = :pageId')
            ->andWhere('r.isCanonical = true')
            ->andWhere('r.isActive = true')
            ->setParameter('pageId', $pageId)
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof PageRoute ? $result : null;
    }

    /**
     * Active routes whose exact `path_pattern` equals the given value, optionally
     * excluding one page. Drives the global duplicate check in
     * {@see \App\Routing\RouteConflictValidator}.
     *
     * @return list<PageRoute>
     */
    public function findActiveByExactPattern(string $pathPattern, ?int $excludePageId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.pathPattern = :pattern')
            ->andWhere('r.isActive = true')
            ->setParameter('pattern', $pathPattern);

        if ($excludePageId !== null) {
            $qb->andWhere('r.page <> :excludePageId')
                ->setParameter('excludePageId', $excludePageId);
        }

        /** @var list<PageRoute> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Every active pattern (path + the owning page keyword), for ambiguity
     * detection in {@see \App\Routing\RouteConflictValidator}.
     *
     * @return list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, priority:int}>
     */
    public function findAllActivePatterns(?int $excludePageId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select(
                'r.id AS id',
                'IDENTITY(r.page) AS page_id',
                'p.keyword AS keyword',
                'r.pathPattern AS path_pattern',
                'r.requirements AS requirements',
                'r.priority AS priority'
            )
            ->innerJoin('r.page', 'p')
            ->where('r.isActive = true');

        if ($excludePageId !== null) {
            $qb->andWhere('r.page <> :excludePageId')
                ->setParameter('excludePageId', $excludePageId);
        }

        /** @var list<array{id:int|string, page_id:int|string, keyword:string, path_pattern:string, requirements:array<array-key, mixed>|null, priority:int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $requirements = [];
            if (is_array($row['requirements'])) {
                foreach ($row['requirements'] as $key => $value) {
                    if (is_string($value)) {
                        $requirements[(string) $key] = $value;
                    }
                }
            }
            $result[] = [
                'id' => (int) $row['id'],
                'page_id' => (int) $row['page_id'],
                'keyword' => $row['keyword'],
                'path_pattern' => $row['path_pattern'],
                'requirements' => $requirements,
                'priority' => (int) $row['priority'],
            ];
        }

        return $result;
    }
}
