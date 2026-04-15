<?php

namespace App\Service\Action;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Repository\ActionRepository;

/**
 * Resolves the actions that should run for a given table/trigger combination.
 */
class ActionResolverService
{
    public function __construct(
        private readonly ActionRepository $actionRepository
    ) {
    }

    /**
     * Load actions attached to a data table and trigger code.
     *
     * @param DataTable $dataTable
     *   The source data table for the trigger event.
     * @param string $triggerType
     *   The action-trigger lookup code such as `finished`, `updated`, or `deleted`.
     *
     * @return Action[]
     *   The actions that should be evaluated by the orchestrator.
     */
    public function resolve(DataTable $dataTable, string $triggerType): array
    {
        return $this->actionRepository->findByDataTableAndTrigger($dataTable, $triggerType);
    }
}
