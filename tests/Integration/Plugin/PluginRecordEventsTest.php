<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Domain\RecordDeletedEvent;
use App\Plugin\Event\Domain\RecordSavedEvent;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\InteractsWithFormRecordPages;
use App\Tests\Support\Plugin\QaDomainEventListener;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * C24 at the record: every save, delete and restore of a record of the generic data store is announced to the
 * listeners of enabled plugins that list `record.saved` / `record.deleted`, once per change, after the commit of the
 * outermost transaction, and never for a write that was refused or rolled back. The write paths are the real ones:
 * the form endpoints, a quick action, and the path a job, an import or a plugin takes.
 *
 * Who is told what (the dispatcher) is {@see PluginDomainEventDispatcherTest}; this test proves that the data service
 * raises the events, with the right payload, at the right moment.
 */
#[PhpUnitGroup('security')]
final class PluginRecordEventsTest extends QaWebTestCase
{
    use InteractsWithFormLists;
    use InteractsWithFormRecordPages;

    private DataService $data;
    private QaDomainEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        // Shared container, as the form workflow golden test: the listener the test reads is the one the requests reach.
        $this->client->disableReboot();
        $this->setUpFormLists();
        $this->data = $this->service(DataService::class);
        $this->listener = $this->service(QaDomainEventListener::class);

        QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            ['record.saved', 'record.deleted'],
        );
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // == the write paths ==============================================================================

    public function testAFormSubmitAndAFormUpdateEachReachTheListenerOnce(): void
    {
        $admin = $this->loginAsQaAdmin();
        $user = $this->loginAsQaUser();
        [$pageId, $sectionId] = $this->createFormRecordPage($admin, 'qa_evt_form');
        $tableId = $this->tableIdByName((string) $sectionId);
        self::assertSame([], $this->listener->handled, 'Creating the page and its form writes no record.');

        $recordId = $this->submitForm($user, $pageId, $sectionId, 'qa_value_1');

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertCount(1, $this->listener->handled, 'Exactly one event, and no other kind.');
        self::assertSame($tableId, $saved[0]->tableId);
        self::assertSame($recordId, $saved[0]->recordId);
        self::assertSame($this->userId(QaBaselineFixture::QA_USER_EMAIL), $saved[0]->ownerUserId);
        self::assertSame(LookupService::ACTION_TRIGGER_TYPES_FINISHED, $saved[0]->trigger);
        self::assertSame("page:{$pageId}@draft", $saved[0]->contentRef, 'The event names the content the form was served from.');

        $version = $this->publishFormPage($admin, $pageId);
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => $pageId,
            'section_id' => $sectionId,
            'form_data' => ['qa_field' => 'qa_value_2'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => 1,
        ], $user));

        $saved = $this->savedEvents();
        self::assertCount(2, $saved, 'The update is a second event, not a repeat of the first.');
        self::assertSame($recordId, $saved[1]->recordId);
        self::assertSame(LookupService::ACTION_TRIGGER_TYPES_UPDATED, $saved[1]->trigger);
        self::assertSame("page:{$pageId}@v{$version}", $saved[1]->contentRef);
    }

    public function testAQuickActionReachesTheListenerOnce(): void
    {
        $quickActions = json_encode([[
            'id' => 'done',
            'label' => 'Mark done',
            'set' => ['status' => 'done'],
            'when' => ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]],
        ]], JSON_THROW_ON_ERROR);
        [$page, $list, $tableId] = $this->formList('qa_evt_quick', ['quick_actions' => $quickActions]);
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->grid->row($tableId, $userId, ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        self::assertSame([], $this->listener->handled, 'A record made by a fixture is no write of the data service.');

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/lists/%d/records/%d/actions/done', $list->getId(), $recordId),
            ['page_id' => (int) $page->getId(), 'expected_version' => 1],
            $token,
        ));

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertCount(1, $this->listener->handled);
        self::assertSame($tableId, $saved[0]->tableId);
        self::assertSame($recordId, $saved[0]->recordId);
        self::assertSame($userId, $saved[0]->ownerUserId);
        self::assertSame(LookupService::ACTION_TRIGGER_TYPES_UPDATED, $saved[0]->trigger);
        self::assertSame('page:' . $page->getId() . '@draft', $saved[0]->contentRef);
    }

    public function testAJobWriteReachesTheListenerOnceWithoutAReference(): void
    {
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);

        $recordId = $this->job('qa_evt_job', ['title' => 'from a job', 'id_users' => $userId]);

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertCount(1, $this->listener->handled);
        self::assertSame($this->tableIdByName('qa_evt_job'), $saved[0]->tableId);
        self::assertSame($recordId, $saved[0]->recordId);
        self::assertSame($userId, $saved[0]->ownerUserId, 'The owner is the one the job wrote the record for.');
        self::assertSame(LookupService::ACTION_TRIGGER_TYPES_FINISHED, $saved[0]->trigger);
        self::assertNull($saved[0]->contentRef, 'A job names no content.');
    }

    public function testARecordWithoutAnOwnerIsAnnouncedWithoutOne(): void
    {
        $this->job('qa_evt_ownerless', ['title' => 'nobody']);

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertNull($saved[0]->ownerUserId);
    }

    public function testAPluginWriteCarriesItsReferenceInTheEvent(): void
    {
        $recordId = $this->job('qa_evt_plugin', ['title' => 'A'], $this->pluginContext('res:qa_item:5@v2'));

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertSame($recordId, $saved[0]->recordId);
        self::assertSame('res:qa_item:5@v2', $saved[0]->contentRef);
    }

    public function testEachUpdateOfARecordIsOneEventWithTheUpdatedTrigger(): void
    {
        $recordId = $this->job('qa_evt_update', ['title' => 'A'], $this->pluginContext('res:qa_item:5@v1'));

        $this->job('qa_evt_update', ['title' => 'B'], null, $recordId);

        $saved = $this->savedEvents();
        self::assertCount(2, $saved);
        self::assertSame([$recordId, $recordId], array_map(static fn (RecordSavedEvent $event): int => $event->recordId, $saved));
        self::assertSame(
            [LookupService::ACTION_TRIGGER_TYPES_FINISHED, LookupService::ACTION_TRIGGER_TYPES_UPDATED],
            array_map(static fn (RecordSavedEvent $event): string => $event->trigger, $saved),
        );
        self::assertSame(['res:qa_item:5@v1', null], array_map(static fn (RecordSavedEvent $event): ?string => $event->contentRef, $saved));
    }

    public function testASaveThatChangesNothingIsStillASave(): void
    {
        $recordId = $this->job('qa_evt_same', ['title' => 'Same']);

        $this->job('qa_evt_same', ['title' => 'Same'], null, $recordId);

        self::assertCount(2, $this->savedEvents(), 'Like the jobs of the table, a plugin hears about every save.');
    }

    public function testARestoreReachesTheListenerOnceWithTheReferenceItRestores(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->job('qa_evt_restore', ['title' => 'T1'], $this->pluginContext('res:qa_item:5@v1'));
        $this->job('qa_evt_restore', ['title' => 'T2'], $this->pluginContext('res:qa_item:5@v2'), $recordId);
        self::assertCount(2, $this->listener->handled);

        $this->data->restoreRecord(
            $this->tableIdByName('qa_evt_restore'),
            $recordId,
            1,
            null,
            false,
            new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2),
        );

        $saved = $this->savedEvents();
        self::assertCount(3, $saved, 'The restore is one event.');
        self::assertCount(3, $this->listener->handled);
        self::assertSame($recordId, $saved[2]->recordId);
        self::assertSame(LookupService::ACTION_TRIGGER_TYPES_UPDATED, $saved[2]->trigger);
        self::assertSame('res:qa_item:5@v1', $saved[2]->contentRef);
    }

    public function testADeleteReachesTheListenerOnceAsADeleteNotASave(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->job('qa_evt_delete', ['title' => 'doomed', 'id_users' => $userId]);
        $tableId = $this->tableIdByName('qa_evt_delete');

        $this->data->deleteData($tableId, $recordId, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));

        $deleted = $this->deletedEvents();
        self::assertCount(1, $deleted);
        self::assertSame($tableId, $deleted[0]->tableId);
        self::assertSame($recordId, $deleted[0]->recordId);
        self::assertSame($userId, $deleted[0]->ownerUserId, 'The owner stays the one who owned the record.');
        self::assertCount(1, $this->savedEvents(), 'Only the first save: a delete is not announced a second time as a save.');
        self::assertCount(2, $this->listener->handled);
    }

    // == when an event arrives =========================================================================

    public function testAWriteInsideAnOuterTransactionIsAnnouncedWhenTheOuterTransactionCommits(): void
    {
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $recordId = $this->job('qa_evt_outer', ['title' => 'A']);
        self::assertSame([], $this->listener->handled, 'The change is not stored yet: nobody is told.');
        $connection->commit();

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertSame($recordId, $saved[0]->recordId);
    }

    public function testAWriteThatIsRolledBackIsNeverAnnounced(): void
    {
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $this->job('qa_evt_rolled_back', ['title' => 'never stored']);
        $connection->rollBack();
        $this->em->clear();

        // A later, unrelated commit must not bring the dropped event back.
        $connection->beginTransaction();
        $connection->commit();

        self::assertSame([], $this->listener->handled);
    }

    public function testADeleteThatIsRolledBackIsNeverAnnounced(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->job('qa_evt_delete_rolled_back', ['title' => 'kept']);
        $tableId = $this->tableIdByName('qa_evt_delete_rolled_back');
        $this->listener->handled = [];
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $this->data->deleteData($tableId, $recordId, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));
        $connection->rollBack();
        $this->em->clear();
        $connection->beginTransaction();
        $connection->commit();

        self::assertSame([], $this->listener->handled);
    }

    public function testAWriteThatIsRefusedIsNeverAnnounced(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->job('qa_evt_refused', ['title' => 'v1']);
        $this->job('qa_evt_refused', ['title' => 'v2'], null, $recordId);
        $this->listener->handled = [];
        $tableId = $this->tableIdByName('qa_evt_refused');

        // A save and a delete based on version 1 while the record is at version 2 are refused with a conflict.
        try {
            $this->job('qa_evt_refused', ['title' => 'lost'], new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1), $recordId);
            self::fail('A stale save must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(409, $e->getCode());
        }
        try {
            $this->data->deleteData($tableId, $recordId, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));
            self::fail('A stale delete must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(409, $e->getCode());
        }

        // A reference of a type nobody registered is refused before anything is written.
        try {
            $this->job('qa_evt_refused', ['title' => 'nope'], RecordWriteContext::forTransactionBy(LookupService::TRANSACTION_BY_BY_SYSTEM)->withContentRef('res:qa_nobody:5@v1'), $recordId);
            self::fail('An unregistered type must be refused.');
        } catch (\InvalidArgumentException) {
            // refused
        }

        self::assertSame([], $this->listener->handled);
    }

    // -- helpers ------------------------------------------------------------------------------------

    /**
     * A write the way a job, an import or a plugin makes it: a trusted writer, no person involved.
     *
     * @param array<string, mixed> $data
     * @param RecordWriteContext|null $context null: a job that names no content
     * @return int the record id
     */
    private function job(string $table, array $data, ?RecordWriteContext $context = null, ?int $recordId = null): int
    {
        $saved = $this->data->saveData(
            $table,
            $data,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $context ?? new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB),
        );
        self::assertIsInt($saved);

        return $saved;
    }

    private function pluginContext(string $ref): RecordWriteContext
    {
        return RecordWriteContext::forTransactionBy(LookupService::TRANSACTION_BY_BY_SYSTEM)->withContentRef($ref);
    }

    /** @return list<RecordSavedEvent> */
    private function savedEvents(): array
    {
        return array_values(array_filter($this->listener->handled, static fn (object $event): bool => $event instanceof RecordSavedEvent));
    }

    /** @return list<RecordDeletedEvent> */
    private function deletedEvents(): array
    {
        return array_values(array_filter($this->listener->handled, static fn (object $event): bool => $event instanceof RecordDeletedEvent));
    }

    /** Sign the test in as a QA user, so that the history and the audit know who wrote. */
    private function actAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        // The user context remembers the first user it resolved; forget it.
        $context = $this->service(UserContextService::class);
        $reflection = new \ReflectionClass($context);
        foreach (['userResolved' => false, 'cachedUser' => null] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($context, $value);
            }
        }
    }
}
