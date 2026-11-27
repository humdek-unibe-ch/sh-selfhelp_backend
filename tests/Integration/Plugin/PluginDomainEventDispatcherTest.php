<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Doctrine\AfterCommitConnection;
use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Domain\PagePublishedEvent;
use App\Plugin\Event\Domain\PluginDomainEventDispatcher;
use App\Plugin\Event\Domain\PluginDomainEvents;
use App\Plugin\Event\Domain\RecordDeletedEvent;
use App\Plugin\Event\Domain\RecordSavedEvent;
use App\Plugin\Event\Domain\UserCreatedEvent;
use App\Plugin\Event\Domain\UserDeletedEvent;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\Plugin\PluginRepository;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\Plugin\QaDomainEventListener;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\Plugin\QaThrowingDomainEventListener;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Psr\Log\AbstractLogger;

/**
 * C24: the five read-only domain events reach the listeners of **enabled** plugins whose
 * manifest `listens` names the event, after the commit and never on a rollback; a listener
 * that throws is logged and changes neither the write nor the other listeners.
 *
 * The recording listener belongs to the QA items plugin ({@see QaItemPluginFixture}); the
 * throwing one to `qa-throwing-plugin`, which a test installs only when it needs it.
 */
#[PhpUnitGroup('security')]
final class PluginDomainEventDispatcherTest extends QaKernelTestCase
{
    private PluginDomainEventDispatcher $dispatcher;
    private QaDomainEventListener $listener;
    private QaThrowingDomainEventListener $throwing;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = $this->service(PluginDomainEventDispatcher::class);
        $this->listener = $this->service(QaDomainEventListener::class);
        $this->throwing = $this->service(QaThrowingDomainEventListener::class);
        $this->connection = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find these plugins.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- who gets which event -------------------------------------------------------------

    public function testAnEventReachesTheListenerOfAnEnabledPluginThatListensForIt(): void
    {
        $this->installPlugin(['record.saved']);
        $event = new RecordSavedEvent(12, 34, 56, 'finished', 'v1');

        $this->dispatcher->dispatchAfterCommit($event);

        self::assertSame([$event], $this->listener->handled, 'delivered at once: no transaction is open');
        self::assertSame(0, $this->throwing->calls, 'a plugin that is not installed hears nothing');
    }

    public function testAnEventTheManifestDoesNotListForIsNotDelivered(): void
    {
        $this->installPlugin(['user.created']);

        $this->dispatcher->dispatchAfterCommit(new RecordSavedEvent(1, 2, null, 'finished'));
        $this->dispatcher->dispatchAfterCommit(new PagePublishedEvent(3, 4));
        self::assertSame([], $this->listener->handled);

        $created = new UserCreatedEvent(9);
        $this->dispatcher->dispatchAfterCommit($created);
        self::assertSame([$created], $this->listener->handled);
    }

    public function testAPluginThatListensForNothingHearsNothing(): void
    {
        $this->installPlugin([]);

        $this->dispatcher->dispatchAfterCommit(new UserDeletedEvent(9));

        self::assertSame([], $this->listener->handled);
    }

    public function testEachOfTheFiveEventsIsDeliveredToAPluginThatListensForAll(): void
    {
        $this->installPlugin(PluginDomainEvents::names());
        $events = [
            new RecordSavedEvent(1, 2, 3, 'finished'),
            new RecordDeletedEvent(1, 2, null),
            new UserCreatedEvent(5),
            new UserDeletedEvent(5),
            new PagePublishedEvent(7, 2),
        ];

        foreach ($events as $event) {
            $this->dispatcher->dispatchAfterCommit($event);
        }

        self::assertSame($events, $this->listener->handled);
    }

    public function testADisabledPluginHearsNothing(): void
    {
        $plugin = $this->installPlugin(['record.saved']);
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        $this->dispatcher->dispatchAfterCommit(new RecordSavedEvent(1, 2, null, 'finished'));

        self::assertSame([], $this->listener->handled);
    }

    public function testNoPluginRowMeansNoDelivery(): void
    {
        // The listener service exists (it is part of the test container) but no plugin of that id is installed.
        $this->dispatcher->dispatchAfterCommit(new RecordSavedEvent(1, 2, null, 'finished'));

        self::assertSame([], $this->listener->handled);
    }

    public function testOnlyTheFiveEventClassesAreAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass');

        $this->dispatcher->dispatchAfterCommit(new \stdClass());
    }

    public function testTheManifestSchemaListsExactlyTheEventsTheHostCanDeliver(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/plugins/plugin-manifest.schema.json'), true);
        $enum = self::jsonGet($schema, 'properties', 'listens', 'items', 'enum');

        self::assertSame(PluginDomainEvents::names(), $enum);
        self::assertSame(['record.saved', 'page.published'], PluginManifest::listensOf(['listens' => ['record.saved', 'page.published']]));
    }

    // -- when an event arrives --------------------------------------------------------------

    public function testTheConnectionIsTheOneThatCanWaitForACommit(): void
    {
        self::assertInstanceOf(AfterCommitConnection::class, $this->connection);
    }

    public function testAnEventWaitsForTheCommit(): void
    {
        $this->installPlugin(['record.saved']);
        $event = new RecordSavedEvent(1, 2, null, 'finished');

        $this->connection->beginTransaction();
        $this->dispatcher->dispatchAfterCommit($event);
        self::assertSame([], $this->listener->handled, 'the change is not stored yet');
        $this->connection->commit();

        self::assertSame([$event], $this->listener->handled);
    }

    public function testAnEventOfARolledBackChangeIsNeverDelivered(): void
    {
        $this->installPlugin(['record.saved']);

        $this->connection->beginTransaction();
        $this->dispatcher->dispatchAfterCommit(new RecordSavedEvent(1, 2, null, 'finished'));
        $this->connection->rollBack();

        $this->connection->beginTransaction();
        $this->connection->commit();

        self::assertSame([], $this->listener->handled, 'the dropped event does not come back with the next commit');
    }

    public function testAnEventInsideASavepointWaitsForTheOutermostCommit(): void
    {
        $this->installPlugin(['record.saved']);
        $kept = new RecordSavedEvent(1, 1, null, 'finished');
        $dropped = new RecordSavedEvent(1, 2, null, 'finished');

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->dispatcher->dispatchAfterCommit($kept);
        $this->connection->commit();
        self::assertSame([], $this->listener->handled, 'releasing a savepoint stores nothing yet');

        $this->connection->beginTransaction();
        $this->dispatcher->dispatchAfterCommit($dropped);
        $this->connection->rollBack();
        $this->connection->commit();

        self::assertSame([$kept], $this->listener->handled, 'the rolled back savepoint takes its events with it');
    }

    public function testAnEventOfAnInnerCommitIsDroppedWhenTheOuterTransactionRollsBack(): void
    {
        $this->installPlugin(['record.saved']);

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->dispatcher->dispatchAfterCommit(new RecordSavedEvent(1, 1, null, 'finished'));
        $this->connection->commit();
        $this->connection->rollBack();

        self::assertSame([], $this->listener->handled);
    }

    // -- a failing listener -------------------------------------------------------------------

    public function testAListenerThatThrowsIsLoggedAndChangesNeitherTheWriteNorTheOtherListeners(): void
    {
        $this->installPlugin(['record.saved']);
        $this->installThrowingPlugin(['record.saved']);
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        // The throwing listener comes first: the one after it must still be called.
        $dispatcher = new PluginDomainEventDispatcher(
            $this->connection,
            $this->service(PluginRegistryService::class),
            [$this->throwing, $this->listener],
            $logger,
        );
        $event = new RecordSavedEvent(1, 2, null, 'finished');

        $this->connection->beginTransaction();
        $this->connection->insert('roles', ['name' => 'qa_c24_write', 'description' => 'written next to the event']);
        $dispatcher->dispatchAfterCommit($event);
        $this->connection->commit();

        self::assertSame(1, $this->throwing->calls, 'the failing listener was called');
        self::assertSame([$event], $this->listener->handled, 'the listener after it still got the event');
        self::assertSame(
            1,
            self::coerceInt($this->connection->fetchOne('SELECT COUNT(*) FROM roles WHERE name = ?', ['qa_c24_write'])),
            'the write that raised the event stays stored',
        );

        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        self::assertSame('error', $record['level']);
        self::assertSame(QaThrowingDomainEventListener::PLUGIN_ID, $record['context']['plugin_id'] ?? null);
        self::assertSame('record.saved', $record['context']['event'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $record['context']['exception'] ?? null);
        self::assertSame(QaThrowingDomainEventListener::MESSAGE, $record['context']['exception']->getMessage());
    }

    public function testAFailureReadingTheEnabledPluginsNeverEscapesTheCommit(): void
    {
        $this->installPlugin(['record.saved']);
        // The registry answers from its cache; drop it so that the failing read below is really asked.
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $failingRepository = new class($registry) extends PluginRepository {
            public function findEnabled(): array
            {
                throw new \RuntimeException('the enabled plugins cannot be read');
            }
        };
        $dispatcher = new PluginDomainEventDispatcher(
            $this->connection,
            new PluginRegistryService($failingRepository, $this->service(CacheService::class)),
            [$this->listener],
            $logger,
        );
        $laterCallbacks = 0;
        $connection = $this->connection;
        self::assertInstanceOf(AfterCommitConnection::class, $connection);

        $this->connection->beginTransaction();
        $this->connection->insert('roles', ['name' => 'qa_c24_registry', 'description' => 'written next to the event']);
        $dispatcher->dispatchAfterCommit(new RecordSavedEvent(1, 2, null, 'finished'));
        $connection->afterCommit(static function () use (&$laterCallbacks): void {
            ++$laterCallbacks;
        });
        $this->connection->commit(); // must not throw: the change is already stored

        self::assertSame(1, $laterCallbacks, 'the after-commit callback queued after the failing one still ran');
        self::assertSame([], $this->listener->handled, 'nothing could be delivered');
        self::assertSame(
            1,
            self::coerceInt($this->connection->fetchOne('SELECT COUNT(*) FROM roles WHERE name = ?', ['qa_c24_registry'])),
            'the committed change stays stored',
        );
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('record.saved', $logger->records[0]['context']['event'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    // -- helpers -----------------------------------------------------------------------------------

    /**
     * The enabled QA items plugin, listening for the given events.
     *
     * @param list<string> $listens
     */
    private function installPlugin(array $listens): Plugin
    {
        return QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            $listens,
        );
    }

    /**
     * An enabled plugin with no catalogue: just the row and a manifest that lists events.
     *
     * @param list<string> $listens
     */
    private function installThrowingPlugin(array $listens): void
    {
        $plugin = new Plugin(QaThrowingDomainEventListener::PLUGIN_ID, 'QA throwing plugin', '1.0.0', '0.2.0');
        $plugin->setEnabled(true);
        $plugin->setManifestJson([
            'id' => QaThrowingDomainEventListener::PLUGIN_ID,
            'name' => 'QA throwing plugin',
            'version' => '1.0.0',
            'pluginApiVersion' => '0.2.0',
            'listens' => $listens,
        ]);
        $this->em->persist($plugin);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
    }
}
