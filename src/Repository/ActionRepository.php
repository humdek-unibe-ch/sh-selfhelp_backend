<?php

namespace App\Repository;

use App\Entity\Action;
use App\Entity\DataTable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Action>
 */
class ActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Action::class);
    }

    /**
     * Find actions with pagination and optional search/sort
     *
     * @param int $page
     *   The requested page number.
     * @param int $pageSize
     *   The requested page size.
     * @param string|null $search
     *   Optional search term applied to action name and trigger labels.
     * @param string|null $sort
     *   Optional sort key.
     * @param string $sortDirection
     *   Requested sort direction.
     *
     * @return array{actions: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     *   Formatted action rows plus pagination metadata for admin listing.
     */
    public function findActionsWithPagination(
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        ?string $sort = null,
        string $sortDirection = 'asc'
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.actionTriggerType', 'att')
            ->addSelect('att')
            ->leftJoin('a.dataTable', 'dt')
            ->addSelect('dt');

        if ($search) {
            $qb->andWhere('a.name LIKE :search OR att.lookupValue LIKE :search OR att.lookupCode LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Sorting whitelist
        $allowedSorts = [
            'id' => 'a.id',
            'name' => 'a.name',
        ];
        $sortField = $allowedSorts[$sort ?? 'id'] ?? 'a.id';
        $direction = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';

        // Total count
        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $totalPages = (int) ceil($total / max(1, $pageSize));
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        $records = $qb
            ->orderBy($sortField, $direction)
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $actions = array_map(static function (Action $action): array {
            $trigger = $action->getActionTriggerType();
            $dataTable = $action->getDataTable();
            return [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'action_trigger_type' => $trigger ? [
                    'id' => $trigger->getId(),
                    'type_code' => $trigger->getTypeCode(),
                    'lookup_code' => $trigger->getLookupCode(),
                    'lookup_value' => $trigger->getLookupValue(),
                ] : null,
                'data_table' => $dataTable ? [
                    'id' => $dataTable->getId(),
                    'name' => $dataTable->getName(),
                    'displayName' => $dataTable->getDisplayName(),
                ] : null,
            ];
        }, $records);

        return [
            'actions' => $actions,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalCount' => $total,
                'totalPages' => $totalPages,
                'hasNext' => $page < $totalPages,
                'hasPrevious' => $page > 1,
            ],
        ];
    }

    /**
     * Find actions attached to a specific data table and trigger type.
     *
     * @param DataTable $dataTable
     *   The data table whose actions should be loaded.
     * @param string $triggerTypeCode
     *   The action-trigger lookup code.
     *
     * @return Action[]
     *   Matching actions ordered by id.
     */
    public function findByDataTableAndTrigger(DataTable $dataTable, string $triggerTypeCode): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.actionTriggerType', 'att')
            ->addSelect('att')
            ->leftJoin('a.dataTable', 'dt')
            ->addSelect('dt')
            ->andWhere('a.dataTable = :dataTable')
            ->andWhere('att.lookupCode = :triggerTypeCode')
            ->setParameter('dataTable', $dataTable)
            ->setParameter('triggerTypeCode', $triggerTypeCode)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}


