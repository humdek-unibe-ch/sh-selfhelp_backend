<?php

namespace App\Repository;

use App\Entity\ActionTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionTranslation>
 */
class ActionTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionTranslation::class);
    }

    /**
     * Find translations by action ID and optionally language ID
     */
    public function findByActionId(int $actionId, ?int $languageId = null): array
    {
        $qb = $this->createQueryBuilder('at')
            ->leftJoin('at.language', 'l')
            ->addSelect('l')
            ->andWhere('at.action = :actionId')
            ->setParameter('actionId', $actionId);

        if ($languageId !== null) {
            $qb->andWhere('at.language = :languageId')
                ->setParameter('languageId', $languageId);
        }

        return $qb->orderBy('at.translationKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find translation by action, key, and language
     */
    public function findByActionKeyAndLanguage(int $actionId, string $translationKey, int $languageId): ?ActionTranslation
    {
        return $this->createQueryBuilder('at')
            ->andWhere('at.action = :actionId')
            ->andWhere('at.translationKey = :translationKey')
            ->andWhere('at.language = :languageId')
            ->setParameter('actionId', $actionId)
            ->setParameter('translationKey', $translationKey)
            ->setParameter('languageId', $languageId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find translation by action ID and translation ID
     */
    public function findOneByActionAndId(int $actionId, int $translationId): ?ActionTranslation
    {
        return $this->createQueryBuilder('at')
            ->andWhere('at.action = :actionId')
            ->andWhere('at.id = :translationId')
            ->setParameter('actionId', $actionId)
            ->setParameter('translationId', $translationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find existing translation keys by action and language
     */
    public function findKeysByActionAndLanguage(int $actionId, int $languageId): array
    {
        return $this->createQueryBuilder('at')
            ->select('at.translationKey')
            ->andWhere('at.action = :actionId')
            ->andWhere('at.language = :languageId')
            ->setParameter('actionId', $actionId)
            ->setParameter('languageId', $languageId)
            ->orderBy('at.translationKey', 'ASC')
            ->getQuery()
            ->getScalarResult();
    }
}
