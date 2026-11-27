<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Plugin\Plugin;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Users\PluginRecordWriterInterface;
use App\Plugin\Users\UserNotManagedException;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ChangeNotifier;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\Plugin\QaUsersPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * C20, writing for another user: `PluginRecordWriterInterface::saveFor`. A therapist plugin puts a record into
 * the generic data store that belongs to a participant, and the data store does what it always does (version,
 * history, change notices); `saveFor` only decides whether the write is allowed and names the door `plugin`.
 *
 * The QA users plugin ({@see QaUsersPluginFixture}) owns the tables `qa_users_*`. Who is who (all `qa_p12_` / `qa.p12.`):
 *
 * | Person | Role |
 * | --- | --- |
 * | therapist | update on group `G` (manages its members) |
 * | viewer | read on `G` (sees the members, manages nobody) |
 * | stranger | no role |
 * | participant, participant2 | members of `G` |
 * | outsider | member of another group |
 * | admin | the seeded QA superuser |
 *
 * Decided 2026-11-26 (task `2026-11-25-plugin-resource-hooks`, part 12): the history names the signed-in user, so
 * a non-zero actor must be that user; `SYSTEM_ACTOR` (0) is the system and names nobody; a guest is never an owner.
 */
#[PhpUnitGroup('security')]
final class PluginRecordWriterTest extends QaKernelTestCase
{
    private const TABLE = 'qa_users_notes';
    private const OTHER_TABLE = 'qa_users_other';

    private PluginRecordWriterInterface $writer;
    private DataRevisionService $revisions;
    private ChangeNotifier $notifier;
    private PluginCacheInvalidator $invalidator;
    private Connection $connection;
    private Plugin $plugin;
    private bool $itemsInstalled = false;

    /** @var array<string, int> the people by name */
    private array $people = [];
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->service(PluginRecordWriterInterface::class);
        $this->revisions = $this->service(DataRevisionService::class);
        $this->notifier = $this->service(ChangeNotifier::class);
        $this->invalidator = $this->service(PluginCacheInvalidator::class);
        $this->connection = $this->em->getConnection();
        $this->plugin = QaUsersPluginFixture::install($this->em, $this->invalidator);

        $users = new UserFactory($this->em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $groups = new GroupFactory($this->em);
        $access = new RoleDataAccessFactory($this->em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class));

        $granted = $groups->createGroup('qa_p12_writer_granted');
        $other = $groups->createGroup('qa_p12_writer_other');
        $therapistRole = $access->createRole('qa_p12_writer_therapist');
        $access->grantGroupAccess($therapistRole, (int) $granted->getId(), DataAccessSecurityService::PERMISSION_UPDATE);
        $viewerRole = $access->createRole('qa_p12_writer_viewer');
        $access->grantGroupAccess($viewerRole, (int) $granted->getId(), DataAccessSecurityService::PERMISSION_READ);

        $this->people = [
            'therapist' => (int) $users->createUser('qa.p12.writer.therapist@selfhelp.test', 'QA Therapist', roles: [$therapistRole])->getId(),
            'viewer' => (int) $users->createUser('qa.p12.writer.viewer@selfhelp.test', 'QA Viewer', roles: [$viewerRole])->getId(),
            'stranger' => (int) $users->createUser('qa.p12.writer.stranger@selfhelp.test', 'QA Stranger')->getId(),
            'participant' => (int) $users->createUser('qa.p12.writer.participant@selfhelp.test', 'QA Participant', [$granted])->getId(),
            'participant2' => (int) $users->createUser('qa.p12.writer.participant2@selfhelp.test', 'QA Participant Two', [$granted])->getId(),
            'outsider' => (int) $users->createUser('qa.p12.writer.outsider@selfhelp.test', 'QA Outsider', [$other])->getId(),
        ];

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->adminId = (int) $admin->getId();
    }

    protected function tearDown(): void
    {
        $this->signInAs(null);
        $this->notifier->reset();
        // The cache pool outlives the database rollback: the next test must not find the QA plugins.
        QaUsersPluginFixture::forget($this->invalidator);
        if ($this->itemsInstalled) {
            QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->invalidator);
        }
        parent::tearDown();
    }

    // == who may write ======================================================================

    public function testATherapistWritesARecordOwnedByTheParticipant(): void
    {
        $this->signInAs('therapist');

        $recordId = $this->save('participant', 'therapist', ['note' => 'Plan: breathing, twice a day']);

        self::assertSame($this->people['participant'], $this->rowOwner($recordId), 'the record belongs to the participant, not to the therapist');
        self::assertSame('Plan: breathing, twice a day', $this->cell($recordId, 'note'));
        self::assertSame(1, $this->tablesNamed(self::TABLE), 'the table of the plugin was created by the data store');

        $history = $this->history($recordId);
        self::assertCount(1, $history);
        self::assertSame('create', $history[0]['operation']);
        self::assertSame('plugin', $history[0]['source'], 'the door of the history is the plugin');
        self::assertSame($this->people['therapist'], $this->actorOf($history[0]), 'the history shows the therapist');
        self::assertNull($history[0]['impersonated_by']);
    }

    public function testTheParticipantAndTheTablesGrantRolesAreToldWithATableNotice(): void
    {
        $this->signInAs('therapist');
        $this->notifier->reset();

        $recordId = $this->save('participant', 'therapist', ['note' => 'Plan']);

        $pending = $this->notifier->pending();
        $topic = $this->service(MercureTopicResolver::class)->syncUserTopic($this->people['participant']);
        self::assertSame(['table:' . $this->tableId()], $pending[$topic] ?? null, 'the owner hears about the table of their record');
        self::assertGreaterThan(0, $recordId);
    }

    public function testTheSuperuserMayWriteForAnyone(): void
    {
        $this->signInAs(null, $this->adminId);

        $recordId = $this->saveFor($this->people['outsider'], $this->adminId, ['note' => 'By the administrator']);

        self::assertSame($this->people['outsider'], $this->rowOwner($recordId));
        self::assertSame($this->adminId, $this->actorOf($this->history($recordId)[0]));
    }

    public function testTheOwnerMayWriteTheirOwnRecord(): void
    {
        $this->signInAs('participant');

        $recordId = $this->save('participant', 'participant', ['note' => 'My own entry']);

        self::assertSame($this->people['participant'], $this->rowOwner($recordId));
        self::assertSame($this->people['participant'], $this->actorOf($this->history($recordId)[0]), 'the history shows the participant');
    }

    public function testSomebodyWhoDoesNotManageTheParticipantIsRefusedAndNothingIsWritten(): void
    {
        $this->signInAs('stranger');
        $before = $this->auditRows('stranger');

        try {
            $this->save('participant', 'stranger', ['note' => 'Not mine to write']);
            self::fail('A user who manages nobody must be refused.');
        } catch (UserNotManagedException $e) {
            self::assertSame(Response::HTTP_FORBIDDEN, $e->getCode());
            self::assertSame('user_not_managed', $e->getMessage());
            self::assertSame($this->people['stranger'], $e->getActorUserId());
            self::assertSame($this->people['participant'], $e->getOwnerUserId());
        }

        $this->assertNothingWritten();
        self::assertSame($before + 1, $this->auditRows('stranger'), 'the refusal is audited, like a refusal on an admin screen');
    }

    public function testViewingTheGroupIsNotEnough(): void
    {
        $this->signInAs('viewer');

        $this->expectException(UserNotManagedException::class);
        try {
            $this->save('participant', 'viewer', ['note' => 'Read is not update']);
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testATherapistDoesNotManageSomebodyOutsideTheirGroups(): void
    {
        $this->signInAs('therapist');

        $this->expectException(UserNotManagedException::class);
        try {
            $this->save('outsider', 'therapist', ['note' => 'Wrong group']);
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testAnOwnerWhoDoesNotExistLooksLikeSomebodyTheActorDoesNotManage(): void
    {
        $this->signInAs('therapist');

        // No enumeration of ids: for a person who manages nobody an unknown id is the same refusal as a known one.
        $this->expectException(UserNotManagedException::class);
        try {
            $this->saveFor(2_000_000_000, $this->people['therapist'], ['note' => 'Nobody']);
        } finally {
            $this->assertNothingWritten();
        }
    }

    // == the history names the signed-in user ===============================================

    public function testAPersonNamedAsActorMustBeTheSignedInUser(): void
    {
        $this->signInAs('stranger');
        $before = $this->auditRows('stranger');

        try {
            // The therapist would be allowed, but the history would name the stranger who is signed in.
            $this->save('participant', 'therapist', ['note' => 'In the name of somebody else']);
            self::fail('A person who is not signed in cannot be named as actor.');
        } catch (\InvalidArgumentException $e) {
            // A programming error, not a refusal: `UserNotManagedException` is not an InvalidArgumentException,
            // so a refusal would have escaped this catch and failed the test.
            self::assertStringContainsString('not the signed-in user', $e->getMessage());
        }

        $this->assertNothingWritten();
        self::assertSame($before, $this->auditRows('stranger'), 'a programming error is refused before the audited check');
    }

    public function testTheSystemWritesWithoutAPersonAndTheHistoryNamesNobody(): void
    {
        $this->signInAs(null); // a job: nobody is signed in

        $recordId = $this->saveFor($this->people['participant'], PluginRecordWriterInterface::SYSTEM_ACTOR, ['note' => 'Written by a job']);

        self::assertSame($this->people['participant'], $this->rowOwner($recordId));
        $history = $this->history($recordId);
        self::assertSame('plugin', $history[0]['source']);
        self::assertNull($history[0]['actor'], 'nobody is named: a job has no person');
    }

    public function testTheSystemActorIsZero(): void
    {
        self::assertSame(0, PluginRecordWriterInterface::SYSTEM_ACTOR);
    }

    public function testAGuestRequestCanNeverNameAPersonAsActor(): void
    {
        $this->signInAs(null); // an anonymous visitor: user id 0 everywhere in the product, nobody signed in

        foreach (['therapist', 'participant'] as $person) {
            try {
                $this->save('participant', $person, ['note' => 'A visitor names ' . $person]);
                self::fail("A visitor cannot name $person as actor.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('not the signed-in user (nobody)', $e->getMessage());
            }
        }

        $this->assertNothingWritten();
    }

    public function testAGuestIsNeverTheOwnerOfARecordWrittenForAUser(): void
    {
        $this->signInAs('therapist');

        foreach ([0, -1] as $owner) {
            try {
                $this->saveFor($owner, $this->people['therapist'], ['note' => 'For a visitor']);
                self::fail("Owner $owner is not a user.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('owner of a record is a user', $e->getMessage());
            }
        }

        $this->assertNothingWritten();
    }

    public function testAnOwnerWhoDoesNotExistIsNotFoundForTheSystem(): void
    {
        $this->signInAs(null);

        try {
            $this->saveFor(2_000_000_000, PluginRecordWriterInterface::SYSTEM_ACTOR, ['note' => 'Nobody']);
            self::fail('There is nobody to own the record.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_NOT_FOUND, $e->getCode());
            self::assertSame('user_not_found', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    // == the table ==========================================================================

    /** @return iterable<string, array{string}> */
    public static function tablesOutsideThePrefix(): iterable
    {
        yield 'a table of nobody in particular' => ['qa_other_notes'];
        yield 'a table of the core' => ['users'];
        yield 'a prefix the plugin does not own at the front' => ['sh2_qa_users_notes'];
        yield 'the prefix itself' => ['qa_users_'];
        yield 'the prefix in capitals' => ['QA_USERS_notes'];
        yield 'the prefix without its end' => ['qa_users'];
        yield 'an empty name' => [''];
    }

    #[DataProvider('tablesOutsideThePrefix')]
    public function testATableOutsideThePrefixOfThePluginIsRefused(string $table): void
    {
        $this->signInAs('therapist');
        $before = $this->auditRows('therapist');

        try {
            $this->writer->saveFor(
                QaUsersPluginFixture::PLUGIN_ID,
                $this->people['participant'],
                $this->people['therapist'],
                $table,
                ['note' => 'Not in my tables'],
            );
            self::fail("Table '$table' is not a table of the plugin.");
        } catch (\InvalidArgumentException) {
            // A programming error (a refusal, `UserNotManagedException`, would have escaped this catch).
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->tablesNamed($table), 'nothing was created');
        self::assertSame($before, $this->auditRows('therapist'), 'a programming error is not audited');
    }

    public function testAPluginThatIsUnknownOrNotEnabledCannotWrite(): void
    {
        $this->signInAs('therapist');

        try {
            $this->writer->saveFor('qa-no-such-plugin', $this->people['participant'], $this->people['therapist'], self::TABLE, ['note' => 'x']);
            self::fail('An unknown plugin owns no table.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        QaUsersPluginFixture::setEnabled($this->plugin, false, $this->em, $this->invalidator);
        try {
            $this->save('participant', 'therapist', ['note' => 'x']);
            self::fail('A plugin that is not enabled cannot write.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->assertNothingWritten();

        QaUsersPluginFixture::setEnabled($this->plugin, true, $this->em, $this->invalidator);
        self::assertGreaterThan(0, $this->save('participant', 'therapist', ['note' => 'Enabled again']));
    }

    // == changing a record ==================================================================

    public function testAChangeKeepsTheOwnerAndAddsARevisionOfTheTherapist(): void
    {
        $this->signInAs('therapist');
        $recordId = $this->save('participant', 'therapist', ['note' => 'one']);

        $same = $this->save('participant', 'therapist', ['note' => 'two'], recordId: $recordId, expectedVersion: 1);

        self::assertSame($recordId, $same, 'the same record');
        self::assertSame($this->people['participant'], $this->rowOwner($recordId), 'still the participant\'s');
        self::assertSame('two', $this->cell($recordId, 'note'));
        $history = $this->history($recordId);
        self::assertCount(2, $history);
        self::assertSame([2, 'update', 'plugin'], [$history[0]['version'], $history[0]['operation'], $history[0]['source']]);
        self::assertSame($this->people['therapist'], $this->actorOf($history[0]));
        self::assertSame([1, 'create'], [$history[1]['version'], $history[1]['operation']]);
    }

    public function testAStaleVersionIsRefusedWith409AndNothingChanges(): void
    {
        $this->signInAs('therapist');
        $recordId = $this->save('participant', 'therapist', ['note' => 'one']);
        $this->save('participant', 'therapist', ['note' => 'two'], recordId: $recordId, expectedVersion: 1);

        try {
            $this->save('participant', 'therapist', ['note' => 'three, based on the old version'], recordId: $recordId, expectedVersion: 1);
            self::fail('A change based on version 1 of a record that is at version 2 must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_CONFLICT, $e->getCode());
        }

        self::assertSame('two', $this->cell($recordId, 'note'));
        self::assertCount(2, $this->history($recordId), 'no revision was added');
    }

    public function testAWriteWithoutAVersionDoesNotCompareOne(): void
    {
        $this->signInAs('therapist');
        $recordId = $this->save('participant', 'therapist', ['note' => 'one']);
        $this->save('participant', 'therapist', ['note' => 'two'], recordId: $recordId, expectedVersion: 1);

        $this->save('participant', 'therapist', ['note' => 'three'], recordId: $recordId);

        self::assertSame('three', $this->cell($recordId, 'note'), 'trusted code that names no version is not checked against one');
        self::assertCount(3, $this->history($recordId));
    }

    public function testARecordOfAnotherOwnerOfAnotherTableOrOfNobodyIsNotFound(): void
    {
        $this->signInAs('therapist');
        $ofParticipantTwo = $this->save('participant2', 'therapist', ['note' => 'Belongs to participant two']);
        $inAnotherTable = $this->saveFor($this->people['participant'], $this->people['therapist'], ['note' => 'Other table'], table: self::OTHER_TABLE);

        $aims = [
            'a record of another owner' => $ofParticipantTwo,
            'a record of another table' => $inAnotherTable,
            'a record nobody has' => 2_000_000_000,
        ];
        foreach ($aims as $what => $recordId) {
            try {
                $this->save('participant', 'therapist', ['note' => 'Hijack'], recordId: $recordId);
                self::fail("$what must not be found");
            } catch (ServiceException $e) {
                self::assertSame(Response::HTTP_NOT_FOUND, $e->getCode(), $what);
                self::assertSame('record_not_found', $e->getMessage(), $what);
            }
        }

        self::assertSame('Belongs to participant two', $this->cell($ofParticipantTwo, 'note'));
        self::assertSame('Other table', $this->cell($inAnotherTable, 'note'));
    }

    // == the data and the arguments =========================================================

    public function testTheOwnerOfTheRecordIsTheArgumentNotTheData(): void
    {
        $this->signInAs('therapist');

        try {
            $this->save('participant', 'therapist', ['note' => 'Steal', 'id_users' => $this->people['stranger']]);
            self::fail('id_users in the data that names another user must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('id_users', $e->getMessage());
        }
        $this->assertNothingWritten();

        $recordId = $this->save('participant', 'therapist', ['note' => 'Same owner', 'id_users' => $this->people['participant']]);
        self::assertSame($this->people['participant'], $this->rowOwner($recordId), 'naming the owner again is harmless');
    }

    public function testUnusableArgumentsAreProgrammingErrors(): void
    {
        $this->signInAs('therapist');
        $participant = $this->people['participant'];
        $therapist = $this->people['therapist'];

        $calls = [
            'a negative actor' => fn () => $this->saveFor($participant, -1, ['note' => 'x']),
            'a record id of 0' => fn () => $this->saveFor($participant, $therapist, ['note' => 'x'], recordId: 0),
            'a version without a record' => fn () => $this->saveFor($participant, $therapist, ['note' => 'x'], expectedVersion: 1),
            'a version of 0' => fn () => $this->saveFor($participant, $therapist, ['note' => 'x'], recordId: 1, expectedVersion: 0),
        ];
        foreach ($calls as $what => $call) {
            try {
                $call();
                self::fail("$what must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->assertNothingWritten();
    }

    // == the content reference (C19) ========================================================

    public function testTheContentReferenceIsStoredOnTheRecordAndOnTheRevision(): void
    {
        $this->installItems();
        $this->signInAs('therapist');

        $recordId = $this->saveFor($this->people['participant'], $this->people['therapist'], ['note' => 'Read exercise 5'], 'res:qa_item:5@v2');
        self::assertSame('res:qa_item:5@v2', $this->rowRef($recordId));
        self::assertSame('res:qa_item:5@v2', $this->history($recordId)[0]['content_ref']);

        $this->saveFor($this->people['participant'], $this->people['therapist'], ['note' => 'Read exercise 5 again'], 'res:qa_item:5@v3', $recordId);
        self::assertSame('res:qa_item:5@v3', $this->rowRef($recordId), 'the record names the content of its latest write');
        $history = $this->history($recordId);
        self::assertSame(['res:qa_item:5@v3', 'res:qa_item:5@v2'], [$history[0]['content_ref'], $history[1]['content_ref']], 'every revision keeps the reference it was written with');
    }

    public function testAMalformedOrUnregisteredReferenceIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->signInAs('therapist');
        $before = $this->auditRows('therapist');

        // Malformed: no version.
        try {
            $this->saveFor($this->people['participant'], $this->people['therapist'], ['note' => 'x'], 'res:qa_item:5');
            self::fail('A reference without its version is malformed.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, $this->auditRows('therapist'), 'refused before the audited check');

        // Well formed, but no enabled plugin registered the resource type `qa_item` (the QA items plugin is not installed here).
        try {
            $this->saveFor($this->people['participant'], $this->people['therapist'], ['note' => 'x'], 'res:qa_item:5@v2');
            self::fail('A type no enabled plugin registered is refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('qa_item', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    // == helpers ============================================================================

    /**
     * `saveFor` for a person of the world, signed in or not.
     *
     * @param array<string, mixed> $data
     */
    private function save(string $owner, string $actor, array $data, ?string $contentRef = null, ?int $recordId = null, ?int $expectedVersion = null): int
    {
        return $this->saveFor($this->people[$owner], $this->people[$actor], $data, $contentRef, $recordId, $expectedVersion);
    }

    /**
     * `saveFor` with ids, into the QA table (or the one named).
     *
     * @param array<string, mixed> $data
     */
    private function saveFor(
        int $ownerUserId,
        int $actorUserId,
        array $data,
        ?string $contentRef = null,
        ?int $recordId = null,
        ?int $expectedVersion = null,
        string $table = self::TABLE,
    ): int {
        return $this->writer->saveFor(
            QaUsersPluginFixture::PLUGIN_ID,
            $ownerUserId,
            $actorUserId,
            $table,
            $data,
            $contentRef,
            $recordId,
            $expectedVersion,
        );
    }

    /**
     * Sign a person of the world in (by name), the QA admin (by id), or nobody (a job, a visitor).
     */
    private function signInAs(?string $person, ?int $userId = null): void
    {
        $userId ??= $person !== null ? $this->people[$person] : null;

        $tokens = $this->service(TokenStorageInterface::class);
        if ($userId === null) {
            $tokens->setToken(null);
        } else {
            $user = $this->em->getRepository(User::class)->find($userId);
            self::assertInstanceOf(User::class, $user);
            $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }
        // The user context remembers the first user it resolved.
        $this->service(UserContextService::class)->reset();
    }

    private function installItems(): void
    {
        $this->itemsInstalled = true;
        QaItemPluginFixture::install($this->em, $this->service(PluginCatalogueSynchronizer::class), $this->invalidator);
    }

    /**
     * The history of the record, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $recordId): array
    {
        return $this->revisions->history($recordId)['items'];
    }

    /** @param array<string, mixed> $revision */
    private function actorOf(array $revision): ?int
    {
        $actor = $revision['actor'] ?? null;

        return $actor === null ? null : self::asInt(self::asArray($actor)['id'] ?? null);
    }

    private function tableId(): int
    {
        return self::coerceInt($this->connection->fetchOne('SELECT id FROM data_tables WHERE name = :name', ['name' => self::TABLE]));
    }

    private function tablesNamed(string $name): int
    {
        return self::coerceInt($this->connection->fetchOne('SELECT COUNT(*) FROM data_tables WHERE name = :name', ['name' => $name]));
    }

    private function rowOwner(int $recordId): ?int
    {
        $owner = $this->connection->fetchOne('SELECT id_users FROM data_rows WHERE id = :id', ['id' => $recordId]);

        return $owner === false || $owner === null ? null : self::coerceInt($owner);
    }

    private function rowRef(int $recordId): ?string
    {
        $ref = $this->connection->fetchOne('SELECT content_ref FROM data_rows WHERE id = :id', ['id' => $recordId]);

        return is_string($ref) ? $ref : null;
    }

    private function cell(int $recordId, string $fieldKey): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT dc.value FROM data_cells dc JOIN data_cols c ON c.id = dc.id_data_cols WHERE dc.id_data_rows = :row AND c.field_key = :key',
            ['row' => $recordId, 'key' => $fieldKey],
        );

        return is_string($value) ? $value : null;
    }

    private function auditRows(string $person): int
    {
        return self::coerceInt($this->connection->fetchOne('SELECT COUNT(*) FROM data_access_audits WHERE id_users = :id', ['id' => $this->people[$person]]));
    }

    /** A refused call leaves no table of the plugin and no record of the people of this test. */
    private function assertNothingWritten(): void
    {
        self::assertSame(0, $this->tablesNamed(self::TABLE), 'the table of the plugin was not even created');
        self::assertSame(
            0,
            self::coerceInt($this->connection->fetchOne(
                'SELECT COUNT(*) FROM data_rows r JOIN data_tables t ON t.id = r.id_data_tables WHERE t.name LIKE :prefix',
                ['prefix' => QaUsersPluginFixture::TABLE_PREFIX . '%'],
            )),
            'no record of the plugin exists',
        );
    }
}
