<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\ValidationCode;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Domain\PagePublishedEvent;
use App\Plugin\Event\Domain\UserCreatedEvent;
use App\Plugin\Event\Domain\UserDeletedEvent;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\ACL\ACLService;
use App\Service\Auth\ProfileService;
use App\Service\Auth\RegistrationService;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\Plugin\QaDomainEventListener;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * C24, the user and page events: creating a user (the admin screen, the CSV import that also serves bulk
 * creation, registration), deleting one (the admin screen, the bulk delete, the user's own account) and
 * publishing a page each tell the plugin that listens, once, after the commit, and never when the work is
 * rolled back. The record events belong to the writes of the data store and are tested there.
 *
 * The listening plugin is the QA items plugin ({@see QaItemPluginFixture}) with {@see QaDomainEventListener},
 * which lists `user.created`, `user.deleted` and `page.published`. Every path is the real service, not a call
 * to the dispatcher.
 */
#[PhpUnitGroup('security')]
final class PluginUserPageEventsTest extends QaKernelTestCase
{
    private QaDomainEventListener $listener;
    private Connection $connection;
    private AdminUserService $adminUsers;
    private UserFactory $users;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->service(QaDomainEventListener::class);
        $this->connection = $this->em->getConnection();
        $this->adminUsers = $this->service(AdminUserService::class);
        $this->users = new UserFactory($this->em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->adminId = (int) $admin->getId();
        $this->signInAsAdmin();

        QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            ['user.created', 'user.deleted', 'page.published'],
        );
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // == a user is created ==================================================================

    public function testCreatingAUserOnTheAdminScreenTellsTheListenerOnce(): void
    {
        $created = $this->adminUsers->createUser($this->adminId, $this->newUserData('single'));

        self::assertEquals([new UserCreatedEvent(self::asInt($created['id']))], $this->listener->handled);
    }

    /**
     * The CSV import is the one bulk way to create users (it calls `createUser` per row): one event for each user that is
     * really created, none for the row that was skipped (the user exists) or that failed.
     */
    public function testTheCsvImportTellsTheListenerOncePerCreatedUser(): void
    {
        $existing = $this->users->createUser('qa.p12.evt.import.exists@selfhelp.test', 'QA Exists');

        $result = $this->adminUsers->importUsers($this->adminId, [
            ['email' => 'qa.p12.evt.import.a@selfhelp.test', 'name' => 'QA A', 'user_name' => 'qa_p12_evt_import_a'],
            ['email' => $existing->getEmail() ?? '', 'name' => 'QA Exists'],
            ['email' => 'not-an-email', 'name' => 'QA Broken'],
            ['email' => 'qa.p12.evt.import.b@selfhelp.test', 'name' => 'QA B', 'user_name' => 'qa_p12_evt_import_b'],
        ]);

        self::assertSame(2, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
        self::assertEquals(
            [
                new UserCreatedEvent($this->userId('qa.p12.evt.import.a@selfhelp.test')),
                new UserCreatedEvent($this->userId('qa.p12.evt.import.b@selfhelp.test')),
            ],
            $this->listener->handled,
        );
    }

    public function testRegisteringTellsTheListenerOnce(): void
    {
        $group = (new GroupFactory($this->em))->createGroup('qa_p12_evt_register');
        $code = $this->seedRegistrationCode('QAP12EVT001', $group);
        $email = 'qa.p12.evt.register@selfhelp.test';

        $this->service(RegistrationService::class)->register($this->registerPageId(), $email, $code);

        self::assertEquals([new UserCreatedEvent($this->userId($email))], $this->listener->handled);
    }

    public function testARegistrationThatIsRefusedTellsNobody(): void
    {
        try {
            $this->service(RegistrationService::class)->register($this->registerPageId(), 'qa.p12.evt.refused@selfhelp.test', 'NOSUCHCODE0');
            self::fail('An unknown registration code is refused.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertSame([], $this->listener->handled);
    }

    // == a user is deleted ==================================================================

    public function testDeletingAUserOnTheAdminScreenTellsTheListenerOnce(): void
    {
        $user = $this->users->createUser('qa.p12.evt.delete@selfhelp.test', 'QA Delete');
        $userId = (int) $user->getId();

        $this->adminUsers->deleteUser($this->adminId, $userId);

        self::assertSame($this->usersWithEmail('qa.p12.evt.delete@selfhelp.test'), 0, 'the user is gone');
        self::assertEquals([new UserDeletedEvent($userId)], $this->listener->handled);
    }

    public function testTheBulkDeleteTellsTheListenerOncePerDeletedUser(): void
    {
        $first = (int) $this->users->createUser('qa.p12.evt.bulk.one@selfhelp.test', 'QA One')->getId();
        $second = (int) $this->users->createUser('qa.p12.evt.bulk.two@selfhelp.test', 'QA Two')->getId();
        $this->forgetEntities();

        $result = $this->adminUsers->bulkDeleteUsers($this->adminId, [$first, $second, 2_000_000_000]);

        $why = json_encode($result['failed']);
        self::assertSame([$first, $second], $result['succeeded'], "both users are deleted; failed: $why");
        self::assertCount(1, $result['failed'], 'the id nobody has fails');
        self::assertEquals([new UserDeletedEvent($first), new UserDeletedEvent($second)], $this->listener->handled);
    }

    public function testADeleteThatIsRefusedTellsNobody(): void
    {
        $user = $this->users->createUser('qa.p12.evt.system@selfhelp.test', 'QA System');
        $user->setIsSystem(true);
        $this->em->flush();

        try {
            $this->adminUsers->deleteUser($this->adminId, (int) $user->getId());
            self::fail('A system user cannot be deleted.');
        } catch (\Exception) {
            self::addToAssertionCount(1);
        }

        self::assertSame([], $this->listener->handled);
    }

    public function testDeletingYourOwnAccountTellsTheListenerOnce(): void
    {
        $user = $this->users->createUser('qa.p12.evt.own@selfhelp.test', 'QA Own');
        $userId = (int) $user->getId();

        $this->service(ProfileService::class)->deleteAccount($user, 'qa.p12.evt.own@selfhelp.test');

        self::assertSame($this->usersWithEmail('qa.p12.evt.own@selfhelp.test'), 0, 'the account is gone');
        self::assertEquals([new UserDeletedEvent($userId)], $this->listener->handled);
    }

    // == a page is published ================================================================

    public function testPublishingAPageTellsTheListenerOnceWithTheNewVersion(): void
    {
        $pageId = $this->newPage('qa_p12_evt_publish');
        $versions = $this->service(PageVersionService::class);

        $first = $versions->createVersion($pageId, 'qa_p12_evt_v1');
        self::assertSame([], $this->listener->handled, 'a version that is only saved is not published');

        $versions->publishVersion($pageId, (int) $first->getId());
        self::assertEquals([new PagePublishedEvent($pageId, $first->getVersionNumber())], $this->listener->handled);

        $second = $versions->createAndPublishVersion($pageId, 'qa_p12_evt_v2');
        self::assertEquals(
            [new PagePublishedEvent($pageId, $first->getVersionNumber()), new PagePublishedEvent($pageId, $second->getVersionNumber())],
            $this->listener->handled,
            'create and publish is one event, not two',
        );
        self::assertGreaterThan($first->getVersionNumber(), $second->getVersionNumber());
    }

    public function testUnpublishingAPageTellsNobody(): void
    {
        $pageId = $this->newPage('qa_p12_evt_unpublish');
        $versions = $this->service(PageVersionService::class);
        $versions->createAndPublishVersion($pageId, 'qa_p12_evt_unpublish_v1');
        $this->listener->handled = [];

        $versions->unpublishPage($pageId);

        self::assertSame([], $this->listener->handled);
    }

    // == after the commit, never on a rollback ==============================================

    /**
     * A caller that wraps the service in its own transaction (an import, a job) gets the event only when that
     * transaction commits. Each of the three kinds of change is run inside one: nothing is delivered while it is
     * open, and everything is delivered by the commit.
     */
    public function testEveryKindOfChangeWaitsForTheCommitOfTheTransactionAroundIt(): void
    {
        $doomed = $this->users->createUser('qa.p12.evt.wait.delete@selfhelp.test', 'QA Wait');
        $doomedId = (int) $doomed->getId();
        $pageId = $this->newPage('qa_p12_evt_wait');
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion($pageId, 'qa_p12_evt_wait_v1');
        $this->forgetEntities();

        $this->connection->beginTransaction();
        $created = $this->adminUsers->createUser($this->adminId, $this->newUserData('wait'));
        $this->adminUsers->deleteUser($this->adminId, $doomedId);
        $versions->publishVersion($pageId, (int) $version->getId());

        self::assertSame([], $this->listener->handled, 'nothing is stored for good yet, so nothing is told');

        $this->connection->commit();

        self::assertEquals(
            [
                new UserCreatedEvent(self::asInt($created['id'])),
                new UserDeletedEvent($doomedId),
                new PagePublishedEvent($pageId, $version->getVersionNumber()),
            ],
            $this->listener->handled,
            'the commit delivers each event once, in the order the changes were made',
        );
    }

    public function testNothingIsDeliveredWhenTheTransactionAroundTheChangeRollsBack(): void
    {
        $doomed = $this->users->createUser('qa.p12.evt.rollback.delete@selfhelp.test', 'QA Rollback');
        $doomedId = (int) $doomed->getId();
        $pageId = $this->newPage('qa_p12_evt_rollback');
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion($pageId, 'qa_p12_evt_rollback_v1');
        $this->forgetEntities();

        $this->connection->beginTransaction();
        $created = $this->adminUsers->createUser($this->adminId, $this->newUserData('rollback'));
        $this->adminUsers->deleteUser($this->adminId, $doomedId);
        $versions->publishVersion($pageId, (int) $version->getId());
        $this->connection->rollBack();

        // A later commit must not bring the dropped events back.
        $this->connection->beginTransaction();
        $this->connection->commit();

        self::assertSame([], $this->listener->handled, 'a change that was rolled back is never announced');
        self::assertSame(0, $this->usersWithEmail(self::asString($created['email'])), 'the created user was rolled back');
        self::assertSame(1, $this->usersWithEmail('qa.p12.evt.rollback.delete@selfhelp.test'), 'the deleted user is back');
    }

    // == helpers ============================================================================

    /**
     * @return array<string, mixed>
     */
    private function newUserData(string $slug): array
    {
        return [
            'email' => "qa.p12.evt.$slug@selfhelp.test",
            'name' => "qa_p12_evt_$slug",
            'user_name' => "qa_p12_evt_$slug",
            'password' => QaBaselineFixture::QA_PASSWORD,
            'enable_validation' => false,
        ];
    }

    private function newPage(string $keyword): int
    {
        $page = (new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        ))->createPage($keyword, false);

        return (int) $page->getId();
    }

    /**
     * What the factories built is flushed; forget it, as the next request would. A user made by `UserFactory` has a
     * validation code entity in memory that the delete of the user removes in SQL, and a later flush in the same
     * test (the next delete, a publish) would trip over it.
     */
    private function forgetEntities(): void
    {
        $this->em->clear();
        $this->signInAsAdmin();
    }

    /** Publishing checks the caller's grant on the page like every page write: act as the QA admin. */
    private function signInAsAdmin(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $this->service(UserContextService::class)->reset();
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "user $email exists");

        return (int) $user->getId();
    }

    private function usersWithEmail(string $email): int
    {
        return self::coerceInt($this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE email = :email', ['email' => $email]));
    }

    private function registerPageId(): int
    {
        return self::coerceInt($this->connection->fetchOne("SELECT id FROM pages WHERE keyword = 'register' LIMIT 1"));
    }

    private function seedRegistrationCode(string $code, Group $group): string
    {
        $entity = new ValidationCode();
        $entity->setCode($code);
        $entity->setGroup($group);
        $this->em->persist($entity);
        $this->em->flush();

        return $code;
    }
}
