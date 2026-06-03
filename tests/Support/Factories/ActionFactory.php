<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\Lookup;
use App\Service\Action\ActionConfig;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds QA actions (and their backing data table) for golden/integration tests.
 *
 * Everything produced is `qa_`-prefixed (plan §7 test-data naming) and persisted
 * through the real EntityManager so the action runtime resolves it exactly as it
 * would resolve an admin-created action. Tests run inside the DAMA transaction,
 * so all rows created here are rolled back automatically.
 *
 * The config produced matches the real admin JSON contract consumed by
 * {@see \App\Service\Action\ActionConfigRuntimeService}: blocks -> jobs ->
 * schedule_time/notification.
 */
final class ActionFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Create (or reuse) a `qa_`-named data table. The name doubles as the
     * "table name" argument passed to {@see \App\Service\CMS\DataService::saveData()},
     * which is how the data layer locates the table the action is attached to.
     */
    public function createDataTable(string $name = 'qa_form_action'): DataTable
    {
        $existing = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $name]);
        if ($existing instanceof DataTable) {
            return $existing;
        }

        $dataTable = new DataTable();
        $dataTable->setName($name);
        $dataTable->setDisplayName('QA form action table');
        $dataTable->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->em->persist($dataTable);
        $this->em->flush();

        return $dataTable;
    }

    /**
     * Attach an action to a data table for a given trigger.
     *
     * @param array<string, mixed> $config decoded action config (will be JSON-encoded)
     */
    public function createAction(
        DataTable $dataTable,
        string $triggerCode = LookupService::ACTION_TRIGGER_TYPES_FINISHED,
        array $config = [],
        string $name = 'qa_form_action',
    ): Action {
        $action = new Action();
        $action->setName($name);
        $action->setDataTable($dataTable);
        $action->setActionTriggerType($this->triggerLookup($triggerCode));
        $action->setConfig((string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    /**
     * Convenience: create a data table + a "finished" trigger action that sends
     * one immediate email to the submitting user. This is the canonical golden
     * fixture for the form -> action -> scheduled-job chain.
     */
    public function createImmediateEmailAction(
        string $tableName = 'qa_form_action',
        string $subject = 'QA action email',
        string $body = 'QA action email body',
    ): Action {
        $dataTable = $this->createDataTable($tableName);

        return $this->createAction(
            $dataTable,
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            self::immediateEmailConfig($subject, $body),
        );
    }

    /**
     * The decoded config for a single immediate email job addressed to the
     * triggering user (`@user` is replaced with the recipient email by
     * {@see \App\Service\Action\ActionSchedulerService}).
     *
     * @return array<string, mixed>
     */
    public static function immediateEmailConfig(string $subject, string $body): array
    {
        return [
            ActionConfig::BLOCKS => [
                [
                    ActionConfig::JOBS => [
                        [
                            ActionConfig::JOB_NAME => 'qa_form_action_email',
                            ActionConfig::JOB_TYPE => '',
                            ActionConfig::SCHEDULE_TIME => [
                                ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY,
                            ],
                            ActionConfig::NOTIFICATION => [
                                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                                ActionConfig::RECIPIENT => '@user',
                                ActionConfig::SUBJECT => $subject,
                                ActionConfig::BODY => $body,
                                ActionConfig::FROM_EMAIL => 'qa-noreply@selfhelp.test',
                                ActionConfig::FROM_NAME => 'QA',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function triggerLookup(string $triggerCode): Lookup
    {
        $lookup = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::ACTION_TRIGGER_TYPES,
            'lookupCode' => $triggerCode,
        ]);

        if (!$lookup instanceof Lookup) {
            throw new \RuntimeException(sprintf(
                'Missing actionTriggerTypes lookup "%s". Run: composer test:reset-db',
                $triggerCode
            ));
        }

        return $lookup;
    }
}
