<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Humdek\QaLiveBundle\Entity\QaLiveItem;

/** @extends ServiceEntityRepository<QaLiveItem> */
class QaLiveItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QaLiveItem::class);
    }

    /** @return list<QaLiveItem> */
    public function listAll(): array
    {
        $result = $this->createQueryBuilder('i')
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $items = [];
        if (!is_array($result)) {
            return $items;
        }
        foreach ($result as $row) {
            if ($row instanceof QaLiveItem) {
                $items[] = $row;
            }
        }

        return $items;
    }
}
