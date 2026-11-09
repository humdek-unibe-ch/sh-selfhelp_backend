<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Users Management page: stats tiles, status/group filters, bulk actions and
 * CSV export/import.
 *
 * Test impact analysis: these endpoints back the admin Users page. The
 * behaviours that can silently break and mislead an admin are (a) the tiles
 * disagreeing with the table underneath them, (b) a bulk action failing
 * wholesale because one id was bad, and (c) an import quietly emailing every
 * imported user. Each has a test below.
 */
#[TestGroup('security')]
final class AdminUserManagementTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const USERS_URI = '/cms-api/v1/admin/users';
    private const STATS_URI = '/cms-api/v1/admin/users/stats';
    private const BULK_DELETE_URI = '/cms-api/v1/admin/users/bulk-delete';
    private const BULK_ADD_GROUP_URI = '/cms-api/v1/admin/users/bulk-add-to-group';
    private const BULK_REMOVE_GROUP_URI = '/cms-api/v1/admin/users/bulk-remove-from-group';
    private const BULK_ACTIVATION_URI = '/cms-api/v1/admin/users/bulk-send-activation';
    private const EXPORT_URI = '/cms-api/v1/admin/users/export';
    private const IMPORT_URI = '/cms-api/v1/admin/users/import';
    private const IMPORT_HEADER = "email,name,user_name,groups\n";

    /**
     * DAMA rolls the DATABASE back after each test, but the cache pool is a
     * filesystem adapter that survives the rollback — so a cached user list or
     * stats payload can outlive the rows it described and make an unrelated
     * test read counts for users that no longer exist. Drop the user-facing
     * caches up front so each test starts from a state consistent with the DB
     * (Testing Rule 14: no order-dependent tests).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $cache = $this->service(CacheService::class);
        $cache->withCategory(CacheService::CATEGORY_USERS)->invalidateAllListsInCategory();
        $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)->invalidateAllListsInCategory();
    }

    // -- §1 stats -----------------------------------------------------------

    public function testStatsReturnsCountsPerStatusBucket(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::STATS_URI, null, $this->loginAsQaAdmin())
        );

        foreach (['total', 'active', 'invited', 'blocked'] as $bucket) {
            self::assertArrayHasKey($bucket, $data, sprintf('Tile "%s" must be present', $bucket));
            self::assertIsInt($data[$bucket]);
            self::assertGreaterThanOrEqual(0, $data[$bucket]);
        }

        // The QA baseline seeds active personas, so `active` must be non-zero:
        // an all-zero payload would pass the shape assertions above while
        // telling the admin nothing.
        self::assertGreaterThan(0, $this->intValue($data, 'active'));
    }

    /**
     * The tiles must describe the same population as the table. If `total`
     * drifts from the unfiltered list's totalCount, an admin sees counts that
     * contradict the rows underneath them and reasonably concludes the page is
     * broken. This is why stats are scoped to the caller's visible set.
     */
    public function testStatsTotalReconcilesWithUnfilteredUserList(): void
    {
        $token = $this->loginAsQaAdmin();

        $stats = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::STATS_URI, null, $token));
        $list = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::USERS_URI . '?page=1&pageSize=1', null, $token)
        );

        $pagination = $list['pagination'] ?? null;
        self::assertIsArray($pagination);
        self::assertArrayHasKey('totalCount', $pagination);
        self::assertIsInt($pagination['totalCount']);

        self::assertSame(
            $pagination['totalCount'],
            $this->intValue($stats, 'total'),
            'stats.total must equal the unfiltered list totalCount for the same admin'
        );
    }

    /**
     * `blocked` wins over status: a blocked user must be counted once, under
     * `blocked`, and never also under their status bucket.
     *
     * The user is blocked through the real block endpoint rather than by
     * writing the entity directly, so this also covers the cache invalidation
     * an admin depends on — stale tiles would be indistinguishable from wrong
     * ones on screen.
     */
    public function testBlockedUserIsCountedOnlyInBlockedBucket(): void
    {
        $token = $this->loginAsQaAdmin();
        $userId = $this->createQaUser('qa.blocked.counted@selfhelp.test', LookupService::USER_STATUS_INVITED);

        $before = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::STATS_URI, null, $token));
        self::assertGreaterThan(0, $this->intValue($before, 'invited'), 'The seeded user must start out invited');

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PATCH', self::USERS_URI . '/' .$userId . '/block', ['blocked' => true], $token)
        );

        $after = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::STATS_URI, null, $token));

        self::assertSame(
            $this->intValue($before, 'blocked') + 1,
            $this->intValue($after, 'blocked'),
            'Blocking a user must move them into the blocked bucket'
        );
        self::assertSame(
            $this->intValue($before, 'invited') - 1,
            $this->intValue($after, 'invited'),
            'blocked wins over status: a blocked+invited user must NOT still count as invited'
        );
        self::assertSame(
            $this->intValue($before, 'total'),
            $this->intValue($after, 'total'),
            'Blocking moves a user between buckets; it must not change the population'
        );
    }

    // -- §2 filters ---------------------------------------------------------

    public function testStatusFilterReturnsOnlyMatchingUsers(): void
    {
        $token = $this->loginAsQaAdmin();
        $this->createQaUser('qa.filter.invited@selfhelp.test', LookupService::USER_STATUS_INVITED);

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::USERS_URI . '?status=invited&pageSize=100', null, $token)
        );

        $emails = $this->emailsOf($data);
        self::assertNotEmpty($emails, 'The invited filter must return the seeded invited user');
        self::assertContains('qa.filter.invited@selfhelp.test', $emails);
        self::assertNotContains(
            QaBaselineFixture::QA_ADMIN_EMAIL,
            $emails,
            'qa.admin is active, so it must not appear under status=invited'
        );
    }

    public function testUnknownStatusFilterIsRejected(): void
    {
        $envelope = $this->jsonRequest(
            'GET',
            self::USERS_URI . '?status=locked',
            null,
            $this->loginAsQaAdmin()
        );

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $envelope['status'] ?? null,
            'An unsupported status must 400 rather than silently returning an unfiltered list'
        );
    }

    public function testGroupFilterReturnsOnlyMembersOfThatGroup(): void
    {
        $token = $this->loginAsQaAdmin();
        $groupId = $this->groupIdByName('admin');

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::USERS_URI . '?id_groups=' . $groupId . '&pageSize=100', null, $token)
        );

        $emails = $this->emailsOf($data);
        self::assertContains(QaBaselineFixture::QA_ADMIN_EMAIL, $emails);
        self::assertNotContains(
            QaBaselineFixture::QA_USER_EMAIL,
            $emails,
            'qa.user is in the subject group, so it must not appear under the admin group filter'
        );
    }

    // -- §3 bulk delete -----------------------------------------------------

    /**
     * Partial success is the contract: one bad id must not discard the rest of
     * the admin's selection.
     */
    public function testBulkDeleteReportsPerUserFailuresWithoutFailingWholeRequest(): void
    {
        $token = $this->loginAsQaAdmin();
        $deletable = $this->createQaUser('qa.bulk.deletable@selfhelp.test', LookupService::USER_STATUS_ACTIVE);

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BULK_DELETE_URI, ['user_ids' => [$deletable, 99999999]], $token)
        );

        self::assertSame([$deletable], $data['succeeded']);

        $failed = $this->failuresOf($data);
        self::assertCount(1, $failed);
        self::assertSame(99999999, $failed[0]['id']);
        self::assertNotSame('', $failed[0]['reason']);

        // The public side effect: the user is really gone.
        $lookup = $this->jsonRequest('GET', self::USERS_URI . '/' .$deletable, null, $token);
        self::assertSame(Response::HTTP_NOT_FOUND, $lookup['status'] ?? null);
    }

    /**
     * Deleting yourself mid-session is unrecoverable, so it must fail as a
     * per-user entry while the rest of the selection still applies.
     */
    public function testBulkDeleteRefusesToDeleteTheRequestingAdminsOwnAccount(): void
    {
        $token = $this->loginAsQaAdmin();
        $adminId = $this->userIdByEmail(QaBaselineFixture::QA_ADMIN_EMAIL);
        $other = $this->createQaUser('qa.bulk.other@selfhelp.test', LookupService::USER_STATUS_ACTIVE);

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BULK_DELETE_URI, ['user_ids' => [$adminId, $other]], $token)
        );

        self::assertSame([$other], $data['succeeded'], 'The other user must still be deleted');

        $failed = $this->failuresOf($data);
        self::assertCount(1, $failed);
        self::assertSame($adminId, $failed[0]['id']);
        self::assertSame('Cannot delete your own account', $failed[0]['reason']);

        // The admin still exists.
        $self = $this->jsonRequest('GET', self::USERS_URI . '/' .$adminId, null, $token);
        self::assertSame(Response::HTTP_OK, $self['status'] ?? null);
    }

    public function testBulkDeleteHandlesASingleIdIdenticallyToTheSingleDeleteEndpoint(): void
    {
        $token = $this->loginAsQaAdmin();
        $userId = $this->createQaUser('qa.bulk.single@selfhelp.test', LookupService::USER_STATUS_ACTIVE);

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BULK_DELETE_URI, ['user_ids' => [$userId]], $token)
        );

        self::assertSame([$userId], $data['succeeded']);
        self::assertSame([], $data['failed']);
    }

    // -- §4 bulk add to group -----------------------------------------------

    public function testBulkAddToGroupIsIdempotentAndCreatesNoDuplicateMembership(): void
    {
        $token = $this->loginAsQaAdmin();
        $userId = $this->createQaUser('qa.bulk.grouped@selfhelp.test', LookupService::USER_STATUS_ACTIVE);
        $groupId = $this->groupIdByName('therapist');

        $body = ['user_ids' => [$userId], 'group_ids' => [$groupId]];

        $first = $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::BULK_ADD_GROUP_URI, $body, $token));
        self::assertSame([$userId], $first['succeeded']);

        // Re-adding an existing member is a success, not a failure.
        $second = $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::BULK_ADD_GROUP_URI, $body, $token));
        self::assertSame([$userId], $second['succeeded']);
        self::assertSame([], $second['failed']);

        // And it must not have created a duplicate row.
        $memberships = $this->entityManager()->getRepository(UsersGroup::class)->count([
            'user' => $this->entityManager()->getReference(User::class, $userId),
            'group' => $this->entityManager()->getReference(Group::class, $groupId),
        ]);
        self::assertSame(1, $memberships, 'Re-adding a member must not duplicate the rel_groups_users row');
    }

    // -- bulk remove from group ---------------------------------------------

    public function testBulkRemoveFromGroupIsIdempotentForNonMembers(): void
    {
        $token = $this->loginAsQaAdmin();
        $userId = $this->createQaUser('qa.bulk.ungrouped@selfhelp.test', LookupService::USER_STATUS_ACTIVE);
        $groupId = $this->groupIdByName('therapist');

        $body = ['user_ids' => [$userId], 'group_ids' => [$groupId]];

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::BULK_ADD_GROUP_URI, $body, $token));

        $first = $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::BULK_REMOVE_GROUP_URI, $body, $token));
        self::assertSame([$userId], $first['succeeded']);

        // Removing someone who is no longer a member is a success, mirroring
        // add treating an existing member as a success.
        $second = $this->assertEnvelopeSuccess($this->jsonRequest('POST', self::BULK_REMOVE_GROUP_URI, $body, $token));
        self::assertSame([$userId], $second['succeeded']);
        self::assertSame([], $second['failed']);

        $memberships = $this->entityManager()->getRepository(UsersGroup::class)->count([
            'user' => $this->entityManager()->getReference(User::class, $userId),
            'group' => $this->entityManager()->getReference(Group::class, $groupId),
        ]);
        self::assertSame(0, $memberships, 'The membership row must be gone');
    }

    public function testBulkRemoveFromGroupRejectsUnknownGroupForTheWholeRequest(): void
    {
        $token = $this->loginAsQaAdmin();
        $userId = $this->createQaUser('qa.bulk.rmbadgroup@selfhelp.test', LookupService::USER_STATUS_ACTIVE);

        $envelope = $this->jsonRequest(
            'POST',
            self::BULK_REMOVE_GROUP_URI,
            ['user_ids' => [$userId], 'group_ids' => [99999999]],
            $token
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $envelope['status'] ?? null);
    }

    /**
     * No self-lockout guard is needed on bulk-remove: admin access comes from
     * the `admin` ROLE, not group membership, so an admin who removes
     * themselves from every group keeps admin access and can re-add
     * themselves. This proves that claim rather than trusting the comment on
     * AdminUserService::bulkRemoveUsersFromGroups() — if admin access ever
     * becomes group-derived, this test fails and the guard becomes required.
     */
    public function testAdminRemovingThemselvesFromTheirGroupKeepsAdminAccess(): void
    {
        $token = $this->loginAsQaAdmin();
        $adminId = $this->userIdByEmail(QaBaselineFixture::QA_ADMIN_EMAIL);
        $adminGroupId = $this->groupIdByName('admin');

        $result = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                self::BULK_REMOVE_GROUP_URI,
                ['user_ids' => [$adminId], 'group_ids' => [$adminGroupId]],
                $token
            )
        );
        self::assertSame([$adminId], $result['succeeded'], 'Removing yourself from a group is allowed');

        // The real assertion: an admin-only endpoint still answers 200.
        $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::STATS_URI, null, $token));
    }

    public function testBulkRemoveFromGroupIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BULK_REMOVE_GROUP_URI, ['user_ids' => [1], 'group_ids' => [1]]);
    }

    public function testBulkAddToGroupRejectsUnknownGroupForTheWholeRequest(): void
    {
        $token = $this->loginAsQaAdmin();
        $userId = $this->createQaUser('qa.bulk.badgroup@selfhelp.test', LookupService::USER_STATUS_ACTIVE);

        $envelope = $this->jsonRequest(
            'POST',
            self::BULK_ADD_GROUP_URI,
            ['user_ids' => [$userId], 'group_ids' => [99999999]],
            $token
        );

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $envelope['status'] ?? null,
            'An unknown group id is a caller bug, so the whole request must 400'
        );
    }

    // -- §5 bulk send activation --------------------------------------------

    public function testBulkSendActivationReportsAlreadyActiveUsersAsFailures(): void
    {
        $token = $this->loginAsQaAdmin();
        $invited = $this->createQaUser('qa.bulk.invited@selfhelp.test', LookupService::USER_STATUS_INVITED);
        $active = $this->createQaUser('qa.bulk.active@selfhelp.test', LookupService::USER_STATUS_ACTIVE);

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::BULK_ACTIVATION_URI, ['user_ids' => [$invited, $active]], $token)
        );

        self::assertSame([$invited], $data['succeeded']);

        $failed = $this->failuresOf($data);
        self::assertCount(1, $failed);
        self::assertSame($active, $failed[0]['id']);
        self::assertSame(
            'User is already active',
            $failed[0]['reason'],
            'The admin must be told why the success count is lower than their selection'
        );
    }

    // -- §6 export ----------------------------------------------------------

    public function testExportReturnsCsvWithTheContractHeaderAndHonoursFilters(): void
    {
        $token = $this->loginAsQaAdmin();
        $this->createQaUser('qa.export.invited@selfhelp.test', LookupService::USER_STATUS_INVITED);

        $this->client->request('GET', self::EXPORT_URI . '?status=invited', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/attachment; filename="users_\d{8}_\d{6}\.csv"/',
            (string) $response->headers->get('Content-Disposition')
        );

        $csv = $this->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        self::assertSame(
            'id,email,name,user_name,status,blocked,groups,roles,last_login',
            trim($lines[0]),
            'The CSV header is a frontend contract'
        );
        self::assertStringContainsString('qa.export.invited@selfhelp.test', $csv);
        self::assertStringNotContainsString(
            QaBaselineFixture::QA_ADMIN_EMAIL,
            $csv,
            'The export must honour the status filter, not dump the whole table'
        );
    }

    // -- §7 import ----------------------------------------------------------

    /**
     * Import must not email the users it creates: a large import firing
     * hundreds of unrecallable activation mails is the footgun this endpoint
     * exists to avoid. The admin invites them deliberately via §5.
     */
    public function testImportCreatesUsersWithoutSendingActivationEmails(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->uploadCsv(
                self::IMPORT_HEADER
                ."qa.import.one@selfhelp.test,QA Import One,qa_import_one,\n",
                $this->loginAsQaAdmin()
            )
        );

        self::assertSame(1, $this->intValue($data, 'imported'));
        self::assertSame(0, $this->intValue($data, 'skipped'));
        self::assertSame([], $data['errors']);

        // The imported user exists...
        $user = $this->entityManager()->getRepository(User::class)
            ->findOneBy(['email' => 'qa.import.one@selfhelp.test']);
        self::assertInstanceOf(User::class, $user);

        // ...and was issued no validation code, i.e. no activation mail.
        self::assertCount(
            0,
            $user->getValidationCodes(),
            'An imported user must not be issued a validation code / activation email'
        );
    }

    public function testImportSkipsExistingEmailsAndReportsBadRowsByFileLineNumber(): void
    {
        // Row 2 is valid, row 3 duplicates an existing user (skip), row 4 has a
        // malformed email (error).
        $data = $this->assertEnvelopeSuccess(
            $this->uploadCsv(
                self::IMPORT_HEADER
                ."qa.import.new@selfhelp.test,QA New,qa_import_new,\n"
                . QaBaselineFixture::QA_ADMIN_EMAIL . ",Dupe,qa_dupe,\n"
                . "not-an-email,QA Bad,qa_bad,\n",
                $this->loginAsQaAdmin()
            )
        );

        self::assertSame(1, $this->intValue($data, 'imported'), 'Valid rows import even when others fail');
        self::assertSame(1, $this->intValue($data, 'skipped'), 'An existing email is a skip, not an error');

        $errors = $this->importErrorsOf($data);
        self::assertCount(1, $errors);
        self::assertSame(4, $errors[0]['row'], "The row number must match the line in the admin's spreadsheet");
        self::assertSame('Invalid email address', $errors[0]['message']);
    }

    public function testImportRejectsFileWithMissingRequiredHeader(): void
    {
        $envelope = $this->uploadCsv("name,user_name\nQA,qa_x\n", $this->loginAsQaAdmin());

        self::assertSame(Response::HTTP_BAD_REQUEST, $envelope['status'] ?? null);
    }

    public function testImportErrorsRowWithUnknownGroupName(): void
    {
        $data = $this->assertEnvelopeSuccess(
            $this->uploadCsv(
                self::IMPORT_HEADER
                ."qa.import.badgroup@selfhelp.test,QA Bad Group,qa_import_badgroup,no_such_group\n",
                $this->loginAsQaAdmin()
            )
        );

        self::assertSame(0, $this->intValue($data, 'imported'), 'An unknown group must not implicitly be created');

        $errors = $this->importErrorsOf($data);
        self::assertCount(1, $errors);
        self::assertStringContainsString('no_such_group', $errors[0]['message']);
    }

    // -- Cross-cutting: permission matrix -----------------------------------

    public function testStatsIsAdminOnly(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::STATS_URI);
    }

    public function testBulkDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BULK_DELETE_URI, ['user_ids' => [1]]);
    }

    public function testBulkAddToGroupIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BULK_ADD_GROUP_URI, ['user_ids' => [1], 'group_ids' => [1]]);
    }

    public function testBulkSendActivationIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::BULK_ACTIVATION_URI, ['user_ids' => [1]]);
    }

    public function testImportIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', self::IMPORT_URI);
    }

    /**
     * The export streams raw CSV rather than the JSON envelope, so the shared
     * matrix helper (which decodes an envelope) does not apply. Assert the
     * same admin/non-admin/anonymous contract directly.
     */
    public function testExportIsAdminOnly(): void
    {
        $this->client->request('GET', self::EXPORT_URI, [], [], $this->authHeaders($this->loginAsQaAdmin()));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        foreach ([QaBaselineFixture::QA_EDITOR_EMAIL, QaBaselineFixture::QA_USER_EMAIL, QaBaselineFixture::QA_GUEST_EMAIL] as $email) {
            $this->client->request('GET', self::EXPORT_URI, [], [], $this->authHeaders($this->loginAs($email)));
            self::assertSame(
                Response::HTTP_FORBIDDEN,
                $this->client->getResponse()->getStatusCode(),
                sprintf('%s must be forbidden on the CSV export', $email)
            );
        }

        $this->client->request('GET', self::EXPORT_URI);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
            'Anonymous requests must not reach the CSV export'
        );
    }

    // -- Helpers ------------------------------------------------------------

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intValue(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        self::assertIsInt($value, sprintf('"%s" must be an integer', $key));

        return $value;
    }

    /**
     * Email addresses from a users-list payload.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function emailsOf(array $data): array
    {
        $users = $data['users'] ?? null;
        self::assertIsArray($users);

        $emails = [];
        foreach ($users as $user) {
            self::assertIsArray($user);
            self::assertArrayHasKey('email', $user);
            self::assertIsString($user['email']);
            $emails[] = $user['email'];
        }

        return $emails;
    }

    /**
     * Typed `failed` entries from a bulk-operation payload.
     *
     * @param array<string, mixed> $data
     * @return list<array{id: int, reason: string}>
     */
    private function failuresOf(array $data): array
    {
        $failed = $data['failed'] ?? null;
        self::assertIsArray($failed);

        $result = [];
        foreach ($failed as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('id', $entry);
            self::assertArrayHasKey('reason', $entry);
            self::assertIsInt($entry['id']);
            self::assertIsString($entry['reason']);
            $result[] = ['id' => $entry['id'], 'reason' => $entry['reason']];
        }

        return $result;
    }

    /**
     * Typed `errors` entries from an import payload.
     *
     * @param array<string, mixed> $data
     * @return list<array{row: int, message: string}>
     */
    private function importErrorsOf(array $data): array
    {
        $errors = $data['errors'] ?? null;
        self::assertIsArray($errors);

        $result = [];
        foreach ($errors as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('row', $entry);
            self::assertArrayHasKey('message', $entry);
            self::assertIsInt($entry['row']);
            self::assertIsString($entry['message']);
            $result[] = ['row' => $entry['row'], 'message' => $entry['message']];
        }

        return $result;
    }

    /**
     * Read a streamed CSV body.
     *
     * StreamedResponse::getContent() returns false (the body is echoed by a
     * callback, never buffered), but the functional client has already drained
     * the stream into the BrowserKit internal response.
     */
    private function streamedContent(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * Seed a QA user through the production entity model (Testing Rule 8: no
     * raw SQL, same lookups/groups as CreateAdminUserCommand).
     */
    private function createQaUser(string $email, string $statusCode, bool $blocked = false): int
    {
        $em = $this->entityManager();

        $status = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => $statusCode,
        ]);
        $userType = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_TYPES,
            'lookupCode' => LookupService::USER_TYPES_USER,
        ]);
        self::assertInstanceOf(Lookup::class, $status);
        self::assertInstanceOf(Lookup::class, $userType);

        $user = new User();
        $user->setEmail($email);
        $user->setName('QA ' . $email);
        $user->setUserName(str_replace(['@', '.'], '_', $email));
        $user->setStatus($status);
        $user->setUserType($userType);
        $user->setBlocked($blocked);
        $user->setIntern(false);
        $user->setPassword(
            $this->service(UserPasswordHasherInterface::class)
                ->hashPassword($user, QaBaselineFixture::QA_PASSWORD)
        );

        $em->persist($user);
        $em->flush();

        return (int) $user->getId();
    }

    private function userIdByEmail(string $email): int
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }

    private function groupIdByName(string $name): int
    {
        $group = $this->entityManager()->getRepository(Group::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Group::class, $group);

        return (int) $group->getId();
    }

    /**
     * POST a CSV to the import endpoint as a real multipart upload.
     *
     * @return array<string, mixed>
     */
    private function uploadCsv(string $contents, string $token): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qa_users_');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        try {
            $this->client->request(
                'POST',
                self::IMPORT_URI,
                [],
                ['file' => new UploadedFile($path, 'users.csv', 'text/csv', null, true)],
                ['HTTP_Authorization' => 'Bearer ' . $token]
            );

            return $this->decode($this->client->getResponse());
        } finally {
            @unlink($path);
        }
    }
}
