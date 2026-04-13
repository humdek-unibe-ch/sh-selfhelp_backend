<?php

namespace App\Service\Action;

use App\Entity\DataRow;
use App\Entity\DataTable;

/**
 * Immutable value object describing one action trigger event.
 *
 * The context is created after a data save/update/delete and then passed through
 * the action runtime so every service operates on the same normalized payload.
 */
final class ActionTriggerContext
{
    /**
     * @param array<string, mixed> $submittedValues
     *   The normalized submitted/extracted values associated with the trigger.
     */
    public function __construct(
        public readonly DataTable $dataTable,
        public readonly DataRow $dataRow,
        public readonly array $submittedValues,
        public readonly string $triggerType,
        public readonly ?int $userId,
        public readonly string $transactionBy
    ) {
    }
}
