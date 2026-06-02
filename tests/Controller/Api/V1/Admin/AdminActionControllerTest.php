<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\DataTable;
use App\Entity\Lookup;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional CRUD coverage for the admin Actions API (plan Phase 3 — actions).
 *
 * Complements {@see \App\Tests\Controller\Api\V1\Admin\ActionPermissionTest}
 * (the negative-permission matrix) with the create/read/update/delete contract
 * and the four trigger types (started/updated/finished/deleted). Acts as the
 * seeded qa.admin persona and writes only qa_-prefixed actions backed by a
 * qa_ data table; DAMA rolls every row back per test.
 */
final class AdminActionControllerTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private int $dataTableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->dataTableId = $this->resolveQaDataTableId();
    }

    private function resolveQaDataTableId(): int
    {
        $name = 'qa_action_ctrl_table';
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $name]);
        if (!$table instanceof DataTable) {
            $table = new DataTable();
            $table->setName($name);
            $table->setDisplayName('QA action controller table');
            $table->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->em->persist($table);
            $this->em->flush();
        }

        return (int) $table->getId();
    }

    private function triggerTypeId(string $code): int
    {
        $lookup = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::ACTION_TRIGGER_TYPES,
            'lookupCode' => $code,
        ]);
        self::assertInstanceOf(Lookup::class, $lookup, "Missing actionTriggerTypes lookup '{$code}'. Run: composer test:reset-db");

        return (int) $lookup->getId();
    }

    /**
     * @return array<string, mixed> the created action's `data` payload
     */
    private function createAction(string $slug, string $triggerCode = LookupService::ACTION_TRIGGER_TYPES_FINISHED): array
    {
        $payload = [
            'name' => 'qa_action_' . $slug . '_' . uniqid('', false),
            'id_action_trigger_types' => $this->triggerTypeId($triggerCode),
            'id_data_tables' => $this->dataTableId,
            'config' => ['blocks' => []],
        ];

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/actions', $payload, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope, Response::HTTP_CREATED);
        self::assertArrayHasKey('id', $data, 'Created action must return an id');

        return $data;
    }

    public function testCreateActionPersistsAllTriggerTypes(): void
    {
        $triggers = [
            LookupService::ACTION_TRIGGER_TYPES_STARTED,
            LookupService::ACTION_TRIGGER_TYPES_UPDATED,
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            LookupService::ACTION_TRIGGER_TYPES_DELETED,
        ];

        foreach ($triggers as $code) {
            $data = $this->createAction('trigger_' . $code, $code);

            self::assertStringStartsWith('qa_action_trigger_' . $code, $data['name']);
            self::assertSame($this->dataTableId, $data['data_table']['id'] ?? null);
            self::assertSame(
                $code,
                $data['action_trigger_type']['lookup_code'] ?? null,
                "Created action should echo the {$code} trigger type"
            );
        }
    }

    public function testGetActionByIdReturnsDetail(): void
    {
        $created = $this->createAction('detail');
        $actionId = (int) $created['id'];

        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/actions/' . $actionId, null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertSame($actionId, $data['id']);
        self::assertSame($created['name'], $data['name']);
        self::assertArrayHasKey('action_trigger_type', $data);
        self::assertArrayHasKey('data_table', $data);
    }

    public function testListActionsIncludesCreatedAction(): void
    {
        $created = $this->createAction('list');
        $name = (string) $created['name'];

        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/actions?pageSize=50&search=' . rawurlencode($name),
            null,
            $this->loginAsQaAdmin()
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('actions', $data);
        $names = array_column($data['actions'], 'name');
        self::assertContains($name, $names, 'Created action must appear in the filtered list');
    }

    public function testUpdateActionChangesName(): void
    {
        $created = $this->createAction('update');
        $actionId = (int) $created['id'];
        $newName = 'qa_action_update_changed_' . uniqid('', false);

        $envelope = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/actions/' . $actionId,
            ['name' => $newName],
            $this->loginAsQaAdmin()
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertSame($actionId, $data['id']);
        self::assertSame($newName, $data['name']);
    }

    public function testDeleteActionRemovesIt(): void
    {
        $created = $this->createAction('delete');
        $actionId = (int) $created['id'];
        $token = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('DELETE', '/cms-api/v1/admin/actions/' . $actionId, null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertTrue($data['deleted'] ?? null);

        $missing = $this->jsonRequest('GET', '/cms-api/v1/admin/actions/' . $actionId, null, $token);
        $this->assertEnvelope404($missing);
    }

    public function testCreateActionWithMissingRequiredFieldsIsRejected(): void
    {
        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/actions',
            ['name' => 'qa_action_invalid'],
            $this->loginAsQaAdmin()
        );

        $this->assertEnvelope400($envelope);
    }

    public function testGetUnknownActionReturnsNotFound(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/actions/999999', null, $this->loginAsQaAdmin());

        $this->assertEnvelope404($envelope);
    }
}
