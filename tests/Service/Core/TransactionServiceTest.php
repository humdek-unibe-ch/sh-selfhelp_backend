<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\Entity\Transaction;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see TransactionService::logTransaction()} — the
 * single audit-trail writer used across data-changing operations (plan Phase 8:
 * transaction record creation and metadata).
 */
final class TransactionServiceTest extends QaKernelTestCase
{
    private TransactionService $transactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactions = $this->service(TransactionService::class);
    }

    public function testLogTransactionPersistsRowWithMetadata(): void
    {
        $tx = $this->transactions->logTransaction(
            LookupService::TRANSACTION_TYPES_INSERT,
            LookupService::TRANSACTION_BY_BY_USER,
            'qa_transaction_table',
            4242,
            false,
            'qa transaction verbal log',
        );

        self::assertInstanceOf(Transaction::class, $tx);
        self::assertNotNull($tx->getId(), 'Transaction must be persisted (have an id).');
        self::assertSame('qa_transaction_table', $tx->getTableName());
        self::assertSame(4242, $tx->getIdTableName());
        self::assertNotNull($tx->getIdTransactionTypes(), 'Transaction type lookup must be linked.');
        self::assertNotNull($tx->getIdTransactionBy(), 'Transaction-by lookup must be linked.');
        self::assertStringContainsString('qa transaction verbal log', (string) $tx->getTransactionLog());

        // Public side effect: the row is reloadable from the DB.
        $reloaded = $this->em->getRepository(Transaction::class)->find($tx->getId());
        self::assertInstanceOf(Transaction::class, $reloaded);
        self::assertSame('qa_transaction_table', $reloaded->getTableName());
    }

    public function testDefaultVerbalLogIsGeneratedWhenNoneProvided(): void
    {
        $tx = $this->transactions->logTransaction(
            LookupService::TRANSACTION_TYPES_UPDATE,
            LookupService::TRANSACTION_BY_BY_USER,
            'qa_transaction_table',
            7,
        );

        self::assertStringContainsString('Transaction type:', (string) $tx->getTransactionLog());
        self::assertStringContainsString('qa_transaction_table', (string) $tx->getTransactionLog());
    }
}
