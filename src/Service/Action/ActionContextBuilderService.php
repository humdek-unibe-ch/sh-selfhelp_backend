<?php

namespace App\Service\Action;

use App\Entity\DataRow;
use App\Entity\DataTable;

/**
 * Builds the normalized trigger context passed into the action runtime.
 *
 * Normalization adds convenience fields such as `record_id`, `id_users`, and
 * `trigger_type` so interpolation and recipient resolution can use a stable payload.
 */
class ActionContextBuilderService
{
    /**
     * @param array<string, mixed> $submittedValues
     *   The submitted or extracted values associated with the trigger event.
     *
     * @return ActionTriggerContext
     *   The normalized action trigger context used by the orchestrator.
     */
    public function build(
        DataTable $dataTable,
        DataRow $dataRow,
        array $submittedValues,
        string $triggerType,
        ?int $userId,
        string $transactionBy
    ): ActionTriggerContext {
        $normalizedValues = $submittedValues;
        $normalizedValues['record_id'] = $dataRow->getId();
        $normalizedValues['id_users'] = $userId;
        $normalizedValues['trigger_type'] = $triggerType;

        return new ActionTriggerContext(
            $dataTable,
            $dataRow,
            $normalizedValues,
            $triggerType,
            $userId,
            $transactionBy
        );
    }
}
