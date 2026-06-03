<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Service\Action\ActionTriggerContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the immutable action trigger context value object.
 */
final class ActionTriggerContextTest extends TestCase
{
    public function testContextExposesNormalizedTriggerPayloadAsReadonly(): void
    {
        $dataTable = $this->createStub(DataTable::class);
        $dataRow = $this->createStub(DataRow::class);

        $context = new ActionTriggerContext(
            $dataTable,
            $dataRow,
            ['qa_answer' => 'value'],
            'finished',
            42,
            'by_user',
        );

        self::assertSame($dataTable, $context->dataTable);
        self::assertSame($dataRow, $context->dataRow);
        self::assertSame(['qa_answer' => 'value'], $context->submittedValues);
        self::assertSame('finished', $context->triggerType);
        self::assertSame(42, $context->userId);
        self::assertSame('by_user', $context->transactionBy);
    }

    public function testContextAllowsNullUserForSystemTriggers(): void
    {
        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            [],
            'deleted',
            null,
            'by_system',
        );

        self::assertNull($context->userId);
        self::assertSame([], $context->submittedValues);
    }
}
