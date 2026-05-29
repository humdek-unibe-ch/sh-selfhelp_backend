<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Find transactions by table name and ID
     *
     * @return Transaction[]
     */
    public function findByTableAndId(string $tableName, int $id): array
    {
        return $this->findBy([
            'tableName' => $tableName,
            'idTableName' => $id
        ], ['transactionTime' => 'DESC']);
    }

    /**
     * Find transactions by user
     *
     * @return list<Transaction>
     */
    public function findByUser(int $userId): array
    {
        /** @var list<Transaction> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('t.transactionTime', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find transactions by type
     *
     * @return list<Transaction>
     */
    public function findByTransactionType(string $type): array
    {
        /** @var list<Transaction> $result */
        $result = $this->createQueryBuilder('t')
            ->join('t.transactionType', 'tt')
            ->andWhere('tt.lookupValue = :type')
            ->setParameter('type', $type)
            ->orderBy('t.transactionTime', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
} 