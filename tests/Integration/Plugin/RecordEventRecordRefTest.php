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
use App\Plugin\Event\Domain\RecordDeletedEvent;
use App\Plugin\Event\Domain\RecordSavedEvent;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\Versioning\VersionRef;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\Plugin\QaDomainEventListener;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * C26 on the record events: every create, update, restore, quick action and delete
 * hands the listener the `record:<id>@v<n>` of the version written by that change.
 */
#[PhpUnitGroup('security')]
final class RecordEventRecordRefTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private DataService $data;
    private QaDomainEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
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
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testCreateUpdateRestoreAndDeleteCarryTheRecordVersionReference(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);

        $recordId = $this->write('qa_c26_evt', ['title' => 'A', 'id_users' => $userId]);
        self::assertSame(VersionRef::record($recordId, 1), $this->savedEvents()[0]->recordRef);

        $this->write('qa_c26_evt', ['title' => 'B', 'id_users' => $userId], $recordId);
        self::assertSame(VersionRef::record($recordId, 2), $this->savedEvents()[1]->recordRef);

        $this->data->restoreRecord(
            $this->tableIdByName('qa_c26_evt'),
            $recordId,
            1,
            null,
            false,
            new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2),
        );
        self::assertSame(VersionRef::record($recordId, 3), $this->savedEvents()[2]->recordRef);

        $this->data->deleteData(
            $this->tableIdByName('qa_c26_evt'),
            $recordId,
            false,
            new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 3),
        );
        $deleted = $this->deletedEvents();
        self::assertCount(1, $deleted);
        self::assertSame(VersionRef::record($recordId, 4), $deleted[0]->recordRef);
    }

    public function testAQuickActionCarriesTheRecordVersionReference(): void
    {
        $quickActions = json_encode([[
            'id' => 'done',
            'label' => 'Mark done',
            'set' => ['status' => 'done'],
            'when' => ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]],
        ]], JSON_THROW_ON_ERROR);
        [$page, $list, $tableId] = $this->formList('qa_c26_quick', ['quick_actions' => $quickActions]);
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->grid->row($tableId, $userId, ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $this->listener->handled = [];

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/lists/%d/records/%d/actions/done', $list->getId(), $recordId),
            ['page_id' => (int) $page->getId(), 'expected_version' => 1],
            $token,
        ));

        $saved = $this->savedEvents();
        self::assertCount(1, $saved);
        self::assertSame(VersionRef::record($recordId, 2), $saved[0]->recordRef);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $table, array $data, ?int $recordId = null): int
    {
        $context = new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM);
        $id = $this->data->saveData(
            $table,
            $data + ($recordId !== null ? ['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_UPDATED] : []),
            LookupService::TRANSACTION_BY_BY_USER,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $context,
        );
        self::assertIsInt($id);

        return $id;
    }

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
}
