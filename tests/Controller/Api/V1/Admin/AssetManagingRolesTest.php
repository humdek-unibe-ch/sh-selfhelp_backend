<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Asset;
use App\Entity\DataAccessAudit;
use App\Entity\Role;
use App\Entity\Transaction;
use App\Entity\User;
use App\Service\CMS\Admin\AssetStorageService;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Who manages an asset and what a bulk action does to several of them.
 *
 * - A new upload is managed by the uploader's roles that may create assets
 *   (`admin.asset.create`), not by every role the uploader happens to have;
 *   an explicit `role_ids` list is taken as it is, except an empty one, which
 *   only an admin may send (the editor would lose the asset, F63).
 * - Those grants are written through the data-access service, so each change
 *   is logged like any other grant change.
 * - Bulk actions check every right BEFORE they change anything, so a refusal on
 *   the last id never leaves the first ones changed, and the refusal is still
 *   audited (the audit row is not rolled back with a half-done change).
 */
#[TestGroup('security')]
final class AssetManagingRolesTest extends QaWebTestCase
{
    private const MULTI_EMAIL = 'qa.asset.multi@selfhelp.test';
    private const PEER_EMAIL = 'qa.asset.peer@selfhelp.test';
    private const CREATOR_ROLE = 'qa_asset_creator_role';
    private const READER_ROLE = 'qa_asset_reader_role';
    private const PEER_ROLE = 'qa_asset_peer_role';
    private const ASSET_PERMISSIONS = ['admin.asset.read', 'admin.asset.create', 'admin.asset.update', 'admin.asset.delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $users = new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));

        $creator = $roles->createRole(self::CREATOR_ROLE);
        $reader = $roles->createRole(self::READER_ROLE);
        $peer = $roles->createRole(self::PEER_ROLE);
        foreach (self::ASSET_PERMISSIONS as $permission) {
            $roles->grantPermission($creator, $permission);
            $roles->grantPermission($peer, $permission);
        }
        // The reader role may look at assets but not create them.
        $roles->grantPermission($reader, 'admin.asset.read');

        $users->createUser(self::MULTI_EMAIL, 'QA Asset Multi', roles: [$creator, $reader]);
        $users->createUser(self::PEER_EMAIL, 'QA Asset Peer', roles: [$peer]);
    }

    protected function tearDown(): void
    {
        // Files are outside the database transaction the tests roll back.
        foreach ($this->uploadedUuids as $uuid) {
            @unlink($this->service(AssetStorageService::class)->absolutePath($uuid));
        }
        parent::tearDown();
    }

    /** @var list<string> */
    private array $uploadedUuids = [];

    public function testAnUploadIsManagedOnlyByTheUploadersRolesThatMayCreateAssets(): void
    {
        $multi = $this->token(self::MULTI_EMAIL);

        $asset = $this->upload($multi, 'qa-roles-default.png');
        self::assertSame([self::CREATOR_ROLE], $this->roleNames($asset), 'The reader role cannot create assets, so it does not manage the upload');

        // An explicit list is taken as it is, whatever the uploader's own roles.
        $explicit = $this->upload($multi, 'qa-roles-explicit.png', ['role_ids' => (string) $this->roleId(self::READER_ROLE)]);
        self::assertSame([self::READER_ROLE], $this->roleNames($explicit));
    }

    public function testAnEmptyManagingRoleListIsRefusedUnlessTheCallerIsAdmin(): void
    {
        $multi = $this->token(self::MULTI_EMAIL);
        $admin = $this->loginAsQaAdmin();
        $asset = $this->upload($multi, 'qa-roles-empty.png', ['name' => 'qa roles before']);
        $id = $this->asInt($asset['id']);
        $assetsBefore = $this->entityManager()->getRepository(Asset::class)->count([]);

        // Edit: refused, and nothing else in the same request changed either.
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, ['name' => 'qa roles after', 'role_ids' => ''], [], $this->bearer($multi));
        $this->assertRefusedForEmptyRoleList();
        $unchanged = $this->assetData($admin, $id);
        self::assertSame('qa roles before', $unchanged['name']);
        self::assertSame([self::CREATOR_ROLE], $this->roleNames($unchanged), 'The uploader still manages it');

        // Upload, one file and several: refused, nothing is stored.
        $this->client->request('POST', '/cms-api/v1/admin/assets', ['role_ids' => ''], ['file' => $this->tempFile('qa-roles-refused.png')], $this->bearer($multi));
        $this->assertRefusedForEmptyRoleList();
        $this->client->request('POST', '/cms-api/v1/admin/assets', ['role_ids' => ''], ['files' => [$this->tempFile('qa-roles-refused-a.png'), $this->tempFile('qa-roles-refused-b.png')]], $this->bearer($multi));
        $this->assertRefusedForEmptyRoleList();
        self::assertSame($assetsBefore, $this->entityManager()->getRepository(Asset::class)->count([]), 'Nothing was uploaded');

        // A list with a role still works for the same caller.
        $this->postEdit($multi, $id, ['role_ids' => (string) $this->roleId(self::CREATOR_ROLE)]);

        // An admin may leave an asset with no managing role on purpose: only
        // the admin role manages it, then.
        $adminOnly = $this->upload($admin, 'qa-roles-none.png', ['role_ids' => '']);
        self::assertSame([], $this->roleNames($adminOnly));
        $this->postEdit($admin, $id, ['role_ids' => '']);
        self::assertSame([], $this->roleNames($this->assetData($admin, $id)));
    }

    public function testGrantChangesAreLoggedLikeAnyOtherGrantChange(): void
    {
        $multi = $this->token(self::MULTI_EMAIL);
        $before = $this->grantLog();

        // The upload grants the creator role: one insert.
        $asset = $this->upload($multi, 'qa-roles-log.png');
        $id = $this->asInt($asset['id']);
        self::assertSame(['insert' => 1], $this->newLogEntries($before));

        // Sharing it with the peer role adds one grant; the creator's grant is
        // already complete and is not rewritten (no noise in the log).
        $before = $this->grantLog();
        $this->postEdit($multi, $id, ['role_ids' => $this->roleId(self::CREATOR_ROLE) . ',' . $this->roleId(self::PEER_ROLE)]);
        self::assertSame(['insert' => 1], $this->newLogEntries($before));

        // Handing it over drops the creator's grant: one delete.
        $before = $this->grantLog();
        $this->postEdit($this->token(self::PEER_EMAIL), $id, ['role_ids' => (string) $this->roleId(self::PEER_ROLE)]);
        self::assertSame(['delete' => 1], $this->newLogEntries($before));

        // Deleting the asset removes the last grant, logged as well.
        $before = $this->grantLog();
        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $this->bearer($this->token(self::PEER_EMAIL)));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(['delete' => 1], $this->newLogEntries($before));
    }

    public function testBulkDeleteIsAllOrNothingAndTheRefusalIsAudited(): void
    {
        $multi = $this->token(self::MULTI_EMAIL);
        $own = $this->upload($multi, 'qa-bulk-own.png');
        $foreign = $this->upload($this->token(self::PEER_EMAIL), 'qa-bulk-foreign.png');
        $ownId = $this->asInt($own['id']);
        $foreignId = $this->asInt($foreign['id']);

        // One id the caller may not delete: nothing is deleted.
        $this->assertEnvelope403($this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-delete', ['asset_ids' => [$ownId, $foreignId]], $multi));
        self::assertTrue($this->exists($ownId), 'The asset the caller may delete is still there');
        self::assertTrue($this->exists($foreignId));
        self::assertGreaterThanOrEqual(1, $this->deniedAudits($foreignId, self::MULTI_EMAIL), 'The refusal is audited, not rolled back');

        // One id that does not exist: nothing is deleted either.
        $this->assertEnvelope404($this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-delete', ['asset_ids' => [$ownId, 2147483600]], $multi));
        self::assertTrue($this->exists($ownId));

        // Only what the caller may delete: it goes, file included.
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-delete', ['asset_ids' => [$ownId]], $multi);
        self::assertSame(['deleted' => 1], $this->assertEnvelopeSuccess($envelope));
        $this->assertMatchesSchema($envelope, 'responses/admin/assets/assets_bulk_delete_envelope');
        self::assertFalse($this->exists($ownId));
        self::assertNull($this->service(AssetStorageService::class)->resolveForDelivery($this->asString($own['uuid'])), 'The file goes with the row');
        self::assertTrue($this->exists($foreignId), 'The other role\'s asset is untouched');
    }

    public function testBulkMoveAndBulkGroupsCheckEveryRightBeforeChangingAnything(): void
    {
        $multi = $this->token(self::MULTI_EMAIL);
        $own = $this->upload($multi, 'qa-bulk-move-own.png');
        $foreign = $this->upload($this->token(self::PEER_EMAIL), 'qa-bulk-move-foreign.png');
        $ownId = $this->asInt($own['id']);
        $foreignId = $this->asInt($foreign['id']);
        $admin = $this->loginAsQaAdmin();

        $folder = $this->asInt($this->asArray($this->asList(
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => 'qa-roles-folder'], $admin))
        )[0] ?? null)['id']);
        $groupId = (int) (new GroupFactory($this->entityManager()))->createGroup('qa_asset_roles_group')->getId();

        $this->assertEnvelope403($this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-folder', ['asset_ids' => [$ownId, $foreignId], 'folder_id' => $folder], $multi));
        self::assertNull($this->assetData($admin, $ownId)['folder_id'], 'The asset the caller may move stayed where it was');
        self::assertGreaterThanOrEqual(1, $this->deniedAudits($foreignId, self::MULTI_EMAIL), 'The refusal is audited');

        $this->assertEnvelope403($this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-groups', ['asset_ids' => [$ownId, $foreignId], 'group_ids' => [$groupId], 'mode' => 'add'], $multi));
        self::assertSame([], $this->asList($this->assetData($admin, $ownId)['groups']), 'No group was added to the asset the caller may change');

        // Within their rights both work, and answer in the documented shape.
        $moved = $this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-folder', ['asset_ids' => [$ownId], 'folder_id' => $folder], $multi);
        self::assertSame(['moved' => 1], $this->assertEnvelopeSuccess($moved));
        $this->assertMatchesSchema($moved, 'responses/admin/assets/assets_bulk_folder_envelope');
        self::assertSame($folder, $this->assetData($admin, $ownId)['folder_id']);

        $this->assertMatchesSchema(
            $this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-folder', ['asset_ids' => [$ownId], 'folder_id' => null], $multi),
            'responses/admin/assets/assets_bulk_folder_envelope'
        );
    }

    // -- helpers -------------------------------------------------------------

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function tempFile(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, 'qa-bytes');

        return new UploadedFile($path, $name, null, null, true);
    }

    private function assertRefusedForEmptyRoleList(): void
    {
        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('at least one managing role', $this->asString($this->decode($response)['error'] ?? ''));
    }

    private function token(string $email): string
    {
        return $this->loginAs($email);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function upload(string $token, string $name, array $fields = []): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, 'qa-bytes');
        $this->client->request('POST', '/cms-api/v1/admin/assets', $fields, ['file' => new UploadedFile($path, $name, null, null, true)], $this->bearer($token));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $asset = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()), 201);
        $this->uploadedUuids[] = $this->asString($asset['uuid']);

        return $asset;
    }

    /**
     * @param array<string, string> $fields
     */
    private function postEdit(string $token, int $id, array $fields): void
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, $fields, [], $this->bearer($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function assetData(string $adminToken, int $id): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/assets/' . $id, null, $adminToken));
    }

    private function exists(int $id): bool
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/assets/' . $id, null, $this->loginAsQaAdmin());

        return ($envelope['status'] ?? null) === 200;
    }

    /**
     * @param array<string, mixed> $asset
     * @return list<string>
     */
    private function roleNames(array $asset): array
    {
        $names = array_map(
            fn (mixed $role): string => $this->asString($this->asArray($role)['name']),
            $this->asList($asset['roles'])
        );
        sort($names);

        return $names;
    }

    private function roleId(string $name): int
    {
        $role = $this->entityManager()->getRepository(Role::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Role::class, $role);

        return (int) $role->getId();
    }

    /**
     * The ids of the log entries about grants made so far.
     *
     * @return list<int>
     */
    private function grantLog(): array
    {
        $em = $this->entityManager();
        $em->clear();

        return array_map(
            static fn (Transaction $entry): int => (int) $entry->getId(),
            $em->getRepository(Transaction::class)->findBy(['tableName' => 'role_data_access'])
        );
    }

    /**
     * The grant log entries added since `$before`, counted by kind
     * (`insert`, `update`, `delete`).
     *
     * @param list<int> $before
     * @return array<string, int>
     */
    private function newLogEntries(array $before): array
    {
        $em = $this->entityManager();
        $em->clear();

        $counts = [];
        foreach ($em->getRepository(Transaction::class)->findBy(['tableName' => 'role_data_access']) as $entry) {
            if (in_array((int) $entry->getId(), $before, true)) {
                continue;
            }
            $kind = (string) $entry->getTransactionType()?->getLookupCode();
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Audited refusals for this asset by this user.
     */
    private function deniedAudits(int $assetId, string $email): int
    {
        $em = $this->entityManager();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $denied = 0;
        foreach ($em->getRepository(DataAccessAudit::class)->findBy(['resourceId' => $assetId, 'user' => $user]) as $audit) {
            if ($audit->getPermissionResult()->getLookupCode() === LookupService::PERMISSION_RESULTS_DENIED) {
                $denied++;
            }
        }

        return $denied;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function assertMatchesSchema(array $envelope, string $schema): void
    {
        $errors = $this->service(JsonSchemaValidationService::class)->validate(
            $this->asObject(json_decode((string) json_encode($envelope))),
            $schema
        );
        self::assertSame([], $errors, "Response failed schema {$schema}:\n" . implode("\n", $errors));
    }
}
