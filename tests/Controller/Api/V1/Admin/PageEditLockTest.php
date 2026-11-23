<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\CMS\Admin\PageEditLockService;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ChangeNotifier;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\PhpUnit\Attribute\TimeSensitive;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Live sync contract C9: the edit lock of a page.
 *
 * Three editors with every route right: A creates the page (so A's role holds the
 * full grant), B is given read and update on it, C has no grant on it. Time is mocked with
 * ClockMock (`time()` inside {@see PageEditLockService}); a login token is issued
 * in real time, so the mocked clock never touches the JWT.
 */
#[Group('security')]
#[TimeSensitive(PageEditLockService::class)]
final class PageEditLockTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/admin/pages/';

    private const PERMISSIONS = [
        'admin.access',
        'admin.page.read',
        'admin.page.create',
        'admin.page.update',
        'admin.page_version.publish',
        'admin.page_version.delete',
    ];

    private EntityManagerInterface $em;
    private string $tokenA;
    private string $tokenB;
    private string $tokenC;
    private int $roleA;
    private int $roleB;
    private int $roleC;
    private int $pageId;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        $created = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $role = $roles->createRole('qa_lock_role_' . $letter);
            foreach (self::PERMISSIONS as $permission) {
                $roles->grantPermission($role, $permission);
            }
            $users->createUser(sprintf('qa.lock.%s@selfhelp.test', $letter), 'QA Lock ' . strtoupper($letter), roles: [$role]);
            $created[$letter] = (int) $role->getId();
        }
        $this->roleA = $created['a'];
        $this->roleB = $created['b'];
        $this->roleC = $created['c'];

        $this->tokenA = $this->loginAs('qa.lock.a@selfhelp.test');
        $this->tokenB = $this->loginAs('qa.lock.b@selfhelp.test');
        $this->tokenC = $this->loginAs('qa.lock.c@selfhelp.test');

        // Creating a page gives the creator's role the full grant (F14); B gets update, C gets nothing.
        $this->pageId = $this->createPage('qa_lock_page', $this->tokenA);
        $this->grantOnPage($this->roleB, $this->pageId, DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);

        $this->now = time();
        ClockMock::withClockMock($this->now);
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
        parent::tearDown();
    }

    // -- C9: who may hold the lock -------------------------------------------------------------------------------------

    public function testAnEditorAcquiresAFreeLockAndTheStatusIsPerCaller(): void
    {
        $data = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        self::assertTrue($data['held']);
        self::assertTrue($data['mine']);
        self::assertSame('QA Lock A', $this->holderName($data));
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now + 120), $data['expires_at']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now + 900), $data['idle_expires_at']);

        $asB = $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenB));
        self::assertTrue($asB['held']);
        self::assertFalse($asB['mine'], 'The same lock is not mine for another caller.');
        self::assertSame('QA Lock A', $this->holderName($asB));

        $free = $this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenA));
        self::assertFalse($free['held']);
        self::assertNull($free['holder']);
        self::assertFalse($this->assertEnvelopeSuccess($this->lock('GET', $this->tokenB))['held']);
    }

    public function testASecondEditorIsRefusedOnAcquireAndOnAnyDraftWrite(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $acquire = $this->lock('POST', $this->tokenB);
        $this->assertLocked($acquire);

        // A write with the right version is refused.
        $write = $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenB);
        $this->assertLocked($write);
        self::assertSame(1, $this->versionOf($this->pageId), 'A refused write does not move the version.');

        // The lock is checked before the version: a write without the header says "locked", not "version required".
        $noHeader = $this->jsonRequest('PUT', self::BASE . $this->pageId, [], $this->tokenB, [self::NO_PAGE_VERSION => '1']);
        $this->assertLocked($noHeader);

        // Reading the lock is allowed.
        $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenB));
    }

    public function testTakeOverMakesTheSecondEditorTheHolderAndRefusesTheFirst(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $taken = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenB, '/take-over'));
        self::assertTrue($taken['mine']);
        self::assertSame('QA Lock B', $this->holderName($taken));

        $this->assertLocked(
            $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenA),
            'QA Lock B',
        );
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenB),
        );

        // The first editor may take it back.
        $back = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA, '/take-over'));
        self::assertTrue($back['mine']);
    }

    public function testTakeOverOnAFreeLockJustTakesIt(): void
    {
        $data = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenB, '/take-over'));

        self::assertTrue($data['mine']);
        self::assertSame('QA Lock B', $this->holderName($data));
    }

    public function testOnlyTheHolderCanReleaseTheLock(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $stillHeld = $this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenB));
        self::assertTrue($stillHeld['held'], 'Someone else cannot give the holder\'s lock up.');
        self::assertFalse($stillHeld['mine']);

        self::assertFalse($this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenA))['held']);
    }

    public function testTheHolderRenewingKeepsTheLock(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $this->setClock(30);
        $renewed = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        self::assertTrue($renewed['mine']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now + 30 + 120), $renewed['expires_at']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now + 900), $renewed['idle_expires_at'], 'A renewal is not an edit.');
    }

    // -- C9: a write takes a free lock ---------------------------------------------------------------------------------

    public function testAWriteWithNoLockHeldLeavesTheCallerHoldingIt(): void
    {
        $before = $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenA));
        self::assertFalse($before['held']);

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenA),
        );

        $status = $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenA));
        self::assertTrue($status['held']);
        self::assertTrue($status['mine']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now + 900), $status['idle_expires_at']);

        // B now meets the lock A's write took.
        $this->assertLocked($this->lock('POST', $this->tokenB));
    }

    public function testAFailedWriteDoesNotLeaveTheLockBehind(): void
    {
        $this->jsonRequest('PUT', self::BASE . $this->pageId . '/sections/2147483000', [
            'sectionName' => 'x',
            'contentFields' => [],
            'propertyFields' => [],
        ], $this->tokenA);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());

        self::assertFalse($this->assertEnvelopeSuccess($this->lock('GET', $this->tokenA))['held']);
    }

    public function testAWriteRefusedForAStaleVersionDoesNotLeaveTheLockBehind(): void
    {
        $envelope = $this->jsonRequest('PUT', self::BASE . $this->pageId, [], $this->tokenA, ['HTTP_X_PAGE_VERSION' => '7']);

        self::assertSame(Response::HTTP_CONFLICT, $envelope['status'] ?? null);
        self::assertSame('stale_page_version', $envelope['error'] ?? null);
        self::assertFalse($this->assertEnvelopeSuccess($this->lock('GET', $this->tokenA))['held']);
    }

    public function testADraftWriteRenewsTheIdleEndOfTheHolder(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $this->setClock(600);
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA)); // keeps the 120 s lease alive
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenA),
        );

        $status = $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenA));
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now + 600 + 900), $status['idle_expires_at']);
    }

    // -- C9: the lease ends --------------------------------------------------------------------------------------------

    public function testTheLeaseEndsOneHundredTwentySecondsAfterTheLastRenewal(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $this->setClock(119);
        $this->assertLocked($this->lock('POST', $this->tokenB));

        // A renews at 100 s: the lease now runs to 220 s.
        $this->setClock(100);
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        $this->setClock(219);
        $this->assertLocked($this->lock('POST', $this->tokenB));

        $this->setClock(221);
        $taken = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenB));
        self::assertTrue($taken['mine'], 'After the lease ended B acquires without take-over.');
        self::assertSame('QA Lock B', $this->holderName($taken));
    }

    public function testTheLeaseEndsFifteenMinutesAfterTheLastDraftWriteEvenWhenRenewed(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $this->setClock(60);
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenA),
        ); // the last draft write, at 60 s

        // A keeps the heartbeat going every 100 s and never writes again.
        for ($at = 100; $at <= 900; $at += 100) {
            $this->setClock($at);
            $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        }

        $this->setClock(959);
        $this->assertLocked($this->lock('POST', $this->tokenB));

        $this->setClock(961);
        $taken = $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenB));
        self::assertSame('QA Lock B', $this->holderName($taken), 'Fifteen minutes after the last write the lock is free again.');
    }

    public function testALockNeverEditedEndsFifteenMinutesAfterAcquisition(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        for ($at = 100; $at <= 800; $at += 100) {
            $this->setClock($at);
            $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        }

        $this->setClock(899);
        $this->assertLocked($this->lock('POST', $this->tokenB));
        $this->setClock(901);
        self::assertTrue($this->assertEnvelopeSuccess($this->lock('POST', $this->tokenB))['mine']);
    }

    public function testAnEndedLeaseDoesNotBlockADraftWrite(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        $this->setClock(121);

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenB),
        );

        $status = $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenB));
        self::assertTrue($status['mine']);
    }

    // -- C9: change notices --------------------------------------------------------------------------------------------

    public function testAcquireTakeOverAndReleaseAreAnnouncedToTheEditorsOfThePage(): void
    {
        $expected = $this->editorTopics();

        $this->recorder()->reset();
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        self::assertSame($expected, $this->lockTopics(), 'Acquire reaches the roles with a grant on the page, and no other.');
        self::assertNotContains($this->service(MercureTopicResolver::class)->syncRoleTopic($this->roleC), $expected);

        $this->recorder()->reset();
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenB, '/take-over'));
        self::assertSame($expected, $this->lockTopics(), 'Take-over reaches them too.');

        $this->recorder()->reset();
        $this->assertEnvelopeSuccess($this->lock('DELETE', $this->tokenB));
        self::assertSame($expected, $this->lockTopics(), 'Release reaches them too.');
    }

    public function testRenewingAndRefusedCallsSendNothing(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        $this->recorder()->reset();
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA)); // renewal by the holder
        $this->lock('POST', $this->tokenB); // refused
        $this->lock('GET', $this->tokenB);
        self::assertSame([], $this->lockTopics());
    }

    public function testAnEndedLeaseIsAnnouncedWhenItIsNoticed(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));
        $this->setClock(121);

        $this->recorder()->reset();
        $this->assertEnvelopeSuccess($this->lock('GET', $this->tokenB));

        self::assertSame($this->editorTopics(), $this->lockTopics());
    }

    // -- routes: permissions and the contract --------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lockRoutes(): iterable
    {
        yield 'get' => ['GET', ''];
        yield 'acquire' => ['POST', ''];
        yield 'release' => ['DELETE', ''];
        yield 'take-over' => ['POST', '/take-over'];
    }

    #[DataProvider('lockRoutes')]
    public function testTheLockRoutesPassThePermissionMatrix(string $method, string $suffix): void
    {
        $uri = self::BASE . $this->pageId . '/lock' . $suffix;

        // Allowed: an editor with the route right and the update grant.
        $allowed = $this->jsonRequest($method, $uri, null, $this->tokenA);
        self::assertSame(Response::HTTP_OK, $allowed['status'] ?? null, 'The editor must be allowed.');
        $this->assertMatchesLockSchema();
        $this->jsonRequest('DELETE', self::BASE . $this->pageId . '/lock', null, $this->tokenA); // leave no lock behind

        // Read-only on pages, and the other personas without the right.
        $readOnly = $this->loginAs(QaBaselineFixture::readPersonaEmail('admin.page.read'));
        foreach ([
            'qa.read.page' => $readOnly,
            'qa.editor' => $this->loginAsQaEditor(),
            'qa.user' => $this->loginAsQaUser(),
            'qa.guest' => $this->loginAsQaGuest(),
        ] as $label => $token) {
            $refused = $this->jsonRequest($method, $uri, null, $token);
            self::assertSame(Response::HTTP_FORBIDDEN, $refused['status'] ?? null, $label . ' must be refused (403).');
        }

        // The route right without a grant on this page.
        $noGrant = $this->jsonRequest($method, $uri, null, $this->tokenC);
        self::assertContains($noGrant['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], 'No grant on the page: 403 or 404.');

        // Anonymous.
        $anonymous = $this->jsonRequest($method, $uri, null, null);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $anonymous['status'] ?? null);
    }

    public function testAnEditorWithoutAGrantNeitherSeesNorChangesTheLock(): void
    {
        $this->assertEnvelopeSuccess($this->lock('POST', $this->tokenA));

        foreach ([['GET', ''], ['POST', ''], ['POST', '/take-over'], ['DELETE', '']] as [$method, $suffix]) {
            $envelope = $this->lock($method, $this->tokenC, $suffix);
            self::assertContains($envelope['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
            self::assertNotSame('page_locked', $envelope['error'] ?? null, 'Whether the page is locked is not told to someone who may not edit it.');
        }

        $write = $this->jsonRequest('PUT', self::BASE . $this->pageId, ['pageData' => ['headless' => false], 'fields' => []], $this->tokenC);
        self::assertContains($write['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
        self::assertNotSame('page_locked', $write['error'] ?? null);
        self::assertTrue($this->assertEnvelopeSuccess($this->lock('GET', $this->tokenA))['mine'], 'The lock is untouched.');
    }

    public function testALockOnAMissingPageAnswersNotFound(): void
    {
        $envelope = $this->jsonRequest('POST', self::BASE . '2147483000/lock', null, $this->tokenA);

        self::assertContains($envelope['status'] ?? null, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
    }

    // -- helpers -------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function lock(string $method, string $token, string $suffix = ''): array
    {
        return $this->jsonRequest($method, self::BASE . $this->pageId . '/lock' . $suffix, null, $token);
    }

    /**
     * Moves the mocked clock to `$seconds` after the start of the test.
     */
    private function setClock(int $seconds): void
    {
        ClockMock::withClockMock($this->now + $seconds);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertLocked(array $envelope, string $holder = 'QA Lock A'): void
    {
        self::assertSame(Response::HTTP_CONFLICT, $envelope['status'] ?? null);
        self::assertSame(PageEditLockService::ERROR_LOCKED, $envelope['error'] ?? null);
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);
        self::assertTrue($data['held'] ?? null);
        self::assertFalse($data['mine'] ?? null);
        self::assertSame($holder, $this->holderName($this->narrow($data)), 'The answer names who is editing.');
        self::assertSame($this->pageId, $data['page_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function holderName(array $data): ?string
    {
        $holder = $data['holder'] ?? null;
        if (!is_array($holder)) {
            return null;
        }
        $name = $holder['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function narrow(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    private function assertMatchesLockSchema(): void
    {
        $raw = json_decode((string) $this->client->getResponse()->getContent());
        self::assertIsObject($raw);
        $errors = $this->service(JsonSchemaValidationService::class)->validate($raw, 'responses/admin/pages/page_lock');
        self::assertSame([], $errors, "The lock response failed its schema:\n" . implode("\n", $errors));
    }

    private function recorder(): MercureTestRecorder
    {
        $recorder = self::getContainer()->get(MercureTestRecorder::class);
        self::assertInstanceOf(MercureTestRecorder::class, $recorder);

        return $recorder;
    }

    /**
     * Topics that received a `page_lock:<page>` tag in the last request, sorted.
     *
     * @return list<string>
     */
    private function lockTopics(): array
    {
        $topics = [];
        foreach ($this->recorder()->getUpdates() as $update) {
            if ($update->getType() !== ChangeNotifier::EVENT_NAME) {
                continue;
            }
            $decoded = json_decode($update->getData(), true);
            self::assertIsArray($decoded);
            $tags = $decoded['tags'] ?? [];
            if (!is_array($tags) || !in_array('page_lock:' . $this->pageId, $tags, true)) {
                continue;
            }
            foreach ($update->getTopics() as $topic) {
                self::assertIsString($topic);
                $topics[] = $topic;
            }
        }
        sort($topics);

        return $topics;
    }

    /**
     * The roles that edit the page: A and B, which hold a grant on it, and the superuser role, which edits everything.
     *
     * @return list<string>
     */
    private function editorTopics(): array
    {
        $adminRoleId = self::asInt($this->em->getConnection()->fetchOne("SELECT id FROM roles WHERE name = 'admin'"));
        self::assertGreaterThan(0, $adminRoleId);

        return $this->sortedTopics([$this->roleA, $this->roleB, $adminRoleId]);
    }

    /**
     * @param list<int> $roleIds
     * @return list<string>
     */
    private function sortedTopics(array $roleIds): array
    {
        $resolver = $this->service(MercureTopicResolver::class);
        $topics = array_map(static fn (int $id): string => $resolver->syncRoleTopic($id), $roleIds);
        sort($topics);

        return $topics;
    }

    private function versionOf(int $pageId): int
    {
        return self::asInt($this->em->getConnection()->fetchOne('SELECT draft_version FROM pages WHERE id = ?', [$pageId]));
    }

    private function createPage(string $keyword, string $token): int
    {
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'url' => '/' . $keyword,
            ], $token),
            Response::HTTP_CREATED,
        );
        $pageId = self::asInt($created['id'] ?? null);
        self::assertGreaterThan(0, $pageId);

        return $pageId;
    }

    private function grantOnPage(int $roleId, int $pageId, int $crud): void
    {
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES,
        );
        self::assertNotNull($typeId);
        $type = $this->em->find(Lookup::class, $typeId);
        self::assertInstanceOf(Lookup::class, $type);
        $role = $this->em->find(Role::class, $roleId);
        self::assertInstanceOf(Role::class, $role);

        $grant = (new RoleDataAccess())
            ->setRole($role)
            ->setResourceType($type)
            ->setResourceId($pageId)
            ->setCrudPermissions($crud);
        $this->em->persist($grant);
        $this->em->flush();

        $this->service(DataAccessSecurityService::class)->invalidateRolePermissions($roleId);
    }
}
