<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Service\Action\ActionContextBuilderService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the trigger-context normalizer.
 */
final class ActionContextBuilderServiceTest extends TestCase
{
    public function testBuildInjectsRecordIdUserIdAndTriggerTypeIntoSubmittedValues(): void
    {
        $dataRow = $this->createStub(DataRow::class);
        $dataRow->method('getId')->willReturn(777);
        $dataTable = $this->createStub(DataTable::class);

        $context = (new ActionContextBuilderService())->build(
            $dataTable,
            $dataRow,
            ['qa_answer' => 'value'],
            'finished',
            42,
            'by_user',
        );

        self::assertSame('value', $context->submittedValues['qa_answer']);
        self::assertSame(777, $context->submittedValues['record_id']);
        self::assertSame(42, $context->submittedValues['id_users']);
        self::assertSame('finished', $context->submittedValues['trigger_type']);
        self::assertSame('finished', $context->triggerType);
        self::assertSame(42, $context->userId);
        self::assertSame('by_user', $context->transactionBy);
    }

    public function testBuildKeepsNullUserIdInNormalizedValues(): void
    {
        $dataRow = $this->createStub(DataRow::class);
        $dataRow->method('getId')->willReturn(5);

        $context = (new ActionContextBuilderService())->build(
            $this->createStub(DataTable::class),
            $dataRow,
            [],
            'deleted',
            null,
            'by_system',
        );

        self::assertArrayHasKey('id_users', $context->submittedValues);
        self::assertNull($context->submittedValues['id_users']);
        self::assertSame(5, $context->submittedValues['record_id']);
    }
}
