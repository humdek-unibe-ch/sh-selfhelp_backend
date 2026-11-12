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
use App\Exception\ServiceException;
use App\Repository\AssetRepository;
use App\Service\CMS\Admin\AdminAssetService;
use App\Service\CMS\Admin\AssetAccessService;
use App\Service\CMS\Admin\AssetExportImportService;
use App\Service\CMS\Admin\AssetStorageService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The size limits of a bundle, with small limits so they can be reached:
 * the real ones (512 MB per file, 4 GB in total) are far too big to test with
 * real files, and are constructor arguments for exactly that reason.
 *
 * A file over the limit is reported for its own entry and the others still
 * import; a bundle over the total limit stops where it crosses it and keeps
 * what was imported before; an export that could not be imported again is
 * refused before any archive is started.
 */
final class AssetExportImportLimitsTest extends QaKernelTestCase
{
    private AssetStorageService $storage;
    private AssetRepository $assets;
    private AdminAssetService $adminAssets;

    /** @var list<string> */
    private array $uuids = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        $repository = $this->em->getRepository(Asset::class);
        self::assertInstanceOf(AssetRepository::class, $repository);
        $this->assets = $repository;
        $this->storage = $this->service(AssetStorageService::class);
        $this->adminAssets = $this->service(AdminAssetService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->uuids as $uuid) {
            @unlink($this->storage->absolutePath($uuid));
        }
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testAFileOverTheLimitIsReportedForItsEntryAndTheOthersStillImport(): void
    {
        $small = $this->newUuid();
        $big = $this->newUuid();
        $zip = $this->bundle([$small => '12345', $big => '12345678901']);

        $result = $this->bundleService(maxFileBytes: 10)->importFromZip($this->upload($zip));

        self::assertSame(1, $result['imported']);
        self::assertSame([['file' => $big, 'error' => 'File is larger than the 10 bytes a bundle file may have']], $result['errors']);
        self::assertNotNull($this->assets->findOneByUuid($small));
        self::assertNull($this->assets->findOneByUuid($big), 'The oversized asset was not created');
        self::assertFileDoesNotExist($this->storage->absolutePath($big), 'and its bytes never reached the storage');
    }

    public function testTheTotalLimitStopsTheImportWhereItIsCrossedAndKeepsWhatCameBefore(): void
    {
        $first = $this->newUuid();
        $second = $this->newUuid();
        $third = $this->newUuid();
        $fourth = $this->newUuid();
        $zip = $this->bundle([$first => '12345', $second => '12345', $third => '12345', $fourth => '1']);

        $result = $this->bundleService(maxTotalBytes: 12)->importFromZip($this->upload($zip));

        self::assertSame(2, $result['imported'], '10 bytes fit, the third file would make it 15');
        self::assertSame(
            [['file' => $third, 'error' => 'Bundle exceeds the 12 bytes it may hold in total; import stopped here']],
            $result['errors'],
            'It stops there: the small fourth file is not tried'
        );
        self::assertNotNull($this->assets->findOneByUuid($first));
        self::assertNotNull($this->assets->findOneByUuid($second));
        self::assertNull($this->assets->findOneByUuid($third));
        self::assertNull($this->assets->findOneByUuid($fourth));
    }

    public function testAnExportOfMoreAssetsThanTheLimitIsRefused(): void
    {
        $this->createAsset('12345', 'qa-limit-count-1');
        $this->createAsset('12345', 'qa-limit-count-2');
        $archivesBefore = $this->exportArchives();

        try {
            $this->bundleService(maxAssets: 1)->exportToZipFile([]);
            self::fail('An export over the asset limit must be refused');
        } catch (ServiceException $e) {
            self::assertSame(400, $e->getCode());
            self::assertStringContainsString('at most 1 assets', $e->getMessage());
        }

        self::assertSame($archivesBefore, $this->exportArchives(), 'No archive was started');
    }

    public function testAnExportThatCouldNotBeImportedAgainIsRefusedBeforeAnArchiveIsStarted(): void
    {
        $first = $this->createAsset('12345', 'qa-limit-bytes-1');
        $second = $this->createAsset('12345', 'qa-limit-bytes-2');
        $archivesBefore = $this->exportArchives();

        try {
            $this->bundleService(maxTotalBytes: 8)->exportToZipFile([$first, $second]);
            self::fail('An export over the size limit must be refused');
        } catch (ServiceException $e) {
            self::assertSame(400, $e->getCode());
            self::assertSame('A bundle holds at most 8 bytes of files: export fewer assets at a time', $e->getMessage());
        }

        self::assertSame($archivesBefore, $this->exportArchives(), 'No archive was started');

        // Within the limit the same two assets export, and the archive can be imported again.
        $path = $this->bundleService(maxTotalBytes: 10)->exportToZipFile([$first, $second]);
        $this->tempFiles[] = $path;
        self::assertFileExists($path);
    }

    // -- helpers -------------------------------------------------------------

    private function bundleService(
        int $maxAssets = AssetExportImportService::MAX_ASSETS,
        int $maxFileBytes = AssetExportImportService::MAX_FILE_BYTES,
        int $maxTotalBytes = AssetExportImportService::MAX_TOTAL_BYTES,
    ): AssetExportImportService {
        return new AssetExportImportService(
            $this->assets,
            $this->adminAssets,
            $this->service(AssetAccessService::class),
            $this->storage,
            $maxAssets,
            $maxFileBytes,
            $maxTotalBytes,
        );
    }

    private function newUuid(): string
    {
        $uuid = $this->storage->generateUuid();
        $this->uuids[] = $uuid;

        return $uuid;
    }

    private function createAsset(string $bytes, string $name): int
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        $created = $this->adminAssets->createAsset(new UploadedFile($path, $name . '.png', null, null, true), ['name' => $name]);
        $this->uuids[] = $this->asString($created['uuid']);

        return $this->asInt($created['id']);
    }

    /**
     * A v2.0 bundle with one entry per uuid (in the order given) holding these bytes.
     *
     * @param array<string, string> $files uuid => bytes
     */
    private function bundle(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_bundle');
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $entries = [];
        foreach ($files as $uuid => $bytes) {
            $zip->addFromString('files/' . $uuid, $bytes);
            $entries[] = [
                'uuid' => $uuid,
                'name' => 'qa-limit-' . substr($uuid, 0, 8),
                'alt' => null,
                'mime_type' => 'image/png',
                'asset_type' => 'Asset',
                'groups' => [],
                'bundle_path' => 'files/' . $uuid,
            ];
        }
        $zip->addFromString('manifest.json', (string) json_encode([
            'bundle_type' => 'selfhelp/asset-bundle',
            'bundle_version' => '2.0',
            'assets' => $entries,
        ]));
        self::assertTrue($zip->close());

        return $path;
    }

    /** A copy: an uploaded file in test mode is moved by the import. */
    private function upload(string $zipPath): UploadedFile
    {
        $copy = (string) tempnam(sys_get_temp_dir(), 'qa_bundle_copy');
        copy($zipPath, $copy);
        $this->tempFiles[] = $copy;

        return new UploadedFile($copy, 'qa-bundle.zip', 'application/zip', null, true);
    }

    /**
     * @return list<string>
     */
    private function exportArchives(): array
    {
        $archives = glob(sys_get_temp_dir() . '/asset-export-*.zip') ?: [];
        sort($archives);

        return $archives;
    }
}
