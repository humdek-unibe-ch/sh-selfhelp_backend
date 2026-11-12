<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Asset;
use App\Service\CMS\Admin\AssetStorageService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\InteractsWithAssetApi;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which files an upload accepts and how they are delivered.
 *
 * The extension is checked against an allowlist and stored on the row (the
 * stored file has none); the bytes must fit the extension, so a script named
 * `x.png` is refused. The delivery names the file `<name>-<uuid>.<ext>` and
 * shows images, audio, video, PDF and plain text inline, everything else as a
 * download.
 */
final class AssetFileTypeTest extends QaWebTestCase
{
    use InteractsWithAssetApi;

    protected function tearDown(): void
    {
        $this->removeUploadedAssetFiles();
        parent::tearDown();
    }

    /** A1 */
    public function testACsvIsAcceptedAsTextCsvAndDeliveredAsADownloadUnderItsDownloadName(): void
    {
        $admin = $this->loginAsQaAdmin();

        $asset = $this->uploadAsset($admin, AssetFixtures::upload('qa_data.csv'));
        $uuid = $this->asString($asset['uuid']);

        self::assertSame('csv', $asset['extension']);
        self::assertSame('text/csv', $asset['mime_type']);
        self::assertSame('qa_data-' . $uuid . '.csv', $asset['file_name']);

        $this->client->request('GET', '/cms-api/v1/assets/' . $uuid, [], [], $this->assetBearer($admin));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition, 'A CSV is downloaded, not shown');
        self::assertStringContainsString('qa_data-' . $uuid . '.csv', $disposition);
    }

    /** A2 */
    public function testOfficeFilesKeepTheirMimeTypeAndAnImageIsShownInlineUnderItsDownloadName(): void
    {
        $admin = $this->loginAsQaAdmin();

        $docx = $this->uploadAsset($admin, AssetFixtures::upload('qa_report.docx'));
        self::assertSame('docx', $docx['extension']);
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx['mime_type']);

        $xlsx = $this->uploadAsset($admin, AssetFixtures::upload('qa_sheet.xlsx', AssetFixtures::zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
        ])));
        self::assertSame('xlsx', $xlsx['extension']);
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx['mime_type']);

        $png = $this->uploadAsset($admin, AssetFixtures::upload('qa_picture.png'));
        $uuid = $this->asString($png['uuid']);
        $this->client->request('GET', '/cms-api/v1/assets/' . $uuid, [], [], $this->assetBearer($admin));
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('inline', $disposition, 'An image is shown in the browser');
        self::assertStringContainsString('qa_picture-' . $uuid . '.png', $disposition);

        $this->client->request('GET', '/cms-api/v1/assets/' . $this->asString($docx['uuid']), [], [], $this->assetBearer($admin));
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /** A3 */
    public function testAFileWhoseContentDoesNotFitItsExtensionOrWhoseExtensionIsNotAllowedIsRefusedAndLeavesNothing(): void
    {
        $admin = $this->loginAsQaAdmin();
        $storage = $this->service(AssetStorageService::class);
        $assets = $this->service(EntityManagerInterface::class)->getRepository(Asset::class);
        $rowsBefore = $assets->count([]);
        $filesBefore = $this->storedFiles($storage);

        $disguised = [
            'php as png' => AssetFixtures::upload('qa_x.png', "<?php echo 'x'; ?>"),
            'html as png' => AssetFixtures::upload('qa_y.png', '<!doctype html><html><script>alert(1)</script></html>'),
            'text as pdf' => AssetFixtures::upload('qa_z.pdf', 'this is not a pdf'),
            'png as docx' => AssetFixtures::upload('qa_w.docx', AssetFixtures::png()),
            'html as svg' => AssetFixtures::upload('qa_v.svg', '<!doctype html><html><script>alert(1)</script></html>'),
        ];
        foreach ($disguised as $case => $file) {
            $envelope = $this->uploadRaw($admin, $file);
            $this->assertEnvelope400($envelope);
            self::assertSame('The file content does not match its extension', $envelope['error'], $case);
        }

        foreach (['qa_run.exe', 'qa_page.php', 'qa_page.html', 'qa_noextension', 'qa_archive.exe.txt.bat'] as $name) {
            $this->assertEnvelope400($this->uploadRaw($admin, AssetFixtures::upload($name, 'plain text')));
        }

        self::assertSame($rowsBefore, $assets->count([]), 'No row was created');
        self::assertSame($filesBefore, $this->storedFiles($storage), 'No file was stored');
    }

    /**
     * @return list<string>
     */
    private function storedFiles(AssetStorageService $storage): array
    {
        $root = $storage->storageRoot();
        $files = is_dir($root) ? array_values(array_diff(scandir($root) ?: [], ['.', '..'])) : [];
        sort($files);

        return $files;
    }
}
