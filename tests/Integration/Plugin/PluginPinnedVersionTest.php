<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Resource\PluginResourceServiceInterface;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * C19: `PluginResourceServiceInterface::pinnedVersion()` tells a plugin which version of an item a participant's
 * unfinished record was begun on, so the plugin keeps showing that version while the item moves on.
 *
 * The records are written through the real write path with `withContentRef('res:qa_item:<id>@v<n>')`, owned
 * by the user the test names and in the trigger state the test needs (`started`, `updated`, `finished`, deleted).
 */
#[PhpUnitGroup('security')]
final class PluginPinnedVersionTest extends QaKernelTestCase
{
    private const TABLE = 'qa_pin_table';

    private PluginResourceServiceInterface $resources;
    private DataService $data;
    private Connection $db;
    private int $participant;
    private int $stranger;
    private int $tableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resources = $this->service(PluginResourceServiceInterface::class);
        $this->data = $this->service(DataService::class);
        $this->db = $this->em->getConnection();
        $this->participant = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $this->stranger = $this->userId(QaBaselineFixture::QA_EDITOR_EMAIL);

        QaItemPluginFixture::install($this->em, $this->service(PluginCatalogueSynchronizer::class), $this->service(PluginCacheInvalidator::class));

        // The table exists before the first record: a participant's records of it are what pin.
        $this->tableId = $this->tableIdOf($this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_FINISHED, null, table: self::TABLE));
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testAnUnfinishedRecordPinsTheVersionItWasBegunOn(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');

        self::assertSame(2, $this->pinned($this->participant, 5));
    }

    public function testAnUpdatedRecordStillPins(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_UPDATED, 'res:qa_item:5@v4');

        self::assertSame(4, $this->pinned($this->participant, 5));
    }

    public function testAFinishedRecordPinsNothing(): void
    {
        $recordId = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');
        self::assertSame(2, $this->pinned($this->participant, 5), 'it pins while it is unfinished');

        // The participant finishes: the plugin writes the same record again with the trigger `finished`.
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_FINISHED, 'res:qa_item:5@v2', $recordId);

        self::assertNull($this->pinned($this->participant, 5), 'after that record is finished the participant is served the newest published version again');
    }

    public function testADeletedRecordPinsNothing(): void
    {
        $recordId = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');
        self::assertSame(2, $this->pinned($this->participant, 5));

        $this->data->deleteData($this->tableId, $recordId, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));

        self::assertNull($this->pinned($this->participant, 5));
    }

    public function testAnotherUsersRecordPinsNothingForThisUser(): void
    {
        $this->write($this->stranger, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v3');

        self::assertNull($this->pinned($this->participant, 5), 'the participant has no record of their own');
        self::assertSame(3, $this->pinned($this->stranger, 5), 'it pins the user it belongs to');
    }

    public function testAGuestIsNeverPinned(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');

        self::assertNull($this->pinned(0, 5));
        self::assertNull($this->pinned(-1, 5));
    }

    public function testTheNewestOfTwoUnfinishedRecordsPins(): void
    {
        $older = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v1');
        $newer = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v3');
        // Same second or not, the order is the order they were saved in.
        $this->setTimestamp($older, '2026-01-01 10:00:00');
        $this->setTimestamp($newer, '2026-01-01 11:00:00');
        self::assertSame(3, $this->pinned($this->participant, 5));

        // Saving the older record again makes it the newest one.
        $this->setTimestamp($older, '2026-01-01 12:00:00');
        self::assertSame(1, $this->pinned($this->participant, 5));
    }

    public function testTheNewestRecordWinsWhenTwoWereSavedInTheSameSecond(): void
    {
        $first = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v1');
        $second = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');
        $this->setTimestamp($first, '2026-01-01 10:00:00');
        $this->setTimestamp($second, '2026-01-01 10:00:00');

        self::assertSame(2, $this->pinned($this->participant, 5), 'the higher record id');
    }

    public function testOnlyTheItemTheRecordNamesIsPinned(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:50@v7');

        self::assertNull($this->pinned($this->participant, 5), 'item 5 is not item 50');
        self::assertNull($this->pinned($this->participant, 500));
        self::assertSame(7, $this->pinned($this->participant, 50));
    }

    public function testAnotherTypeWhoseNameOnlyLooksLikeThisOneIsNotPinned(): void
    {
        $recordId = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');
        // An underscore of the type is no wildcard: a record of the type `qaxitem` is not a record of `qa_item`.
        $this->db->executeStatement('UPDATE data_rows SET content_ref = :ref WHERE id = :id', ['ref' => 'res:qaxitem:5@v9', 'id' => $recordId]);

        self::assertNull($this->pinned($this->participant, 5));
    }

    public function testARecordOfAnotherTableDoesNotPin(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2', table: 'qa_pin_other_table');

        self::assertNull($this->pinned($this->participant, 5));
    }

    public function testAPageReferenceDoesNotPinAnItem(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'page:5@v2');

        self::assertNull($this->pinned($this->participant, 5));
    }

    public function testAJobThatWritesTheRecordAgainDoesNotLoseThePin(): void
    {
        $recordId = $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');

        // A scheduled job touches the record and names no content: the record keeps what it was begun on.
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_UPDATED, null, $recordId);

        self::assertSame(2, $this->pinned($this->participant, 5));
    }

    public function testARecordThatNeverNamedAnItemPinsNothing(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, null);

        self::assertNull($this->pinned($this->participant, 5));
    }

    public function testAnImpossibleItemIdPinsNothing(): void
    {
        $this->write($this->participant, LookupService::ACTION_TRIGGER_TYPES_STARTED, 'res:qa_item:5@v2');

        self::assertNull($this->pinned($this->participant, 0));
        self::assertNull($this->pinned($this->participant, -5));
        self::assertNull($this->resources->pinnedVersion($this->participant, 0, 'qa_item', 5));
    }

    public function testATypeNoEnabledPluginRegisteredIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('qa_unknown');

        $this->resources->pinnedVersion($this->participant, $this->tableId, 'qa_unknown', 5);
    }

    // -- helpers ------------------------------------------------------------------------------

    private function pinned(int $userId, int $itemId): ?int
    {
        return $this->resources->pinnedVersion($userId, $this->tableId, QaItemPluginFixture::TYPE_CODE, $itemId);
    }

    /**
     * One record of a user in a trigger state, written the way a plugin writes it.
     *
     * @param string|null $ref the content reference the write names; null: none (a job)
     * @param int|null $recordId write this record again instead of creating one
     * @return int the record id
     */
    private function write(int $userId, string $trigger, ?string $ref, ?int $recordId = null, string $table = self::TABLE): int
    {
        $context = $ref === null
            ? new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB)
            : RecordWriteContext::forTransactionBy(LookupService::TRANSACTION_BY_BY_SYSTEM)->withContentRef($ref);

        $saved = $this->data->saveData(
            $table,
            ['title' => 'qa_pin_' . bin2hex(random_bytes(3)), 'id_users' => $userId, 'trigger_type' => $trigger],
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $context,
        );
        self::assertIsInt($saved);

        return $saved;
    }

    private function tableIdOf(int $recordId): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT id_data_tables FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function setTimestamp(int $recordId, string $utc): void
    {
        $this->db->executeStatement('UPDATE data_rows SET `timestamp` = :at WHERE id = :id', ['at' => $utc, 'id' => $recordId]);
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, $email . ' must be seeded. Run: composer test:reset-db');

        return (int) $user->getId();
    }
}
