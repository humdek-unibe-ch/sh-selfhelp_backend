<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AdminAssetService;
use App\Service\CMS\Admin\AssetAccessService;
use App\Service\CMS\Admin\AssetStorageService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Tests\Support\FailingCommitEntityManager;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The commit is the last step of an upload or an edit, and the only one that
 * comes after the file is already in place. When the database refuses it, the
 * file must be put back exactly as it was: the old bytes for an edit, nothing
 * at all for an upload, and no staged or backup file either way.
 */
final class AdminAssetCommitFailureTest extends QaKernelTestCase
{
    private FailingCommitEntityManager $failing;
    private AdminAssetService $assets;
    private AssetStorageService $storage;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        $repository = $this->em->getRepository(Asset::class);
        self::assertInstanceOf(AssetRepository::class, $repository);

        $this->storage = $this->service(AssetStorageService::class);
        $this->failing = new FailingCommitEntityManager($this->em);
        $this->assets = new AdminAssetService(
            $repository,
            $this->failing,
            $this->service(TransactionService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
            $this->service(AssetAccessService::class),
            $this->storage,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $uuid) {
            @unlink($this->storage->absolutePath($uuid));
        }
        parent::tearDown();
    }

    public function testAnUploadWhoseCommitFailsLeavesNoFileAndNoRow(): void
    {
        $before = $this->filesInStorage();
        $this->failing->failNextCommit();

        try {
            $this->assets->createAsset($this->upload('bytes', 'qa-commit-create.txt'), ['name' => 'qa commit create']);
            self::fail('The failed commit must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame(FailingCommitEntityManager::MESSAGE, $e->getMessage());
        }

        self::assertSame($before, $this->filesInStorage(), 'The file went again and nothing temporary is left');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Asset::class)->findOneBy(['name' => 'qa commit create']), 'No row was kept either');
    }

    public function testAnEditWhoseCommitFailsPutsTheOldBytesAndTheOldRowBack(): void
    {
        $created = $this->assets->createAsset($this->upload('old bytes', 'qa-commit-edit.txt'), ['name' => 'qa commit edit', 'alt' => 'old alt']);
        $uuid = $this->asString($created['uuid']);
        $this->createdFiles[] = $uuid;
        $id = $this->asInt($created['id']);
        $before = $this->filesInStorage();

        $this->failing->failNextCommit();
        try {
            $this->assets->updateAsset($id, ['alt' => 'new alt'], $this->upload('new bytes that are longer', 'qa-commit-edit.txt'));
            self::fail('The failed commit must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame(FailingCommitEntityManager::MESSAGE, $e->getMessage());
        }

        self::assertSame('old bytes', file_get_contents($this->storage->absolutePath($uuid)), 'The old bytes are back');
        self::assertSame($before, $this->filesInStorage(), 'No staged or backup file is left');

        $this->em->clear();
        $row = $this->em->getRepository(Asset::class)->find($id);
        self::assertInstanceOf(Asset::class, $row);
        self::assertSame('old alt', $row->getAlt(), 'The row is as it was');
        self::assertSame(strlen('old bytes'), $row->getSize());
    }

    public function testADeleteWhoseCommitFailsKeepsTheFile(): void
    {
        $created = $this->assets->createAsset($this->upload('kept bytes', 'qa-commit-delete.txt'), ['name' => 'qa commit delete']);
        $uuid = $this->asString($created['uuid']);
        $this->createdFiles[] = $uuid;
        $id = $this->asInt($created['id']);
        // The delete is a request of its own, with a fresh entity manager.
        $this->em->clear();

        $this->failing->failNextCommit();
        try {
            $this->assets->deleteAsset($id);
            self::fail('The failed commit must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame(FailingCommitEntityManager::MESSAGE, $e->getMessage());
        }

        self::assertSame('kept bytes', file_get_contents($this->storage->absolutePath($uuid)), 'The row is still there, so is its file');
        $this->em->clear();
        self::assertInstanceOf(Asset::class, $this->em->getRepository(Asset::class)->find($id));
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Everything in the asset storage, hidden files included, so a staged or
     * backup file that stayed behind shows up.
     *
     * @return list<string>
     */
    private function filesInStorage(): array
    {
        $files = array_values(array_diff(scandir($this->storage->storageRoot()) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }
}
